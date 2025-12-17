<?php

namespace App\Services;

use App\Models\PoStatus;
use Illuminate\Database\Eloquent\Collection;

class PoStatusService
{
    /**
     * Get all PO Status records.
     */
    public function getAll(): Collection
    {
        return PoStatus::all();
    }

    /**
     * Find a PO Status by ID.
     */
    public function find(int $id): ?PoStatus
    {
        return PoStatus::find($id);
    }

    /**
     * Create a new PO Status.
     */
    public function create(array $data): PoStatus
    {
        return PoStatus::create($data);
    }

    /**
     * Update an existing PO Status.
     */
    public function update(int $id, array $data): ?PoStatus
    {
        $poStatus = PoStatus::find($id);

        if (! $poStatus) {
            return null;
        }

        $poStatus->update($data);

        return $poStatus;
    }

    /**
     * Delete a PO Status.
     */
    public function delete(int $id): bool
    {
        $poStatus = PoStatus::find($id);

        if (! $poStatus) {
            return false;
        }

        return $poStatus->delete();
    }
}