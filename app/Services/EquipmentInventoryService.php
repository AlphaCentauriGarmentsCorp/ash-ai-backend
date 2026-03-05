<?php

namespace App\Services;

use App\Models\EquipmentInventory;
use Illuminate\Database\Eloquent\Collection;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Writer\PngWriter;
use Illuminate\Http\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

class EquipmentInventoryService
{

    public function getAll(): Collection
    {
        return EquipmentInventory::with('location')->get();
    }

    public function getByLocation($locationId): Collection
    {
        return EquipmentInventory::with('location')->where('location_id', $locationId)->get();
    }

    public function find(int $id): ?EquipmentInventory
    {
        return EquipmentInventory::with('location')->find($id);
    }

    public function create(array $data): EquipmentInventory
    {
        $files = [];
        $data['sku'] = $this->generateSKU('EQP');
        $data['qr_code'] = $this->generateQRCode($data['sku']);

        if (!empty($data['receipt'])) {
            foreach ($data['receipt'] as $file) {
                $path = $file->store('EquipmentInventory/' . $data['sku'] . '/receipts', 'public');
                $files[] = '/storage/' . $path;
            }
        }
        $data['receipt'] = json_encode($files);


        if (!empty($data['image'])) {
            $path = $data['image']->store('EquipmentInventory/' . $data['sku'] . '/images', 'public');
            $data['image'] = '/storage/' . $path;
        }


        return EquipmentInventory::create($data);
    }

    public function update(array $data, int $id): EquipmentInventory
    {
        $equipment = EquipmentInventory::findOrFail($id);

        $sku =  $equipment->sku;

        $existingReceipts = json_decode($equipment->receipt, true) ?? [];
        $newReceipts = [];

        if (!empty($data['receipt'])) {
            foreach ($data['receipt'] as $file) {
                if ($file instanceof \Illuminate\Http\UploadedFile) {
                    $path = $file->store("EquipmentInventory/{$sku}/receipts", 'public');
                    $newReceipts[] = '/storage/' . $path;
                }
            }
        }

        $data['receipt'] = json_encode(array_merge($existingReceipts, $newReceipts));
        if (!empty($data['image']) && $data['image'] instanceof \Illuminate\Http\UploadedFile) {
            $path = $data['image']->store("EquipmentInventory/{$sku}/images", 'public');
            $data['image'] = '/storage/' . $path;
        } else {
            $data['image'] = $equipment->image;
        }

        if (!empty($data['qr_code']) && $data['qr_code'] instanceof \Illuminate\Http\UploadedFile) {
            $path = $data['qr_code']->store("EquipmentInventory/{$sku}/QRCodes", 'public');
            $data['qr_code'] = '/storage/' . $path;
        } else {
            $data['qr_code'] = $equipment->qr_code;
        }

        $data = array_filter($data, fn($key) => in_array($key, $equipment->getFillable()), ARRAY_FILTER_USE_KEY);
        $equipment->update($data);

        return $equipment;
    }

    public function delete(int $id): bool
    {
        $equipmentInventory = EquipmentInventory::find($id);

        if (!$equipmentInventory) {
            return false;
        }

        return $equipmentInventory->delete();
    }

    protected function generateQRCode(string $sku): string
    {
        $filename = $sku . '.png';
        $folder = "EquipmentInventory/" . $sku . "/QRCodes";

        $result = Builder::create()
            ->writer(new PngWriter())
            ->data($sku)
            ->size(300)
            ->margin(10)
            ->build();

        Storage::disk('public')->put(
            "{$folder}/{$filename}",
            $result->getString()
        );

        return "/storage/{$folder}/{$filename}";
    }


    protected function generateSKU(string $prefix): string
    {
        $last = EquipmentInventory::where('sku', 'like', $prefix . '-%')
            ->orderBy('id', 'desc')
            ->first();

        if ($last) {
            $number = (int) substr($last->sku, strlen($prefix) + 1);
            $nextNumber = $number + 1;
        } else {
            $nextNumber = 1;
        }

        return sprintf('%s-%04d', $prefix, $nextNumber);
    }
}
