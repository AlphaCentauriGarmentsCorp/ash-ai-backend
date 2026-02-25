<?php

namespace App\Services;

use App\Models\Client;
use App\Models\ClientBrand;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

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
                $data['barangay'],
                $data['city'],
                $data['province'],
                $data['postal_code'],
            ]);

            $client = Client::create([
                'name'           => $fullName,
                'email'          => $data['email'],
                'contact_number' => $data['contact_number'],
                'address'        => $address,
                'method'         => $data['method'],
                'courier'        => $data['courier'],
                'notes'          => $data['notes'] ?? null,
            ]);

            foreach ($data['brands'] as $brand) {

                $logoPath = null;

                if (isset($brand['logo']) && $brand['logo'] instanceof \Illuminate\Http\UploadedFile) {
                    $originalName = pathinfo(
                        $brand['logo']->getClientOriginalName(),
                        PATHINFO_FILENAME
                    );
                    $originalName = Str::slug($originalName);
                    $extension = $brand['logo']->getClientOriginalExtension();
                    $fileName = time() . '_' . $originalName . '.' . $extension;
                    $brand['logo']->storeAs('client_brands', $fileName, 'public');
                    $logoPath = '/storage/client_brands/' . $fileName;
                }

                ClientBrand::create([
                    'client_id'  => $client->id,
                    'brand_name' => $brand['name'],
                    'logo_url'   => $logoPath,
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

        if (!$client) {
            return null;
        }

        return DB::transaction(function () use ($client, $data) {

            if (isset($data['first_name']) && isset($data['last_name'])) {
                $data['name'] = $data['first_name'] . ' ' . $data['last_name'];
            }

            $addressFields = ['street_address', 'barangay', 'city', 'province', 'postal_code'];
            if (collect($addressFields)->some(fn($field) => isset($data[$field]))) {
                $existing = explode(', ', $client->address);
                $data['address'] = implode(', ', [
                    $data['street_address'] ?? $existing[0] ?? '',
                    $data['barangay'] ?? $existing[1] ?? '',
                    $data['city'] ?? $existing[2] ?? '',
                    $data['province'] ?? $existing[3] ?? '',
                    $data['postal_code'] ?? $existing[4] ?? '',
                ]);
            }

            $clientData = collect($data)->except([
                'first_name', 'last_name', 'street_address', 'barangay',
                'city', 'province', 'postal_code', 'brands'
            ])->toArray();

            $client->update($clientData);

            if (isset($data['brands'])) {
                $submittedBrandIds = collect($data['brands'])->pluck('id')->filter()->toArray();

                $brandsToDelete = ClientBrand::where('client_id', $client->id)
                    ->whereNotIn('id', $submittedBrandIds)
                    ->get();

                foreach ($brandsToDelete as $brand) {
                        if ($brand->logo_url) {
                            $relativePath = str_replace('/storage/', '', $brand->logo_url);
                            Storage::disk('public')->delete($relativePath);
                        }
                        $brand->delete();
                }

                foreach ($data['brands'] as $brand) {
                    $brandId = $brand['id'] ?? null;
                    $logoUrl = null;

                    if (isset($brand['logo']) && $brand['logo'] instanceof \Illuminate\Http\UploadedFile) {
                        if ($brandId) {
                            $existingBrand = ClientBrand::find($brandId);
                            if ($existingBrand && $existingBrand->logo_url) {
                                $relativePath = str_replace('/storage/', '', $existingBrand->logo_url);
                                Storage::disk('public')->delete($relativePath);
                            }
                        }

                        $logoPath = $brand['logo']->store('client_brands', 'public');
                        $logoUrl = '/storage/' . $logoPath;
                    }

                    if ($brandId) {
                        $brandData = ['brand_name' => $brand['name']];
                        if ($logoUrl) {
                            $brandData['logo_url'] = $logoUrl;
                        }
                        
                        ClientBrand::updateOrCreate(
                            ['id' => $brandId, 'client_id' => $client->id],
                            $brandData
                        );
                    } else {
                        ClientBrand::create([
                            'client_id' => $client->id,
                            'brand_name' => $brand['name'],
                            'logo_url' => $logoUrl,
                        ]);
                    }
                }
            }

            return $client->fresh('brands');
        });
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
