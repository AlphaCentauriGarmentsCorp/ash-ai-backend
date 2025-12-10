<?php

namespace App\Services;

use App\Models\TypeSize;
use Illuminate\Database\Eloquent\Collection;

class TypeSizeService
{
    /**
     * Get all types.
     */
    public function getAll(): Collection
    {
        return TypeSize::all();
    }

    /**
     * Find type by ID.
     */
    public function find(int $id): ?TypeSize
    {
        return TypeSize::find($id);
    }

    /**
     * Create a new type.
     */
    public function create(array $data): TypeSize
    {
        return TypeSize::create($data);
    }

    /**
     * Update an existing type.
     */
    public function update(int $id, array $data): ?TypeSize
    {
        $typeSize = TypeSize::find($id);

        if (! $typeSize) {
            return null;
        }

        $typeSize->update($data);

        return $typeSize;
    }

    /**
     * Delete a type.
     */
    public function delete(int $id): bool
    {
        $typeSize = TypeSize::find($id);

        if (! $typeSize) {
            return false;
        }

        return $typeSize->delete();
    }
}
