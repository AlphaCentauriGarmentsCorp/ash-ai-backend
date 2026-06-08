<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderSamples;
use App\Models\PoItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Writer\PngWriter;
use Endroid\QrCode\Encoding\Encoding;
use Picqer\Barcode\BarcodeGeneratorPNG;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use App\Exceptions\BusinessRuleException;

/**
 * OrderService — owns Order creation.
 *
 * The `orders` table now uses a quotation-derived schema: FK-based
 * apparel/pattern/print method, financials, and JSON carry-over from
 * the source quotation. Production-time fields (courier, fabric, etc.)
 * that used to live on Order are no longer on the table.
 *
 * The store() method is tolerant of either-shape input — it will
 * accept the new payload (`subtotal`/`grand_total`/`apparel_type_id`/...)
 * AND legacy payloads (`total_amount`/`apparel_type` string), so that
 * the existing `/orders/new` form keeps working as the frontend evolves.
 */
class OrderService
{
    protected OrderStagesService $stagesService;
    protected NotificationService $notifications;
    protected QuotationService $quotation;
    protected CsrActivityLogger $activityLogger;

    public function __construct(OrderStagesService $stagesService, NotificationService $notifications, QuotationService $quotation, CsrActivityLogger $activityLogger)
    {
        $this->stagesService = $stagesService;
        $this->notifications = $notifications;
        $this->quotation = $quotation;
        $this->activityLogger = $activityLogger;
    }

    public function store(array $data, array $meta = []): Order
    {
        // Resolve + normalise every persisted field (also enforces the
        // line-item floor and the superadmin Incomplete flag). Shared with
        // update() so both paths normalise, re-price and floor-check the same.
        $f = $this->resolveOrderFields($data, $meta);

        $order = DB::transaction(function () use ($f, $data) {
            // Generate unique PO code + its QR / Barcode artifacts.
            $poCode = $this->generatePoCode('ASH');
            $codes  = $this->generateQrAndBarcode($poCode, 'Orders');

            // Create the Order using ONLY columns present in the orders table.
            $order = Order::create(array_merge(
                $this->buildOrderAttributes($f, $data),
                [
                    'po_code'      => $poCode,
                    'qr_path'      => $codes['qr_path'],
                    'barcode_path' => $codes['barcode_path'],
                ],
            ));

            // PO items + samples — best-effort (form `sizes` / items_json).
            $this->createPoItems($data, $order);
            $this->createOrderSamples($data, $order);

            // File uploads → disk / order_designs.
            $this->storeFiles($data, $order);

            // Auto-create the full sequential workflow.
            $this->stagesService->initializeForOrder($order);

            return $order->load('items', 'samples', 'orderStages');
        });

        // Notifications fire after the commit.
        $this->notifications->orderCreated($order);

        // Change 11 — audit the override.
        if ($order->is_incomplete) {
            $actor = $meta['actor'] ?? null;
            $this->activityLogger->log(
                action:   'order.saved_incomplete',
                summary:  "{$order->po_code} saved with " . count($f['incomplete_fields']) . " missing field(s) via superadmin override",
                subject:  $order,
                orderId:  $order->id,
                clientId: $order->client_id,
                data:     ['incomplete_fields' => $f['incomplete_fields']],
                userId:   $actor?->getKey(),
            );
        }

        return $order;
    }

