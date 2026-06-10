<?php

namespace App\Services;

use App\Models\FabricSwatch;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * FabricSwatchService — CRUD + stock-status decoration.
 *
 * Phase 6-B. The catalog endpoint can join `materials.stock_on_hand`
 * onto each swatch (via `material_id`) to surface live availability:
 *
 *   - in_stock   : stock_on_hand > LOW_STOCK_THRESHOLD
 *   - low_stock  : 0 < stock_on_hand <= LOW_STOCK_THRESHOLD
 *   - out_of_stock : stock_on_hand <= 0
 *   - unknown    : material_id is null OR linked material missing
 *
 * The threshold is per-material in a fuller build; for now we use a
 * single hard-coded value at the swatch presenter level.
 */
class FabricSwatchService
{
    protected const LOW_STOCK_THRESHOLD = 5;

    /**
     * List swatches with optional filters.
     *
     * @param array{
     *     fabric_type?: string,
     *     gsm?: int,
     *     collection?: string,
     *     supplier_id?: int,
     *     color_family?: string,
     * } $filters
     */
    public function list(array $filters = []): Collection
    {
        $q = FabricSwatch::with(['pantone', 'supplier', 'material']);

        if (!empty($filters['fabric_type'])) {
            $q->where('fabric_type', $filters['fabric_type']);
        }
        if (!empty($filters['gsm'])) {
            $q->where('gsm', (int) $filters['gsm']);
        }
        if (!empty($filters['collection'])) {
            $q->where('collection', $filters['collection']);
        }
        if (!empty($filters['supplier_id'])) {
            $q->where('supplier_id', (int) $filters['supplier_id']);
        }
        if (!empty($filters['color_family'])) {
            $q->where('color_family', $filters['color_family']);
        }

        return $q->orderBy('color_family')->orderBy('name')->get();
    }

    /**
     * Record a pick — atomically increment pick_count (powers the
     * frequency-based "Most used" group in the swatch picker). Atomic so
     * concurrent CSR picks can't clobber each other. Returns the refreshed
     * swatch (with relations) ready for present().
     */
    public function recordPick(int $id): FabricSwatch
    {
        $swatch = FabricSwatch::findOrFail($id);
        $swatch->increment('pick_count');

        return $swatch->fresh(['pantone', 'supplier', 'material']);
    }

    public function find(int $id): ?FabricSwatch
    {
        return FabricSwatch::with(['pantone', 'supplier', 'material'])->find($id);
    }

    public function create(array $data, ?UploadedFile $photo = null): FabricSwatch
    {
        return DB::transaction(function () use ($data, $photo) {
            if ($photo !== null) {
                $data['photo_path'] = $photo->store('csr/fabric-swatches', 'public');
            }

            $swatch = FabricSwatch::create($data);

            return $swatch->fresh(['pantone', 'supplier', 'material']);
        });
    }

    public function update(int $id, array $data, ?UploadedFile $photo = null): FabricSwatch
    {
        return DB::transaction(function () use ($id, $data, $photo) {
            /** @var FabricSwatch $swatch */
            $swatch = FabricSwatch::lockForUpdate()->findOrFail($id);

            if ($photo !== null) {
                if ($swatch->photo_path !== null) {
                    Storage::disk('public')->delete($swatch->photo_path);
                }
                $data['photo_path'] = $photo->store('csr/fabric-swatches', 'public');
            }

            $swatch->fill($data)->save();

            return $swatch->fresh(['pantone', 'supplier', 'material']);
        });
    }

    public function delete(int $id): bool
    {
        return DB::transaction(function () use ($id) {
            /** @var FabricSwatch $swatch */
            $swatch = FabricSwatch::lockForUpdate()->findOrFail($id);

            if ($swatch->photo_path !== null) {
                Storage::disk('public')->delete($swatch->photo_path);
            }

            return (bool) $swatch->delete();
        });
    }

    /**
     * Build the presenter shape — includes stock_status from the
     * linked material (if any).
     */
    public function present(FabricSwatch $swatch): array
    {
        $stockOnHand = null;
        $stockStatus = 'unknown';

        if ($swatch->material_id !== null && $swatch->material !== null) {
            // Materials.stock_on_hand was added Phase 4 (Material Requests).
            $stockOnHand = $swatch->material->stock_on_hand ?? null;

            if ($stockOnHand === null) {
                $stockStatus = 'unknown';
            } elseif ((float) $stockOnHand <= 0) {
                $stockStatus = 'out_of_stock';
            } elseif ((float) $stockOnHand <= self::LOW_STOCK_THRESHOLD) {
                $stockStatus = 'low_stock';
            } else {
                $stockStatus = 'in_stock';
            }
        }

        return [
            'id'           => $swatch->id,
            'name'         => $swatch->name,
            'pantone_id'   => $swatch->pantone_id,
            'pantone_name' => optional($swatch->pantone)->name,
            'pantone_code' => optional($swatch->pantone)->pantone_code,
            'hex_color'    => $swatch->hex_color,
            'fabric_type'  => $swatch->fabric_type,
            'gsm'          => $swatch->gsm,
            'collection'   => $swatch->collection,
            'supplier_id'  => $swatch->supplier_id,
            'supplier_name' => optional($swatch->supplier)->name,
            'material_id'  => $swatch->material_id,
            'stock_on_hand' => $stockOnHand !== null ? (float) $stockOnHand : null,
            'stock_status' => $stockStatus,
            'color_family' => $swatch->color_family,
            'photo_path'   => $swatch->photo_path,
            'photo_url'    => $swatch->photo_path
                ? Storage::disk('public')->url($swatch->photo_path)
                : null,
            'notes'        => $swatch->notes,
            'pick_count'   => (int) ($swatch->pick_count ?? 0),
            'created_at'   => $swatch->created_at?->toIso8601String(),
            'updated_at'   => $swatch->updated_at?->toIso8601String(),
        ];
    }
}
