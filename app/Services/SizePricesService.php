<?php

namespace App\Services;



use App\Models\SizePrices;
use Illuminate\Database\Eloquent\Collection;

class SizePricesService
{
    public function getAll(): Collection
    {
        return SizePrices::with(['shirt', 'size'])->get();
    }

    public function find(int $id): ?SizePrices
    {
        return SizePrices::with(['shirt', 'size'])->find($id);
    }

    public function create(array $data): SizePrices
    {
        return SizePrices::create($data);
    }

    public function update(array $data, int $id): ?SizePrices
    {
        $SizePrices = SizePrices::find($id);

        if (!$SizePrices) {
            return null;
        }

        $SizePrices->update($data);
        return $SizePrices;
    }

    public function delete(int $id): bool
    {
        $SizePrices = SizePrices::find($id);

        if (!$SizePrices) {
            return false;
        }

        return $SizePrices->delete();
    }
}
