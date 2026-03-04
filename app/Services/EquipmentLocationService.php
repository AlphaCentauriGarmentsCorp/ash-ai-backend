<?php

namespace App\Services;

use App\Models\EquipmentLocation;
use Illuminate\Database\Eloquent\Collection;

class EquipmentLocationService
{
    public function getAll(): Collection
    {
        return EquipmentLocation::all();
    }

    public function find(int $id): ?EquipmentLocation
    {
        return EquipmentLocation::find($id);
    }

    public function create(array $data): EquipmentLocation
    {
        return EquipmentLocation::create($data);
    }

    public function update(array $data, int $id): ?EquipmentLocation
    {
        $equipmentLocation = EquipmentLocation::find($id);

        if (!$equipmentLocation) {
            return null;
        }

        $equipmentLocation->update($data);
        return $equipmentLocation;
    }

    public function delete(int $id): bool
    {
        $equipmentLocation = EquipmentLocation::find($id);

        if (!$equipmentLocation) {
            return false;
        }

        return $equipmentLocation->delete();
    }
}
