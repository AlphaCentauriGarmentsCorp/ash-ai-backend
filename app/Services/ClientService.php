<?php

namespace App\Services;

use App\Models\Client;
use App\Models\ClientBrand;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

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
        return DB::transaction(function () use ($data) {

            $fullName = $data['first_name'] . ' ' . $data['last_name'];
            $address = implode(', ', [
                $data['street_address'],
                $data['city'],
                $data['province'],
                $data['postal_code'],
            ]);

            $client = Client::create([
                'name'           => $fullName,
                'email'          => $data['email'],
                'contact_number' => $data['contact_number'],
                'address'        => $address,
                'notes'          => $data['notes'] ?? null,
            ]);

            foreach ($data['brands'] as $brand) {
                $logoPath = $brand['logo']->store('client_brands', 'public');

                ClientBrand::create([
                    'client_id'  => $client->id,
                    'brand_name' => $brand['name'],
                    'logo_url'   => '/storage/' . $logoPath,
                ]);
            }

            return $client;
        });
    }

    /**
     * Update an existing Client Brand..
     */
    public function update(int $id, array $data): ?Client
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
