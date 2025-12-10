<?php

namespace App\Services;

use App\Models\Client;
use Illuminate\Database\Eloquent\Collection;

class ClientService
{
    /**
     * Get all Client Brand.
     */
    public function getAll(): Collection
    {
        return Client::all();
    }

    /**
     * Find a Client Brand. by ID.
     */
    public function find(int $id): ?Client
    {
        return Client::find($id);
    }

    /**
     * Create a new Client Brand..
     */
    public function create(array $data): Client
    {
        return Client::create($data);
    }

    /**
     * Update an existing Client Brand..
     */
    public function update(int $id, array $data): ? Client
    {
        $client = Client::find($id);

        if (! $client) {
            return null;
        }

        $client->update($data);

        return $client;
    }

    /**
     * Delete a Client Brand..
     */
    public function delete(int $id): bool
    {
        $client = Client::find($id);

        if (! $client) {
            return false;
        }

        return $client->delete();
    }
}
