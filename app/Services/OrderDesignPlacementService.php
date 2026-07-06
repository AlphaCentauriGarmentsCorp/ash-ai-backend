<?php

namespace App\Services;

use App\Models\OrderDesign;
use App\Models\OrderDesignPlacement;
use App\Models\OrderStage;
use App\Models\Pantone;
use App\Models\StageAuditLog;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * GA Portal CP1 — GA-writable print placements.
 *
 * Brings the legacy portal's "Print Location" editor into the Graphic
 * Artist portal: the artist adds a location (Body Front, ...), uploads
 * per-location artwork, sets the Color# slot count, and fills Pantone
 * codes per slot. Rows live in order_design_placements — the SAME table
 * the admin Graphic Design page writes to, so both editors stay in sync.
 *
 * Rules:
 *   - one placement per (order_design_id, type), case-insensitive
 *   - stage must be an ACTIVE graphic_artwork stage of the same order
 *   - pantones stored as JSON: int = pantones-table ID, array = inline
 *     descriptor {pantone_code, name, hexcolor} (free-typed codes)
 *   - color_count (slot count) is independent of count(pantones);
 *     unfilled slots are allowed — completion is warn-only (Tapos na
 *     is never hard-blocked by this module)
 *
 * Audit actions (stage_audit_logs):
 *   - placement.upserted
 *   - placement.deleted
 */
class OrderDesignPlacementService
{
    public const AUDIT_UPSERTED = 'placement.upserted';
    public const AUDIT_DELETED  = 'placement.deleted';

    /**
     * Create or update a placement.
     *
     * @param array{
     *   order_id:int,
     *   order_stage_id:int,
     *   id?:int|null,
     *   type:string,
     *   color_count?:int|null,
     *   pantones?:array|null,
     *   artwork_path?:string|null,
     * } $data  artwork_path is the already-stored disk path (the
     *          controller handles the actual file move).
     */
    public function upsert(array $data, ?User $actor = null): OrderDesignPlacement
    {
        $actor = $actor ?? Auth::user();
        $this->ensureCan($actor);

        return DB::transaction(function () use ($data, $actor) {
            $stage = $this->loadActiveGraphicStage(
                (int) $data['order_stage_id'],
                (int) $data['order_id'],
            );

            // Find-or-create the order's design row (same anchor row the
            // admin Graphic Design page uses).
            $design = OrderDesign::firstOrCreate(
                ['order_id' => (int) $data['order_id']],
                ['artist_id' => $actor->id],
            );

            $type = trim((string) $data['type']);
            if ($type === '') {
                throw ValidationException::withMessages([
                    'type' => 'Placement type is required.',
                ]);
            }

            $existing = null;
            if (! empty($data['id'])) {
                $existing = OrderDesignPlacement::lockForUpdate()->find((int) $data['id']);
                if (! $existing) {
                    throw ValidationException::withMessages([
                        'id' => 'Placement not found.',
                    ]);
                }
                if ((int) $existing->order_design_id !== (int) $design->id) {
                    throw ValidationException::withMessages([
                        'id' => 'Placement does not belong to that order.',
                    ]);
                }
            }

            // Uniqueness per (design, type), case-insensitive. On update,
            // renaming into an existing sibling's type is also blocked.
            $duplicate = OrderDesignPlacement::where('order_design_id', $design->id)
                ->whereRaw('LOWER(type) = ?', [mb_strtolower($type)])
                ->when($existing, fn ($q) => $q->where('id', '!=', $existing->id))
                ->exists();
            if ($duplicate) {
                throw ValidationException::withMessages([
                    'type' => "May placement na na '{$type}' sa order na ito.",
                ]);
            }

            $patch = [
                'type'        => $type,
                'color_count' => array_key_exists('color_count', $data) && $data['color_count'] !== null
                    ? (int) $data['color_count']
                    : ($existing?->color_count),
                'pantones'    => $this->normalizePantones($data['pantones'] ?? null, $existing),
            ];

            if (! empty($data['artwork_path'])) {
                // Replacing artwork hard-deletes the previous physical file.
                if ($existing && $existing->mockup_image) {
                    $this->deletePhysicalFile($existing->mockup_image);
                }
                $patch['mockup_image'] = $data['artwork_path'];
            }

            if ($existing) {
                $existing->update($patch);
                $placement = $existing;
            } else {
                $placement = $design->placements()->create($patch);
            }

            StageAuditLog::create([
                'order_id'       => (int) $data['order_id'],
                'order_stage_id' => $stage->id,
                'user_id'        => $actor->id,
                'action'         => self::AUDIT_UPSERTED,
                'from_status'    => null,
                'to_status'      => null,
                'notes'          => $type
                    . ' — ' . count($placement->pantones ?? []) . ' pantone(s)'
                    . ($placement->color_count !== null ? " / {$placement->color_count} slot(s)" : ''),
                'created_at'     => now(),
            ]);

            return $placement->fresh();
        });
    }

