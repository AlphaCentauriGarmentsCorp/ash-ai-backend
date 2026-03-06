<?php

namespace App\Services;

use App\Models\Supplier;
use Illuminate\Database\Eloquent\Collection;

class SupplierService
{
    public function getAll(): Collection
    {
        return Supplier::all();
    }

    public function find(int $id): ?Supplier
    {
        return Supplier::with('materials')->find($id);
    }

    public function create(array $data): Supplier
    {
        $data['address'] = implode('|', [
            $data['street_address'],
            $data['barangay'],
            $data['city'],
            $data['province'],
            $data['postal_code'],
        ]);

        return Supplier::create($data);
    }

    public function update(array $data, int $id): ?Supplier
    {
        $supplier = Supplier::find($id);

        if (!$supplier) {
            return null;
        }

        $data['address'] = implode('|', [
            $data['street_address'],
            $data['barangay'],
            $data['city'],
            $data['province'],
            $data['postal_code'],
        ]);

        $supplier->update($data);
        return $supplier;
    }

    public function delete(int $id): bool
    {
        $supplier = Supplier::find($id);

        if (!$supplier) {
            return false;
        }

        return $supplier->delete();
    }
}
