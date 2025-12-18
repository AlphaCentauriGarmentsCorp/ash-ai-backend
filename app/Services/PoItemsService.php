<?php

namespace App\Services;

use App\Models\PoItems;
use Illuminate\Database\Eloquent\Collection;
use App\Services\PoItemsService;

class PoItemsService
{
    /**
     * Get all PO Items records.
     */
    public function getAll(): Collection
    {
        return PoItems::all();
    }

    /**
     * Find a PO Item by ID.
     */
    public function find(int $id): ?PoItems
    {
        return PoItems::find($id);
    }

    /**
     * Create a new PO Item.
     */
    public function create(array $data): PoItems
    {
        return PoItems::create($data);
    }

    /**
     * Update an existing PO Item.
     */
    public function update(int $id, array $data): ?PoItems
    {
        $poItem = PoItems::find($id);

        if (! $poItem) {
            return null;
        }

        $poItem->update($data);

        return $poItem;
    }

    /**
     * Delete a PO Item.
     */
    public function delete(int $id): bool
    {
        $poItem = PoItems::find($id);

        if (! $poItem) {
            return false;
        }

        return $poItem->delete();
    }
}