    /**
     * Update an existing order. Mirrors store()'s normalisation, line-item
     * floor, re-pricing and Incomplete-flag logic, but:
     *   - keeps po_code / qr_path / barcode_path / status / workflow intact,
     *   - does NOT (re)initialise the stage workflow,
     *   - DIFFS PO items (preserves SKU/QR/barcode for unchanged sizes),
     *   - clears the Incomplete flag on a clean save (no override).
     *
     * Editability (order not yet in production) is enforced by the caller.
     */
    public function update(Order $order, array $data, array $meta = []): Order
    {
        $f = $this->resolveOrderFields($data, $meta);
        $wasIncomplete = (bool) $order->is_incomplete;

        DB::transaction(function () use ($order, $f, $data) {
            // po_code / qr_path / barcode_path / status / workflow are NOT in
            // buildOrderAttributes(), so they stay as-is.
            $order->update($this->buildOrderAttributes($f, $data));

            // Reconcile line items against the submitted sizes.
            $this->diffPoItems($data, $order);

            // New files are additive (existing uploads are preserved).
            $this->storeFiles($data, $order);
        });

        $order->refresh()->load('items', 'samples', 'orderStages');

        // Audit: completing (flag cleared) and re-flagging are recorded
        // distinctly; a plain edit logs order.updated.
        $actor = $meta['actor'] ?? null;
        if ($order->is_incomplete) {
            $this->activityLogger->log(
                action:   'order.saved_incomplete',
                summary:  "{$order->po_code} updated, still missing " . count($f['incomplete_fields']) . " field(s) (superadmin override)",
                subject:  $order,
                orderId:  $order->id,
                clientId: $order->client_id,
                data:     ['incomplete_fields' => $f['incomplete_fields']],
                userId:   $actor?->getKey(),
            );
        } elseif ($wasIncomplete) {
            $this->activityLogger->log(
                action:   'order.completed',
                summary:  "{$order->po_code} completed — all required details filled in",
                subject:  $order,
                orderId:  $order->id,
                clientId: $order->client_id,
                data:     [],
                userId:   $actor?->getKey(),
            );
        } else {
            $this->activityLogger->log(
                action:   'order.updated',
                summary:  "{$order->po_code} updated",
                subject:  $order,
                orderId:  $order->id,
                clientId: $order->client_id,
                data:     [],
                userId:   $actor?->getKey(),
            );
        }

        return $order;
    }

