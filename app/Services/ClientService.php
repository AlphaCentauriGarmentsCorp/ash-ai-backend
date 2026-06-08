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

            $client = Client::create([
                'name'           => $fullName,
                'email'          => $data['email'],
                'contact_number' => $data['contact_number'],
                // Change 6 (option B): persist granular address columns…
                'street_address' => $data['street_address'] ?? null,
                'barangay'       => $data['barangay'] ?? null,
                'city'           => $data['city'] ?? null,
                'province'       => $data['province'] ?? null,
                'postal_code'    => $data['postal_code'] ?? null,
                // …and keep `address` as a derived single-line convenience value.
                'address'        => $this->composeAddress($data),
                'method'         => $data['method'] ?? null,
                'courier'        => $data['courier'] ?? null,
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

            // Change 6 (option B): granular address columns are now real and
            // fillable. Merge any incoming parts over the client's existing
            // values so a partial update never blanks out an untouched part,
            // then recompose the derived single-line `address`.
            $addressFields = ['street_address', 'barangay', 'city', 'province', 'postal_code'];
            if (collect($addressFields)->some(fn ($field) => array_key_exists($field, $data))) {
                $merged = [];
                foreach ($addressFields as $field) {
                    $merged[$field] = array_key_exists($field, $data)
                        ? $data[$field]
                        : $client->{$field};
                    $data[$field] = $merged[$field];
                }
                $data['address'] = $this->composeAddress($merged);
            }

            $clientData = collect($data)->except([
                'first_name', 'last_name', 'brands',
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
     * Compose the derived single-line `address` from granular parts, skipping
     * empty parts so we never produce ", , ," noise. Kept for backward
     * compatibility with readers of the legacy single `address` column.
     */
    private function composeAddress(array $parts): string
    {
        return collect([
            $parts['street_address'] ?? null,
            $parts['barangay'] ?? null,
            $parts['city'] ?? null,
            $parts['province'] ?? null,
            $parts['postal_code'] ?? null,
        ])->filter(fn ($p) => filled($p))->implode(', ');
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
