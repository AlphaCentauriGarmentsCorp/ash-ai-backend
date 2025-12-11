<?php

namespace App\Services;

use App\Models\Design;
use Illuminate\Database\Eloquent\Collection;

class DesignService
{
    /**
     * Get all fabric types.
     */
    public function getAll(): Collection
    {
        return Design::all();
    }

    /**
     * Find a fabric type by ID.
     */
    public function find(int $id): ?Design
    {
        return Design::find($id);
    }

    /**
     * Create a new fabric type.
     */
    public function create(array $data): Design
    {
        return Design::create($data);
    }

    /**
     * Update an existing fabric type.
     */
    public function update(int $id, array $data): ?Design
    {
        $design = Design::find($id);

        if (! $design) {
            return null;
        }

        $design->update($data);

        return $design;
    }

    /**
     * Delete a fabric type.
     */
    public function delete(int $id): bool
    {
        $design = Design::find($id);

        if (! $design) {
            return false;
        }

        return $design->delete();
    }
}