    /**
     * Resolve + normalise every persisted order field from a raw payload.
     *
     * Accepts both legacy (form) and modern (quotation prefill / convert)
     * field names and maps to the orders-table shape. Also enforces the hard
     * line-item floor (throws BusinessRuleException), resolves the Change 11
     * Incomplete flag from the override meta, and re-prices through the engine
     * when the order is engine-priceable. Shared by store() and update().
     *
     * @return array<string, mixed>
     */
    protected function resolveOrderFields(array $data, array $meta = []): array
    {
        $pick = function (string ...$keys) use ($data) {
            foreach ($keys as $k) {
                if (array_key_exists($k, $data) && $data[$k] !== null && $data[$k] !== '') {
                    return $data[$k];
                }
            }
            return null;
        };

        $clientId       = $pick('client_id', 'client');
        $clientBrand    = $pick('client_brand', 'company');
        $clientName     = $pick('client_name');
        $apparelTypeId  = $pick('apparel_type_id');
        $patternTypeId  = $pick('pattern_type_id');
        $printMethodId  = $pick('print_method_id');
        $necklineId     = $pick('apparel_neckline_id');
        $shirtColor     = $pick('shirt_color');
        $specialPrint   = $pick('special_print');
        $printArea      = $pick('print_area');
        $freeItems      = $pick('free_items', 'freebie_others');
        $notes          = $pick('notes');

        $discountType   = $pick('discount_type');
        $discountPrice  = $pick('discount_price') ?? 0;
        $discountAmount = $pick('discount_amount') ?? 0;
        $subtotal       = $pick('subtotal', 'total_amount') ?? 0;
        $grandTotal     = $pick('grand_total', 'estimated_total', 'total_amount') ?? 0;

        $itemConfigJson = $this->decodeJson($pick('item_config_json'));
        $itemsJson      = $this->decodeJson($pick('items_json'));
        $addonsJson     = $this->decodeJson($pick('addons_json'));
        $breakdownJson  = $this->decodeJson($pick('breakdown_json'));
        $printPartsJson = $this->decodeJson($pick('print_parts_json'));

        $quotationId    = $pick('quotation_id');

        // ── Change 11: hard floor (never bypassable) ─────────────────────
        $itemSources = [
            $data['sizes'] ?? null,
            $itemsJson,
            is_array($breakdownJson) ? ($breakdownJson['items'] ?? $breakdownJson) : null,
        ];
        $hasLineItem = false;
        foreach ($itemSources as $src) {
            if (is_array($src)
                && count(array_filter($src, fn ($row) => is_array($row) && ! empty($row))) > 0) {
                $hasLineItem = true;
                break;
            }
        }
        if (! $hasLineItem) {
            throw new BusinessRuleException(
                'An order needs at least one line item (a size with quantity) before it can be saved.',
                'ORDER_NO_LINE_ITEMS',
                422,
                ['sizes' => 'Add at least one size and quantity.'],
            );
        }

        // ── Change 11: resolve the Incomplete flag from the override meta ──
        $overrideRequested = (bool) ($meta['override'] ?? false);
        $incompleteFields  = array_values(array_filter(
            array_map(fn ($v) => trim((string) $v), (array) ($meta['incomplete_fields'] ?? [])),
            fn ($v) => $v !== ''
        ));
        $isIncomplete = $overrideRequested && ! empty($incompleteFields);

        // ── Option-A pricing hardening ───────────────────────────────────
        if (is_array($itemConfigJson) && ! empty($itemConfigJson['apparel_pattern_price_id'])) {
            try {
                $computed = $this->quotation->preview([
                    'item_config_json'    => $itemConfigJson,
                    'items_json'          => $itemsJson,
                    'print_parts_json'    => $printPartsJson,
                    'addons_json'         => $addonsJson,
                    'apparel_neckline_id' => $necklineId,
                    'discount_type'       => $discountType,
                    'discount_price'      => $discountPrice,
                ]);

                $submittedGrand = (float) $grandTotal;

                $subtotal       = $computed['subtotal'] ?? $subtotal;
                $grandTotal     = $computed['grand_total'] ?? $grandTotal;
                $discountAmount = $computed['discount_amount'] ?? $discountAmount;
                if (! empty($computed['breakdown_json'])) {
                    $breakdownJson = $computed['breakdown_json'];
                }

                if (abs($submittedGrand - (float) $grandTotal) > 0.01) {
                    Log::warning('Order persist: client grand_total overridden by engine recompute', [
                        'submitted_grand_total' => $submittedGrand,
                        'engine_grand_total'    => (float) $grandTotal,
                    ]);
                }
            } catch (\Throwable $e) {
                Log::warning('Order persist: engine recompute failed; using submitted totals', [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return [
            'client_id'           => $clientId,
            'client_brand'        => $clientBrand,
            'client_name'         => $clientName,
            'apparel_type_id'     => $apparelTypeId,
            'pattern_type_id'     => $patternTypeId,
            'print_method_id'     => $printMethodId,
            'apparel_neckline_id' => $necklineId,
            'shirt_color'         => $shirtColor,
            'special_print'       => $specialPrint,
            'print_area'          => $printArea,
            'free_items'          => $freeItems,
            'notes'               => $notes,
            'discount_type'       => $discountType,
            'discount_price'      => $discountPrice,
            'discount_amount'     => $discountAmount,
            'subtotal'            => $subtotal,
            'grand_total'         => $grandTotal,
            'item_config_json'    => $itemConfigJson,
            'items_json'          => $itemsJson,
            'addons_json'         => $addonsJson,
            'breakdown_json'      => $breakdownJson,
            'print_parts_json'    => $printPartsJson,
            'quotation_id'        => $quotationId,
            'is_incomplete'       => $isIncomplete,
            'incomplete_fields'   => $incompleteFields,
        ];
    }

    /**
     * Build the Order attribute array from resolved fields ($f) + the raw
     * payload ($data, for the form-only columns). Excludes po_code / qr_path /
     * barcode_path (store() generates those once; update() must not touch
     * them). Shared by store() and update().
     *
     * @param array<string, mixed> $f
     * @return array<string, mixed>
     */
    protected function buildOrderAttributes(array $f, array $data): array
    {
        $attrs = [
            // Linkage
            'quotation_id'       => $f['quotation_id'],

            // Client
            'client_id'          => $f['client_id'],
            'client_name'        => $f['client_name'],
            'client_brand'       => $f['client_brand'],

            // Apparel + print method (FK based)
            'apparel_type_id'    => $f['apparel_type_id'],
            'pattern_type_id'    => $f['pattern_type_id'],
            'apparel_neckline_id'=> $f['apparel_neckline_id'],
            'print_method_id'    => $f['print_method_id'],

            // Print details
            'shirt_color'        => $f['shirt_color'],
            'special_print'      => $f['special_print'],
            'print_area'         => $f['print_area'],

            // Misc descriptive
            'free_items'         => $f['free_items'],
            'notes'              => $f['notes'],

            // Financials
            'discount_type'      => $f['discount_type'],
            'discount_price'     => $f['discount_price'],
            'discount_amount'    => $f['discount_amount'],
            'subtotal'           => $f['subtotal'],
            'grand_total'        => $f['grand_total'],

            // JSON blobs carried over from the quotation
            'item_config_json'   => $f['item_config_json'],
            'items_json'         => $f['items_json'],
            'addons_json'        => $f['addons_json'],
            'breakdown_json'     => $f['breakdown_json'],
            'print_parts_json'   => $f['print_parts_json'],

            // ── Order Information ────────────────────────────────────
            // These columns exist on `orders` and the form sends them, but
            // were previously dropped on create. Persist on store + update.
            'deadline'           => $data['deadline'] ?? null,
            'priority'           => $data['priority'] ?? null,
            'brand'              => $data['brand'] ?? null,

            // ── Shipping / courier (Add Order) ──────────────────────
            'courier'            => $data['courier'] ?? null,
            'method'             => $data['method'] ?? null,
            'receiver_name'      => $data['receiver_name'] ?? null,
            'contact_number'     => $data['contact_number'] ?? null,
            'street_address'     => $data['street_address'] ?? null,
            'barangay_address'   => $data['barangay_address'] ?? null,
            'city_address'       => $data['city_address'] ?? null,
            'province_address'   => $data['province_address'] ?? null,
            'postal_address'     => $data['postal_address'] ?? null,

            // ── Production details (Add Order) ──────────────────────
            'design_name'           => $data['design_name'] ?? null,
            'service_type'          => $data['service_type'] ?? null,
            'print_service'         => $data['print_service'] ?? null,
            'size_label'            => $data['size_label'] ?? null,
            'print_label_placement' => $data['print_label_placement'] ?? null,
            'fabric_type'           => $data['fabric_type'] ?? null,
            'fabric_supplier'       => $data['fabric_supplier'] ?? null,
            'fabric_color'          => $data['fabric_color'] ?? null,
            'thread_color'          => $data['thread_color'] ?? null,
            'ribbing_color'         => $data['ribbing_color'] ?? null,
            'freebie_items'         => $data['freebie_items'] ?? null,
            'freebie_color'         => $data['freebie_color'] ?? null,
            'freebie_others'        => $data['freebie_others'] ?? null,
            'payment_plan'          => $data['payment_plan'] ?? null,
            'payment_method'        => $data['payment_method'] ?? null,
            'deposit_percentage'    => $data['deposit_percentage'] ?? null,

            // Change 11 — Incomplete flag (cleared on a clean save)
            'is_incomplete'         => $f['is_incomplete'],
            'incomplete_fields'     => $f['is_incomplete'] ? $f['incomplete_fields'] : null,
        ];

        // `priority` is NOT NULL with a DB default ('normal'). Never write an
        // explicit null: on create it would violate the constraint, and on
        // update it would clobber the value. Omitting the key lets create fall
        // back to the default and update keep the existing value.
        if ($attrs['priority'] === null) {
            unset($attrs['priority']);
        }

        return $attrs;
    }

    /**
     * Reconcile an order's PO items against the submitted sizes. Existing rows
     * for a kept size are preserved (SKU / QR / barcode + any printed labels
     * stay) and only their quantity / design / color are refreshed; sizes no
     * longer present are deleted; brand-new sizes are minted via the same
     * SKU/QR/barcode scheme as createPoItems(). No-op when no sizes submitted.
     */
    protected function diffPoItems(array $data, Order $order): void
    {
        $sizes = $data['sizes'] ?? null;
        if (! is_array($sizes) || empty($sizes)) {
            $itemsJson = $data['items_json'] ?? $order->items_json;
            if (is_string($itemsJson)) {
                $decoded = json_decode($itemsJson, true);
                $sizes = is_array($decoded) ? $decoded : [];
            } elseif (is_array($itemsJson)) {
                $sizes = $itemsJson;
            } else {
                $sizes = [];
            }
        }

        if (empty($sizes)) return;

        $submitted = [];
        foreach ($sizes as $size) {
            if (! is_array($size)) continue;
            $name = (string) ($size['name'] ?? $size['size'] ?? '');
            if ($name === '') continue;
            $submitted[strtoupper($name)] = [
                'name'     => $name,
                'quantity' => $size['quantity'] ?? 0,
            ];
        }

        $existing = $order->items()->get()->keyBy(fn ($it) => strtoupper((string) $it->size));

        $designName  = $data['design_name'] ?? $order->design_name ?? null;
        $fabricColor = $data['fabric_color'] ?? $order->shirt_color ?? null;

        // Remove sizes no longer present.
        foreach ($existing as $key => $item) {
            if (! array_key_exists($key, $submitted)) {
                $item->delete();
            }
        }

        // Update kept sizes; mint new ones.
        foreach ($submitted as $key => $row) {
            if (isset($existing[$key])) {
                $existing[$key]->update([
                    'quantity'    => $row['quantity'],
                    'design_code' => $designName ?? '',
                    'color'       => $fabricColor ?? '',
                ]);
                continue;
            }

            $brandPrefix = 'X';
            $lastNumber = PoItem::where('sku', 'like', $brandPrefix . '%')
                ->orderByDesc('id')
                ->value('sku');
            $lastNumber = $lastNumber ? (int) substr($lastNumber, 1, 3) : 0;
            $nextNumber = $lastNumber + 1;
            $sizeCode   = strtoupper($row['name']);
            $sku        = $brandPrefix . str_pad((string) $nextNumber, 3, '0', STR_PAD_LEFT) . "U{$sizeCode}";
            $paths      = $this->generateQrAndBarcode($sku, 'ItemCodes');

            PoItem::create([
                'order_id'     => $order->id,
                'sku'          => $sku,
                'design_code'  => $designName ?? '',
                'color'        => $fabricColor ?? '',
                'size'         => $row['name'],
                'quantity'     => $row['quantity'],
                'qr_path'      => $paths['qr_path'],
                'barcode_path' => $paths['barcode_path'],
            ]);
        }
    }

    /**
     * Decode a JSON-string OR pass through an already-decoded array OR
     * return null. Useful for fields that arrive either way through the
     * controller depending on whether the request used FormData (string)
     * or JSON (array).
     */
    protected function decodeJson($value): ?array
    {
        if ($value === null || $value === '') return null;
        if (is_array($value)) return $value;
        if (! is_string($value)) return null;
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Generate QR and Barcode artifacts for a code, returning both paths.
     */
    protected function generateQrAndBarcode(string $code, string $folder): array
    {
        $basePath = "{$folder}";
        $publicPath = "/storage/{$folder}";

        $qrFile = "qr_{$code}.png";
        $barcodeFile = "barcode_{$code}.png";

        Storage::disk('public')->makeDirectory($basePath);

        $qrFullPath = "{$basePath}/{$qrFile}";
        $barcodeFullPath = "{$basePath}/{$barcodeFile}";

        $qrImage = Builder::create()
            ->writer(new PngWriter())
            ->data($code)
            ->encoding(new Encoding('UTF-8'))
            ->size(200)
            ->build()
            ->getString();

        Storage::disk('public')->put($qrFullPath, $qrImage);

        $generator = new BarcodeGeneratorPNG();
        $barcodeData = $generator->getBarcode($code, $generator::TYPE_CODE_128, 2, 50);
        Storage::disk('public')->put($barcodeFullPath, $barcodeData);

        return [
            'qr_path'      => "{$publicPath}/{$qrFile}",
            'barcode_path' => "{$publicPath}/{$barcodeFile}",
        ];
    }

    /**
     * Create PO items from the form `sizes` array OR from the items_json
     * carried over from the source quotation. Best-effort: skips quietly
     * when neither is present.
     *
     * SKU prefix used to come from $order->brand (now removed). We pick
     * a default 'X' prefix when no brand context is available.
     */
    protected function createPoItems(array $data, Order $order): void
    {
        // Try the form `sizes` shape first; fall back to items_json.
        $sizes = $data['sizes'] ?? null;
        if (! is_array($sizes) || empty($sizes)) {
            $itemsJson = $data['items_json'] ?? $order->items_json;
            if (is_string($itemsJson)) {
                $decoded = json_decode($itemsJson, true);
                $sizes = is_array($decoded) ? $decoded : [];
            } elseif (is_array($itemsJson)) {
                $sizes = $itemsJson;
            } else {
                $sizes = [];
            }
        }

        if (empty($sizes)) return;

        // SKU prefix. With brand gone from the schema, default to 'X'.
        $brandPrefix = 'X';

        $lastNumber = PoItem::where('sku', 'like', $brandPrefix . '%')
            ->orderByDesc('id')
            ->value('sku');

        $lastNumber = $lastNumber ? (int) substr($lastNumber, 1, 3) : 0;

        $designName = $data['design_name'] ?? null;
        $fabricColor = $data['fabric_color'] ?? $order->shirt_color ?? null;

        foreach ($sizes as $size) {
            if (! is_array($size)) continue;

            $sizeName = $size['name'] ?? $size['size'] ?? '';
            $sizeCode = strtoupper((string) $sizeName);
            if ($sizeCode === '') continue;

            $nextNumber = $lastNumber + 1;
            $sku = $brandPrefix . str_pad((string) $nextNumber, 3, '0', STR_PAD_LEFT) . "U{$sizeCode}";

            $paths = $this->generateQrAndBarcode($sku, 'ItemCodes');

            PoItem::create([
                'order_id'     => $order->id,
                'sku'          => $sku,
                // design_code + color are NOT NULL on po_items, but both are
                // OPTIONAL on the order form (and may be skipped entirely on a
                // superadmin "save anyway" / Incomplete order). Coalesce to ''
                // so an order missing these still creates its line items.
                'design_code'  => $designName ?? '',
                'color'        => $fabricColor ?? '',
                'size'         => $sizeName,
                'quantity'     => $size['quantity'] ?? 0,
                'qr_path'      => $paths['qr_path'],
                'barcode_path' => $paths['barcode_path'],
            ]);

            $lastNumber = $nextNumber;
        }
    }

    /**
     * Create Order Samples. Skips quietly when no `samples` array is
     * present in the payload (the convert-from-quotation flow doesn't
     * always include them).
     */
    protected function createOrderSamples(array $data, Order $order): void
    {
        $samples = $data['samples'] ?? [];
        if (! is_array($samples) || empty($samples)) return;

        foreach ($samples as $sample) {
            if (! is_array($sample)) continue;

            OrderSamples::create([
                'order_id'    => $order->id,
                'size'        => $sample['size'] ?? null,
                'quantity'    => $sample['quantity'] ?? 0,
                'total_price' => $sample['total_price'] ?? 0,
                'unit_price'  => $sample['unit_price'] ?? 0,
            ]);
        }
    }

    /**
     * Persist any uploaded design files into storage.
     *
     * NOTE: The new orders table does NOT have `design_files` /
     * `design_mockup` / `size_label_files` / `freebies_files` columns.
     * We physically store the uploaded files under `orders/{po_code}/...`
     * so they're not lost, but we don't write any path back to the
     * orders row (it would crash on a missing column). Phase 5 will
     * introduce a proper attachment table.
     */
    protected function storeFiles(array $data, Order $order): void
    {
        $map = [
            'design_files'     => 'design_files',
            'design_mockup'    => 'design_mockups',
            'size_label_files' => 'size_labels',
            'freebies_files'   => 'freebies',
            'payments'         => 'payments',
        ];

        foreach ($map as $field => $folder) {
            $files = $data[$field] ?? null;
            if (empty($files) || ! is_array($files)) continue;

            foreach ($files as $file) {
                if (! is_object($file) || ! method_exists($file, 'storeAs')) continue;
                $filename = $file->getClientOriginalName();
                $file->storeAs("orders/{$order->po_code}/{$folder}", $filename, 'public');
            }
        }
    }

    /**
     * Generate unique PO code.
     */
    protected function generatePoCode(string $prefix = 'ASH'): string
    {
        $year = now()->year;
        // withTrashed(): PO numbers must never be reused, so a soft-deleted
        // order still "occupies" its number. Without this, deleting the most
        // recent order would let the next order reclaim its PO code and
        // collide on the unique index.
        //
        // The trailing sequence is parsed in PHP rather than via the MySQL-only
        // SUBSTRING_INDEX(), so the query is portable — identical result on
        // MySQL/MariaDB and on the sqlite test database. lockForUpdate stays
        // for concurrency on MySQL (it is a no-op on sqlite, which has no
        // row-level locks).
        $lastNumber = Order::withTrashed()
            ->whereYear('created_at', $year)
            ->lockForUpdate()
            ->pluck('po_code')
            ->map(fn ($code) => (int) substr((string) $code, strrpos((string) $code, '-') + 1))
            ->max();

        $nextNumber = ((int) $lastNumber) + 1;
        return sprintf('%s-%d-%06d', $prefix, $year, $nextNumber);
    }
}