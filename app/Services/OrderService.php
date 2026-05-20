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

    public function __construct(OrderStagesService $stagesService, NotificationService $notifications)
    {
        $this->stagesService = $stagesService;
        $this->notifications = $notifications;
    }

    public function store(array $data): Order
    {
        // ----------------------------------------------------------------
        // Field-name normalisation. Accept both legacy (form) and modern
        // (quotation prefill / convert flow) field names. The orders
        // table only has the modern shape so we map down to that.
        // ----------------------------------------------------------------
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

        $order = DB::transaction(function () use (
            $clientId, $clientBrand, $clientName,
            $apparelTypeId, $patternTypeId, $printMethodId, $necklineId,
            $shirtColor, $specialPrint, $printArea,
            $freeItems, $notes,
            $discountType, $discountPrice, $discountAmount, $subtotal, $grandTotal,
            $itemConfigJson, $itemsJson, $addonsJson, $breakdownJson, $printPartsJson,
            $quotationId, $data,
        ) {
            // Generate unique PO code
            $poCode = $this->generatePoCode('ASH');

            // Generate QR + Barcode
            $codes = $this->generateQrAndBarcode($poCode, 'Orders');

            // Create the Order using ONLY columns present in the orders table.
            $order = Order::create([
                // Linkage
                'quotation_id'       => $quotationId,
                'po_code'            => $poCode,

                // Client
                'client_id'          => $clientId,
                'client_name'        => $clientName,
                'client_brand'       => $clientBrand,

                // Apparel + print method (FK based)
                'apparel_type_id'    => $apparelTypeId,
                'pattern_type_id'    => $patternTypeId,
                'apparel_neckline_id'=> $necklineId,
                'print_method_id'    => $printMethodId,

                // Print details
                'shirt_color'        => $shirtColor,
                'special_print'      => $specialPrint,
                'print_area'         => $printArea,

                // Misc descriptive
                'free_items'         => $freeItems,
                'notes'              => $notes,

                // Financials
                'discount_type'      => $discountType,
                'discount_price'     => $discountPrice,
                'discount_amount'    => $discountAmount,
                'subtotal'           => $subtotal,
                'grand_total'        => $grandTotal,

                // JSON blobs carried over from the quotation
                'item_config_json'   => $itemConfigJson,
                'items_json'         => $itemsJson,
                'addons_json'        => $addonsJson,
                'breakdown_json'     => $breakdownJson,
                'print_parts_json'   => $printPartsJson,

                // Artifacts
                'qr_path'            => $codes['qr_path'],
                'barcode_path'       => $codes['barcode_path'],

                // Status defaults to 'Pending Approval' (column default)
            ]);

            // PO items + samples — best-effort, only run when the form
            // included sizes/samples arrays. The new convert-from-quotation
            // path doesn't include these (sizes come from `breakdown_json`
            // instead), so we read both shapes and fall back gracefully.
            $this->createPoItems($data, $order);
            $this->createOrderSamples($data, $order);

            // File uploads — only persist file paths into JSON blobs that
            // exist on the orders table. The legacy design_files / mockup
            // / freebies_files columns are gone from this schema, so we
            // write the file paths into a dedicated row in `order_designs`
            // instead. (For now the upload is stored on disk and the path
            // is recorded; downstream Phase 5 will surface them in the UI.)
            $this->storeFiles($data, $order);

            // Auto-create the full 14-stage sequential workflow.
            $this->stagesService->initializeForOrder($order);

            return $order->load('items', 'samples', 'orderStages');
        });

        // Notifications fire after the commit. CSR + managers get pinged
        // about the new order so they can pick it up.
        $this->notifications->orderCreated($order);

        return $order;
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
                'design_code'  => $designName,
                'color'        => $fabricColor,
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
        $lastNumber = Order::whereYear('created_at', $year)
            ->lockForUpdate()
            ->selectRaw("CAST(SUBSTRING_INDEX(po_code, '-', -1) AS UNSIGNED) as num")
            ->orderByDesc('num')
            ->value('num');

        $nextNumber = ($lastNumber ?? 0) + 1;
        return sprintf('%s-%d-%06d', $prefix, $year, $nextNumber);
    }
}
