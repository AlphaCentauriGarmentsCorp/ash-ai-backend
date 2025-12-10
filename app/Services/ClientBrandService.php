<?php

namespace App\Services;

use App\Models\ClientBrand;
use Illuminate\Database\Eloquent\Collection;

class ClientBrandService
{
    /**
     * Get all Client Brand.
     */
    public function getAll(): Collection
    {
        return ClientBrand::all();
    }

    /**
     * Find a Client Brand. by ID.
     */
    public function find(int $id): ?ClientBrand
    {
        return ClientBrand::find($id);
    }

    /**
     * Create a new Client Brand..
     */
    public function create(array $data): ClientBrand
    {
        return ClientBrand::create($data);
    }

    /**
     * Update an existing Client Brand..
     */
    public function update(int $id, array $data): ? ClientBrand
    {
        $clientBrand = ClientBrand::find($id);

        if (! $clientBrand) {
            return null;
        }

        $clientBrand->update($data);

        return $clientBrand;
    }

    /**
     * Delete a Client Brand..
     */
    public function delete(int $id): bool
    {
        $clientBrand = ClientBrand::find($id);

        if (! $clientBrand) {
            return false;
        }

        return $clientBrand->delete();
    }
}
