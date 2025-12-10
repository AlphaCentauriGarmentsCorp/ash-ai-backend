<?php

namespace App\Services;

use App\Models\FabricType;
use Illuminate\Database\Eloquent\Collection;

class FabricTypeService
{
    /**
     * Get all fabric types.
     */
    public function getAll(): Collection
    {
        return FabricType::all();
    }

    /**
     * Find a fabric type by ID.
     */
    public function find(int $id): ?FabricType
    {
        return FabricType::find($id);
    }

    /**
     * Create a new fabric type.
     */
    public function create(array $data): FabricType
    {
        return FabricType::create($data);
    }

    /**
     * Update an existing fabric type.
     */
    public function update(int $id, array $data): ?FabricType
    {
        $fabricType = FabricType::find($id);

        if (! $fabricType) {
            return null;
        }

        $fabricType->update($data);

        return $fabricType;
    }

    /**
     * Delete a fabric type.
     */
    public function delete(int $id): bool
    {
        $fabricType = FabricType::find($id);

        if (! $fabricType) {
            return false;
        }

        return $fabricType->delete();
    }
}
