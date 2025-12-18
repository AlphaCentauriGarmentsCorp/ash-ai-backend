<?php

namespace App\Services;

use App\Models\Design;
use Illuminate\Database\Eloquent\Collection;

class DesignService
{
    /**
     * Get all Design records.
     */
    public function getAll(): Collection
    {
        return Design::all();
    }

    /**
     * Find a Design by ID.
     */
    public function find(int $id): ?Design
    {
        return Design::find($id);
    }

    /**
     * Create a new Design.
     */
    public function create(array $data): Design
    {
        return Design::create($data);
    }

    /**
     * Update an existing Design.
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
     * Delete a Design.
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