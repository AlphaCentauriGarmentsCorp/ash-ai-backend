<?php

namespace App\Services;

use App\Models\Order;
use App\Models\PoItem;
use App\Models\OrderSamples;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Writer\PngWriter;
use Endroid\QrCode\Encoding\Encoding;
use Picqer\Barcode\BarcodeGeneratorPNG;

class OrderService
{
    /**
     * Store a new order from validated data.
     *
     * Accepts the pre-filled payload passed from QuotationService::confirmAndConvert()
     * via the frontend AddNewOrder form. Handles JSON blob normalisation,
     * QR/barcode generation (GD-guarded), and references print_parts image
     * paths already stored by the Quotation — no re-upload needed.
     */
    public function store(array $data): Order
    {
        return DB::transaction(function () use ($data) {
            $poCode = $this->generatePoCode('ASH');

            // QR/barcode generation requires PHP GD extension.
            // Gracefully skipped when GD is unavailable — paths stored as null
            // and can be backfilled later via artisan command.
            $orderCodes = $this->tryGenerateQrAndBarcode($poCode, 'Orders');

            // Decode JSON blobs that may arrive as strings from the form
            $itemConfigJson = $this->decodeJsonField($data['item_config_json'] ?? null);
            $itemsJson      = $this->decodeJsonField($data['items_json']       ?? null);
            $addonsJson     = $this->decodeJsonField($data['addons_json']      ?? null);
            $breakdownJson  = $this->decodeJsonField($data['breakdown_json']   ?? null);
            $printPartsJson = $this->decodeJsonField($data['print_parts_json'] ?? null);

            // Financial totals are passed directly from quotation columns —
            // breakdown_json only holds itemised rows, not the summary totals.
            $subtotal    = (float) ($data['subtotal']        ?? 0);
            $grandTotal  = (float) ($data['grand_total']     ?? 0);
            $discountAmt = (float) ($data['discount_amount'] ?? 0);

            $order = Order::create([
                'po_code'             => $poCode,
                'quotation_id'        => $data['quotation_id']        ?? null,
                'client_id'           => $data['client_id']           ?? null,
                'client_name'         => $data['client_name']         ?? null,
                'client_brand'        => $data['client_brand']        ?? null,
                'apparel_type_id'     => $data['apparel_type_id']     ?? null,
                'pattern_type_id'     => $data['pattern_type_id']     ?? null,
                'apparel_neckline_id' => $data['apparel_neckline_id'] ?? null,
                'print_method_id'     => $data['print_method_id']     ?? null,
                'shirt_color'         => $data['shirt_color']         ?? null,
                'special_print'       => $data['special_print']       ?? null,
                'print_area'          => $data['print_area']          ?? 'Regular',
                'free_items'          => $data['free_items']          ?? null,
                'notes'               => $data['notes']               ?? null,
                'discount_type'       => $data['discount_type']       ?? null,
                'discount_price'      => $data['discount_price']      ?? 0,
                'discount_amount'     => $discountAmt,
                'subtotal'            => $subtotal,
                'grand_total'         => $grandTotal,
                'item_config_json'    => $itemConfigJson,
                'items_json'          => $itemsJson,
                'addons_json'         => $addonsJson,
                'breakdown_json'      => $breakdownJson,
                // print_parts_json references existing image paths from Quotation — no re-upload
                'print_parts_json'    => $printPartsJson,
                'qr_path'             => $orderCodes['qr_path'],
                'barcode_path'        => $orderCodes['barcode_path'],
                'status'              => 'Pending Approval',
            ]);

            return $order;
        });
    }

    // ── QR / Barcode ───────────────────────────────────────────────────────────

    /**
     * Attempt QR + barcode generation. Returns null paths on failure
     * (e.g. GD extension missing) so the order can still be saved.
     */
    protected function tryGenerateQrAndBarcode(string $code, string $folder): array
    {
        try {
            return $this->generateQrAndBarcode($code, $folder);
        } catch (\Throwable $e) {
            Log::warning("QR/barcode generation skipped for [{$code}]: " . $e->getMessage());
            return ['qr_path' => null, 'barcode_path' => null];
        }
    }

    /**
     * Generate QR and Barcode images and persist them to the public disk.
     * Requires the PHP GD extension to be enabled.
     */
    protected function generateQrAndBarcode(string $code, string $folder): array
    {
        $basePath   = $folder;
        $publicPath = "/storage/{$folder}";

        Storage::disk('public')->makeDirectory($basePath);

        $qrFile      = "qr_{$code}.png";
        $barcodeFile = "barcode_{$code}.png";

        $qrImage = Builder::create()
            ->writer(new PngWriter())
            ->data($code)
            ->encoding(new Encoding('UTF-8'))
            ->size(200)
            ->build()
            ->getString();

        Storage::disk('public')->put("{$basePath}/{$qrFile}", $qrImage);

        $generator   = new BarcodeGeneratorPNG();
        $barcodeData = $generator->getBarcode($code, $generator::TYPE_CODE_128, 2, 50);
        Storage::disk('public')->put("{$basePath}/{$barcodeFile}", $barcodeData);

        return [
            'qr_path'      => "{$publicPath}/{$qrFile}",
            'barcode_path' => "{$publicPath}/{$barcodeFile}",
        ];
    }

    // ── Helpers ────────────────────────────────────────────────────────────────

    protected function decodeJsonField(mixed $value): mixed
    {
        if (is_array($value)) return $value;
        if (is_string($value) && $value !== '') {
            $decoded = json_decode($value, true);
            return json_last_error() === JSON_ERROR_NONE ? $decoded : null;
        }
        return null;
    }

    /**
     * Generate a unique PO code: ASH-{year}-{000001}
     */
    protected function generatePoCode(string $prefix = 'ASH'): string
    {
        $year       = now()->year;
        $lastNumber = Order::whereYear('created_at', $year)
            ->lockForUpdate()
            ->selectRaw("CAST(SUBSTRING_INDEX(po_code, '-', -1) AS UNSIGNED) as num")
            ->orderByDesc('num')
            ->value('num');

        $nextNumber = ($lastNumber ?? 0) + 1;
        return sprintf('%s-%d-%06d', $prefix, $year, $nextNumber);
    }
}
