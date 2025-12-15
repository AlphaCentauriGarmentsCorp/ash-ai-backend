<?php

namespace App\Services;

use App\Models\WarehouseMaterials;
use Illuminate\Database\Eloquent\Collection;


class WarehouseMaterialsService
{
    public function getAll(): Collection
    {
        return WarehouseMaterials::all();
    }

    public function find(int $id): ?WarehouseMaterials
    {
        return WarehouseMaterials::find($id);
    }

	public function create(array $data): WarehouseMaterials
    {
        return WarehouseMaterials::create($data);
    }

    public function update(
        WarehouseMaterials $warehouseMaterial,
        array $data
    ): WarehouseMaterials {
        $warehouseMaterial->update($data);
        return $warehouseMaterial;
    }

    public function delete(WarehouseMaterials $warehouseMaterial): bool
    {
        return $warehouseMaterial->delete();
    }
}