    /**
     * Delete a placement: removes the row + its physical artwork file.
     */
    public function delete(int $placementId, int $orderStageId, ?User $actor = null): void
    {
        $actor = $actor ?? Auth::user();
        $this->ensureCan($actor);

        DB::transaction(function () use ($placementId, $orderStageId, $actor) {
            $placement = OrderDesignPlacement::lockForUpdate()->find($placementId);
            if (! $placement) {
                throw ValidationException::withMessages([
                    'id' => 'Placement not found.',
                ]);
            }

            $design = OrderDesign::find($placement->order_design_id);
            if (! $design) {
                throw ValidationException::withMessages([
                    'id' => 'Placement has no parent design.',
                ]);
            }

            $stage = $this->loadActiveGraphicStage($orderStageId, (int) $design->order_id);

            $type = $placement->type;
            $path = $placement->mockup_image;

            $placement->delete();
            $this->deletePhysicalFile($path);

            StageAuditLog::create([
                'order_id'       => (int) $design->order_id,
                'order_stage_id' => $stage->id,
                'user_id'        => $actor->id,
                'action'         => self::AUDIT_DELETED,
                'from_status'    => null,
                'to_status'      => null,
                'notes'          => $type,
                'created_at'     => now(),
            ]);
        });
    }

    /**
     * Present a single placement with hydrated Pantone entries — same
     * row shape as GraphicArtistPortalService::placements() so the
     * frontend can splice the response straight into its list.
     */
    public function present(OrderDesignPlacement $placement): array
    {
        $raw = is_array($placement->pantones) ? $placement->pantones : [];

        $ids = [];
        foreach ($raw as $entry) {
            if (is_int($entry) || (is_string($entry) && ctype_digit($entry))) {
                $ids[] = (int) $entry;
            } elseif (is_array($entry) && isset($entry['id'])) {
                $ids[] = (int) $entry['id'];
            }
        }
        $pantonesById = empty($ids)
            ? collect()
            : Pantone::whereIn('id', array_values(array_unique($ids)))->get()->keyBy('id');

        $hydrated = [];
        foreach ($raw as $entry) {
            if (is_int($entry) || (is_string($entry) && ctype_digit($entry))) {
                $rec = $pantonesById->get((int) $entry);
                if ($rec) {
                    $hydrated[] = [
                        'source'       => 'official',
                        'id'           => $rec->id,
                        'name'         => $rec->name,
                        'hexcolor'     => $rec->hexcolor,
                        'pantone_code' => $rec->pantone_code,
                    ];
                }
            } elseif (is_array($entry)) {
                $entrySource = isset($entry['source']) && $entry['source'] !== ''
                    ? (string) $entry['source']
                    : (isset($entry['id']) ? 'official' : 'inline');
                $hydrated[] = [
                    'source'       => $entrySource,
                    'id'           => $entry['id']           ?? null,
                    'name'         => $entry['name']         ?? null,
                    'hexcolor'     => $entry['hexcolor']     ?? null,
                    'pantone_code' => $entry['pantone_code'] ?? null,
                ];
            }
        }

        return [
            'id'           => $placement->id,
            'type'         => $placement->type,
            'mockup_image' => $placement->mockup_image,
            'mockup_url'   => $placement->mockup_image
                ? $this->publicUrl($placement->mockup_image)
                : null,
            'color_count'  => $placement->color_count !== null
                ? (int) $placement->color_count
                : null,
            'pantones'     => $hydrated,
        ];
    }

    // ── Helpers ────────────────────────────────────────────────────

