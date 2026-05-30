<?php

namespace App\Services;

use App\Models\OrderDesignFile;
use App\Models\OrderStage;
use App\Models\QaPackerTaskCompletion;
use App\Models\StageSampleUpload;
use App\Models\StageUpload;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

/**
 * StageArtifactService — unifies every "reviewable artifact" source into ONE
 * normalized, per-stage map for the CSR Review Hub.
 *
 * WHY THIS EXISTS
 * ---------------
 * Artifacts already live in several specialized tables, each written by a
 * different portal:
 *   - order_design_files          (Graphic Artist: AI/PSD/PNG, versioned)   — keyed by order_id + kind
 *   - stage_sample_uploads        (sample cut/print/sew: front/back photos)  — keyed by order_stage_id
 *   - qa_packer_task_completions  (QA/Packer: final photos json)             — keyed by order_stage_id
 *   - stage_uploads               (generic Phase-3 proof-of-work)            — keyed by order_stage_id
 *
 * The hub shouldn't care which subsystem produced a file — it just needs to
 * show "what was produced at this stage" so a reviewer can approve/reject with
 * eyes on the actual output. This service reads all sources and returns a
 * single Collection keyed by order_stage_id, each value an array of artifacts:
 *
 *   [ 'id' => string, 'url' => string, 'original_name' => ?string,
 *     'is_image' => bool, 'source' => string, 'label' => ?string,
 *     'created_at' => ?string ]
 *
 * It is READ-ONLY and additive: it never writes to or alters any source table.
 * Adding a new artifact source later = add one private collector here.
 */
class StageArtifactService
{
    /**
     * Build the per-stage artifact map for an order.
     *
     * @return Collection<int, array<int, array>> keyed by order_stage_id
     */
    public function forOrder(int $orderId): Collection
    {
        // Stage lookup for this order (id + slug), so we can route order-scoped
        // sources (design files) to the right stage by slug.
        $stages = OrderStage::where('order_id', $orderId)
            ->get(['id', 'stage'])
            ->keyBy('id');

        // stageIdForSlug: first stage whose slug matches (orders have one of each).
        $stageIdForSlug = $stages->mapWithKeys(fn ($s) => [$s->stage => $s->id]);

        /** @var array<int, array<int, array>> $byStage */
        $byStage = [];

        $push = function (?int $stageId, array $artifact) use (&$byStage, $stages) {
            if (! $stageId || ! $stages->has($stageId)) {
                return;
            }
            $byStage[$stageId][] = $artifact;
        };

        $this->collectGenericUploads($orderId, $push);
        $this->collectSampleUploads($orderId, $push);
        $this->collectDesignFiles($orderId, $stageIdForSlug, $push);
        $this->collectQaCompletions($orderId, $push);

        // Sort each stage's artifacts newest-first and wrap as a Collection.
        return collect($byStage)->map(function ($artifacts) {
            usort($artifacts, fn ($a, $b) => strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? '')));
            return $artifacts;
        });
    }

    // ──────────────────────────────────────────────────────────────────
    // Source collectors — each normalizes one table into the common shape.
    // ──────────────────────────────────────────────────────────────────

    /** Generic Phase-3 proof-of-work uploads (one row per file). */
    private function collectGenericUploads(int $orderId, callable $push): void
    {
        StageUpload::where('order_id', $orderId)
            ->get()
            ->each(function (StageUpload $u) use ($push) {
                $push($u->order_stage_id, [
                    'id'            => 'upload-' . $u->id,
                    'url'           => $u->file_path ? Storage::disk('public')->url($u->file_path) : null,
                    'original_name' => $u->original_name,
                    'is_image'      => is_string($u->mime_type) && str_starts_with($u->mime_type, 'image/'),
                    'source'        => 'proof',
                    'label'         => $u->notes ?: ($u->category ?: 'Proof of work'),
                    'created_at'    => $u->created_at?->toDateTimeString(),
                ]);
            });
    }

    /** Sample uploads: front/back photo columns → up to two artifacts. */
    private function collectSampleUploads(int $orderId, callable $push): void
    {
        StageSampleUpload::where('order_id', $orderId)
            ->get()
            ->each(function (StageSampleUpload $s) use ($push) {
                foreach ([
                    ['path' => $s->photo_front_path, 'tag' => 'Front'],
                    ['path' => $s->photo_back_path,  'tag' => 'Back'],
                ] as $i => $photo) {
                    if (! $photo['path']) {
                        continue;
                    }
                    $push($s->order_stage_id, [
                        'id'            => 'sample-' . $s->id . '-' . $i,
                        'url'           => Storage::disk('public')->url($photo['path']),
                        'original_name' => $photo['tag'] . ' sample',
                        'is_image'      => true,
                        'source'        => 'sample',
                        'label'         => trim($photo['tag'] . ' · ' . ($s->remarks ?? '')),
                        'created_at'    => $s->created_at?->toDateTimeString(),
                    ]);
                }
            });
    }

    /**
     * Graphic Artist design files. These are keyed by order_id (+ kind), not by
     * stage, so route them to the graphic_artwork stage. Only the latest version
     * of each kind is shown to keep the hub uncluttered.
     */
    private function collectDesignFiles(int $orderId, Collection $stageIdForSlug, callable $push): void
    {
        $gaStageId = $stageIdForSlug->get('graphic_artwork');
        if (! $gaStageId) {
            return;
        }

        OrderDesignFile::where('order_id', $orderId)
            ->where('is_latest', true)
            ->get()
            ->each(function (OrderDesignFile $f) use ($push, $gaStageId) {
                $push($gaStageId, [
                    'id'            => 'design-' . $f->id,
                    'url'           => $f->file_path ? Storage::disk('public')->url($f->file_path) : null,
                    'original_name' => $f->original_name,
                    'is_image'      => is_string($f->mime_type) && str_starts_with($f->mime_type, 'image/'),
                    'source'        => 'design',
                    'label'         => trim(($f->kind ?? 'design') . ' · v' . $f->version),
                    'created_at'    => $f->created_at?->toDateTimeString(),
                ]);
            });
    }

    /**
     * QA/Packer final photos, stored as a { kind: path } JSON map on the latest
     * completion row for the stage.
     */
    private function collectQaCompletions(int $orderId, callable $push): void
    {
        QaPackerTaskCompletion::where('order_id', $orderId)
            ->orderByDesc('id')
            ->get()
            ->groupBy('order_stage_id')
            ->each(function ($rows, $stageId) use ($push) {
                /** @var QaPackerTaskCompletion $latest */
                $latest = $rows->first(); // newest (ordered desc)
                $photos = $latest->final_photos_json;
                if (! is_array($photos)) {
                    return;
                }
                $i = 0;
                foreach ($photos as $kind => $path) {
                    if (! is_string($path) || $path === '') {
                        continue;
                    }
                    $push((int) $stageId, [
                        'id'            => 'qa-' . $latest->id . '-' . $i++,
                        'url'           => Storage::disk('public')->url($path),
                        'original_name' => is_string($kind) ? $kind : 'photo',
                        'is_image'      => true,
                        'source'        => 'qa',
                        'label'         => is_string($kind) ? str_replace('_', ' ', $kind) : 'Final photo',
                        'created_at'    => $latest->submitted_at?->toDateTimeString()
                            ?? $latest->created_at?->toDateTimeString(),
                    ]);
                }
            });
    }
}
