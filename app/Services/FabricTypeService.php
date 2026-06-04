<?php

namespace App\Services;

use App\Models\FabricType;
use Illuminate\Database\Eloquent\Collection;

class FabricTypeService
{
    public function getAll(): Collection
    {
        return FabricType::orderBy('name')->get();
    }

    public function find(int $id): ?FabricType
    {
        return FabricType::find($id);
    }

    public function create(array $data): FabricType
    {
        return FabricType::create($data);
    }

    public function update(array $data, int $id): ?FabricType
    {
        $fabricType = FabricType::find($id);

        if (! $fabricType) {
            return null;
        }

        $fabricType->update($data);
        return $fabricType;
    }

    public function delete(int $id): bool
    {
        $fabricType = FabricType::find($id);

        if (! $fabricType) {
            return false;
        }

        return $fabricType->delete();
    }
}