    /**
     * Normalise the submitted pantones payload into the storage shape.
     *
     * Accepted entry forms:
     *   - int / digit-string          → pantones-table ID (stored as int)
     *   - "PMS 186 C" (plain string)  → inline {pantone_code}
     *   - {id: 3}                     → ID reference (stored as int)
     *   - {pantone_code, name?, hexcolor?} → inline descriptor
     *
     * Blank entries are dropped (an empty slot is represented by
     * color_count exceeding the stored entry count, not by a
     * placeholder row). Passing null keeps the existing value on
     * update; passing [] explicitly clears.
     *
     * @return array<int, int|array<string,string|null>>|null
     */
    protected function normalizePantones(?array $raw, ?OrderDesignPlacement $existing): ?array
    {
        if ($raw === null) {
            return $existing?->pantones;
        }

        $out = [];
        foreach ($raw as $entry) {
            if (is_int($entry) || (is_string($entry) && ctype_digit($entry))) {
                $out[] = (int) $entry;
                continue;
            }
            if (is_string($entry)) {
                $code = trim($entry);
                if ($code !== '') {
                    $out[] = ['pantone_code' => $code, 'name' => null, 'hexcolor' => null];
                }
                continue;
            }
            if (is_array($entry)) {
                $source = isset($entry['source'])
                    ? strtolower(trim((string) $entry['source']))
                    : null;

                // CP1 — custom color: snapshot + reference. Freeze the
                // name/hex/code and keep a reference to custom_colors. An
                // entry without an id is a freshly-picked custom color:
                // find-or-create it (de-duped on hex) and backfill the id.
                if ($source === 'custom') {
                    $cid = (isset($entry['id']) && (is_int($entry['id']) || ctype_digit((string) $entry['id'])))
                        ? (int) $entry['id']
                        : null;

                    $name = trim((string) ($entry['name'] ?? ''));
                    $hex  = trim((string) ($entry['hexcolor'] ?? ''));
                    $code = trim((string) ($entry['pantone_code'] ?? ''));

                    if ($cid === null) {
                        if ($hex === '') {
                            continue; // nothing to anchor a custom color on
                        }
                        $custom = app(\App\Services\CustomColorService::class)->findOrCreate([
                            'name'         => $name !== '' ? $name : null,
                            'hexcolor'     => $hex,
                            'pantone_code' => $code !== '' ? $code : null,
                        ], null);
                    } else {
                        $custom = \App\Models\CustomColor::find($cid);
                        if (! $custom) {
                            continue; // dangling reference — drop it
                        }
                    }

                    $out[] = [
                        'source'       => 'custom',
                        'id'           => $custom->id,
                        'name'         => $custom->name,
                        'hexcolor'     => $custom->hexcolor,
                        'pantone_code' => $custom->pantone_code,
                    ];
                    continue;
                }

                // Official reference (with or without an explicit source) —
                // stored as a bare int so Screen Maker / Printer keep reading
                // the same shape they always have (color_index unchanged).
                if (isset($entry['id']) && (is_int($entry['id']) || ctype_digit((string) $entry['id']))) {
                    $out[] = (int) $entry['id'];
                    continue;
                }

                // Legacy inline descriptor (no id, no source).
                $code = trim((string) ($entry['pantone_code'] ?? ''));
                $name = trim((string) ($entry['name'] ?? ''));
                $hex  = trim((string) ($entry['hexcolor'] ?? ''));
                if ($code !== '' || $name !== '' || $hex !== '') {
                    $out[] = [
                        'pantone_code' => $code !== '' ? $code : null,
                        'name'         => $name !== '' ? $name : null,
                        'hexcolor'     => $hex  !== '' ? $hex  : null,
                    ];
                }
            }
        }

        return array_slice($out, 0, 20);
    }

    /**
     * Same active-stage guard as OrderDesignFileService — stage must
     * exist, belong to the order, be graphic_artwork, and be active.
     */
    protected function loadActiveGraphicStage(int $stageId, int $expectedOrderId): OrderStage
    {
        $stage = OrderStage::find($stageId);
        if (! $stage) {
            throw ValidationException::withMessages([
                'order_stage_id' => 'Stage not found.',
            ]);
        }

        if ((int) $stage->order_id !== (int) $expectedOrderId) {
            throw ValidationException::withMessages([
                'order_stage_id' => 'Stage does not belong to that order.',
            ]);
        }

        if ($stage->stage !== 'graphic_artwork') {
            throw ValidationException::withMessages([
                'order_stage_id' => "Stage '{$stage->stage}' is not a graphic artist portal stage.",
            ]);
        }

        $activeStatuses = [
            OrderStage::STATUS_IN_PROGRESS,
            OrderStage::STATUS_FOR_APPROVAL,
            OrderStage::STATUS_DELAYED,
        ];

        if (! in_array($stage->status, $activeStatuses, true)) {
            throw ValidationException::withMessages([
                'order_stage_id' => "Cannot modify placements against a stage in status '{$stage->status}'.",
            ]);
        }

        return $stage;
    }

    protected function ensureCan(?User $actor): void
    {
        if (! $actor) {
            throw ValidationException::withMessages([
                'actor' => 'No authenticated user.',
            ]);
        }

        if (! $actor->can('action.upload-photos')) {
            throw ValidationException::withMessages([
                'permission' => 'You do not have permission to edit placements.',
            ]);
        }
    }

    /**
     * Deletes the file from the public disk. Path may be either
     * disk-relative or the /storage/-prefixed public URL form (legacy
     * GraphicEditingService writes the latter). Silent on missing files.
     */
    protected function deletePhysicalFile(?string $path): void
    {
        if (! $path) {
            return;
        }
        $relative = ltrim($path, '/');
        if (str_starts_with($relative, 'storage/')) {
            $relative = substr($relative, strlen('storage/'));
        }
        Storage::disk('public')->delete($relative);
    }

    protected function publicUrl(string $path): string
    {
        $relative = ltrim($path, '/');
        if (str_starts_with($relative, 'storage/')) {
            return '/' . $relative;
        }
        return Storage::disk('public')->url($relative);
    }
}
