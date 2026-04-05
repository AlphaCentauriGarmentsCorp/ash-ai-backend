<?php

namespace App\Services;


use App\Models\Addons;
use Illuminate\Database\Eloquent\Collection;

class AddonsService
{
    public function getAll(): Collection
    {
        return Addons::with('category')->get();
    }

    public function find(int $id): ?Addons
    {
        return Addons::with('category')->find($id);
    }

    public function create(array $data): Addons
    {
        return Addons::create($data);
    }

    public function update(array $data, int $id): ?Addons
    {
        $Addons = Addons::find($id);

        if (!$Addons) {
            return null;
        }

        $Addons->update($data);
        return $Addons;
    }

    public function delete(int $id): bool
    {
        $Addons = Addons::find($id);

        if (!$Addons) {
            return false;
        }

        return $Addons->delete();
    }
}
