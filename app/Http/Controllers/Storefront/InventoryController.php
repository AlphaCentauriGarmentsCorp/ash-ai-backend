<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Models\Storefront\ProductVariant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The inventory ledger, for the external system that owns stock.
 *
 * Shape matches that system's own export one column per field, so a row can be read
 * or written without a translation layer in between.
 *
 * Ownership, which is the whole point of this endpoint:
 *   on_hand / is_active / location  -> THEIRS. Written here, read by the storefront.
 *   allocated                       -> OURS.  Raised at checkout when a unit is
 *                                      reserved; they clear it once a parcel ships.
 *   available                       -> derived, on_hand - allocated. Never written.
 *
 * Everything is keyed on SKU rather than our internal id, so their system never has
 * to store our primary keys.
 */
class InventoryController extends Controller
{
    /**
     * GET /inventory — the whole ledger, one row per SKU.
     *
     * ?updated_since=<iso8601> for an incremental pull, so a poller does not drag the
     * entire catalogue every minute.
     */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'updated_since' => ['sometimes', 'date'],
            'sku' => ['sometimes', 'string', 'max:64'],
        ]);

        $rows = ProductVariant::query()
            ->with('product')
            ->when(
                isset($data['updated_since']),
                fn ($q) => $q->where('storefront_product_variants.updated_at', '>=', $data['updated_since']),
            )
            ->when(isset($data['sku']), fn ($q) => $q->where('sku', $data['sku']))
            ->orderBy('product_id')
            ->orderBy('id')
            ->get()
            ->map(fn (ProductVariant $v) => $this->row($v));

        return response()->json([
            'data' => $rows,
            'meta' => [
                'count' => $rows->count(),
                'generated_at' => now()->toIso8601String(),
            ],
        ]);
    }

    /**
     * PATCH /inventory/{sku} — update one SKU.
     *
     * Partial: send only what changed. Absent fields are left alone rather than
     * being reset, so a caller that only knows about quantity cannot blank a
     * shelf location it never had.
     */
    public function update(Request $request, string $sku): JsonResponse
    {
        $variant = ProductVariant::with('product')->where('sku', $sku)->first();

        if (! $variant) {
            return response()->json(['message' => "No SKU {$sku}."], 404);
        }

        $variant->fill($this->validated($request))->save();

        return response()->json([
            'message' => 'Inventory updated.',
            'data' => $this->row($variant->fresh('product')),
        ]);
    }

    /**
     * POST /inventory/sync — update many SKUs in one call.
     *
     * All-or-nothing: a batch that names an unknown SKU is rejected whole rather than
     * half-applied, because a partially-applied stock sync is worse than a failed one
     * — the caller cannot tell what landed.
     */
    public function sync(Request $request): JsonResponse
    {
        $data = $request->validate([
            'items' => ['required', 'array', 'min:1', 'max:500'],
            'items.*.sku' => ['required', 'string', 'max:64'],
        ]);

        $skus = array_column($data['items'], 'sku');
        $known = ProductVariant::whereIn('sku', $skus)->pluck('sku')->all();

        if ($missing = array_values(array_diff($skus, $known))) {
            return response()->json([
                'message' => 'Unknown SKUs; nothing was applied.',
                'errors' => ['sku' => $missing],
            ], 422);
        }

        $updated = DB::transaction(function () use ($request, $data) {
            $n = 0;

            foreach ($data['items'] as $i => $line) {
                $fields = $this->validated($request, "items.$i.");

                if ($fields === []) {
                    continue;
                }

                ProductVariant::where('sku', $line['sku'])->first()?->fill($fields)->save();
                $n++;
            }

            return $n;
        });

        return response()->json(['message' => 'Inventory synced.', 'updated' => $updated]);
    }

    // ------------------------------------------------------------------

    /**
     * The writable fields, validated off a prefix so one rule set serves both the
     * single-SKU and the batch endpoint.
     *
     * `allocated` is accepted because the ERP has to be able to clear a reservation
     * once it ships the parcel — but it is the only reason, and it is never inferred.
     */
    private function validated(Request $request, string $prefix = ''): array
    {
        $rules = [
            'on_hand' => ['sometimes', 'integer', 'min:0', 'max:1000000'],
            'allocated' => ['sometimes', 'integer', 'min:0', 'max:1000000'],
            'cancelled_qty' => ['sometimes', 'integer', 'min:0', 'max:1000000'],
            'is_active' => ['sometimes', 'boolean'],
            'weight_grams' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100000'],
            'width_cm' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:1000'],
            'length_cm' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:1000'],
            'shelf_location' => ['sometimes', 'nullable', 'string', 'max:24'],
            'warehouse' => ['sometimes', 'nullable', 'string', 'max:60'],
            'area' => ['sometimes', 'nullable', 'string', 'max:60'],
        ];

        if ($prefix === '') {
            return $request->validate($rules);
        }

        $prefixed = [];
        foreach ($rules as $field => $rule) {
            $prefixed[$prefix.$field] = $rule;
        }

        $validated = $request->validate($prefixed);

        // Unwrap items.3.on_hand back to on_hand.
        $out = [];
        foreach (array_keys($rules) as $field) {
            $value = data_get($validated, $prefix.$field, '__absent__');

            if ($value !== '__absent__') {
                $out[$field] = $value;
            }
        }

        return $out;
    }

    /** One ledger row, column-for-column with the ERP's export. */
    private function row(ProductVariant $v): array
    {
        $p = $v->product;

        return [
            'sku' => $v->sku,
            'product_name' => $p?->name,
            'product_code' => $p?->product_code,
            'category' => $p ? strtoupper(str_replace('-', ' ', (string) $p->type)) : null,
            'size' => $v->size,

            'on_hand' => (int) $v->on_hand,
            'allocated' => (int) $v->allocated,
            'available' => $v->available,
            'cancelled_qty' => (int) $v->cancelled_qty,

            'price' => $p ? (int) $p->price : null,
            'status' => $v->is_active ? 'Active' : 'Inactive',

            'weight_grams' => $v->weight_grams !== null ? (float) $v->weight_grams : null,
            'dimensions_cm' => $v->dimensions,
            'width_cm' => $v->width_cm !== null ? (float) $v->width_cm : null,
            'length_cm' => $v->length_cm !== null ? (float) $v->length_cm : null,

            'shelf_location' => $v->shelf_location,
            'warehouse' => $v->warehouse ?: config('reefer.inventory.default_warehouse'),
            'area' => $v->area,
            'marketplace' => $p?->marketplace ?: config('reefer.inventory.default_marketplace'),

            'image' => $p?->image_path ? asset('storage/'.$p->image_path) : null,
            'external_image_id' => $p?->external_image_id,

            'id' => $v->id,
            'created_at' => optional($v->created_at)->toIso8601String(),
            'updated_at' => optional($v->updated_at)->toIso8601String(),
        ];
    }
}
