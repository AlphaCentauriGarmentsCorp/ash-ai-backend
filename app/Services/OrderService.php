<?php

namespace App\Services;

use App\Models\Order;
use App\Models\PoItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Writer\PngWriter;
use Endroid\QrCode\Encoding\Encoding;
use Picqer\Barcode\BarcodeGeneratorPNG;
use Illuminate\Support\Facades\Storage;

class OrderService
{


    public function store(array $data): Order
    {
        return DB::transaction(function () use ($data) {

            // Generate unique PO code
            $poCode = $this->generatePoCode('ASH');

            // Create QR and Barcode for the Order
            $orderCodes = $this->generateQrAndBarcode($poCode, 'Orders');

            // Create Order
            $order = Order::create([
                'po_code' => $poCode,
                'client_id' => $data['client'],
                'client_brand' => $data['company'],
                'brand' => $data['brand'],
                'priority' => $data['priority'],
                'deadline' => $data['deadline'],

                'courier' => $data['courier'],
                'method' => $data['method'],
                'receiver_name' => $data['receiver_name'],
                'receiver_contact' => $data['contact_number'],
                'address' => implode(', ', array_filter([
                    $data['street_address'] ?? null,
                    $data['barangay_address'] ?? null,
                    $data['city_address'] ?? null,
                    $data['province_address'] ?? null,
                    $data['postal_address'] ?? null,
                ])),

                'design_name' => $data['design_name'],
                'apparel_type' => $data['apparel_type'],
                'pattern_type' => $data['pattern_type'],
                'service_type' => $data['service_type'],
                'print_method' => $data['print_method'],
                'print_service' => $data['print_service'],
                'size_label' => $data['size_label'],
                'print_label_placement' => $data['print_label_placement'],

                'freebie_items' => $data['freebie_items'],
                'freebie_others' => $data['freebie_others'],
                'freebie_color' => $data['freebie_color'],

                'fabric_type' => $data['fabric_type'],
                'fabric_supplier' => $data['fabric_supplier'],
                'fabric_color' => $data['fabric_color'],
                'thread_color' => $data['thread_color'],
                'ribbing_color' => $data['ribbing_color'],

                'placement_measurements' => $data['placement_measurements'] ?? null,
                'notes' => $data['notes'] ?? null,
                'options' => isset($data['selectedOptions']) ? json_encode($data['selectedOptions']) : null,

                'payment_method' => $data['payment_method'] ?? null,
                'payment_plan' => $data['payment_plan'] ?? null,
                'total_price' => $data['total_amount'],
                'average_unit_price' => $data['average_unit_price'],
                'total_quantity' => $data['total_quantity'],

                'deposit' => $data['deposit_percentage'],

                'qr_path' => $orderCodes['qr_path'],
                'barcode_path' => $orderCodes['barcode_path'],
            ]);

            // Create PO Items
            $this->createPoItems($data, $order);

            // Store uploaded files
            $this->storeFiles($data, $order);

            return $order->load('items');
        });
    }

    /**
     * Generate QR and Barcode
     */
    protected function generateQrAndBarcode(string $code, string $folder): array
    {
        $basePath = "{$folder}"; // relative to 'public' disk
        $publicPath = "/storage/{$folder}";

        $qrFile = "qr_{$code}.png";
        $barcodeFile = "barcode_{$code}.png";

        // Ensure folder exists using Storage facade
        Storage::disk('public')->makeDirectory($basePath);

        $qrFullPath = "{$basePath}/{$qrFile}";
        $barcodeFullPath = "{$basePath}/{$barcodeFile}";

        // Generate QR code
        $qrImage = Builder::create()
            ->writer(new PngWriter())
            ->data($code)
            ->encoding(new Encoding('UTF-8'))
            ->size(200)
            ->build()
            ->getString();

        Storage::disk('public')->put($qrFullPath, $qrImage);

        // Generate Barcode
        $generator = new BarcodeGeneratorPNG();
        $barcodeData = $generator->getBarcode($code, $generator::TYPE_CODE_128, 2, 50);
        Storage::disk('public')->put($barcodeFullPath, $barcodeData);

        return [
            'qr_path' => "{$publicPath}/{$qrFile}",
            'barcode_path' => "{$publicPath}/{$barcodeFile}",
        ];
    }

    /**
     * Create PO items and generate SKU, QR, Barcode
     */
    protected function createPoItems(array $data, Order $order): void
    {
        $brandPrefix = match (strtolower($order->brand)) {
            'reefer' => 'R',
            'sorbetes' => 'S',
            default => 'X',
        };

        $lastNumber = PoItem::where('sku', 'like', $brandPrefix . '%')
            ->orderByDesc('id')
            ->value('sku');

        $lastNumber = $lastNumber ? (int)substr($lastNumber, 1, 3) : 0;

        foreach ($data['sizes'] as $size) {
            $nextNumber = $lastNumber + 1;
            $sizeCode = strtoupper($size['name'] ?? $size['size']);
            $sku = $brandPrefix . str_pad($nextNumber, 3, '0', STR_PAD_LEFT) . "U{$sizeCode}";

            $paths = $this->generateQrAndBarcode($sku, 'ItemCodes');

            PoItem::create([
                'order_id' => $order->id,
                'sku' => $sku,
                'design_code' => $data['design_name'],
                'color' => $data['fabric_color'],
                'size' => $size['name'] ?? $size['size'],
                'quantity' => $size['quantity'],
                'qr_path' => $paths['qr_path'],
                'barcode_path' => $paths['barcode_path'],
            ]);
        }
    }

    /**
     * Store uploaded files
     */
    protected function storeFiles(array $data, Order $order): void
    {
        $map = [
            'design_files' => 'design_files',
            'design_mockup' => 'design_mockups',
            'size_label_files' => 'size_labels',
            'freebies_files' => 'freebies',
            'payments' => 'payments',
        ];

        foreach ($map as $field => $folder) {
            if (empty($data[$field])) continue;

            $paths = [];
            foreach ($data[$field] as $file) {
                $filename = $file->getClientOriginalName();
                $file->storeAs("orders/{$order->po_code}/{$folder}", $filename, 'public');
                $paths[] = "/storage/orders/{$order->po_code}/{$folder}/{$filename}";
            }

            $order->update([$field => json_encode($paths)]);
        }
    }

    /**
     * Generate unique PO code
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
