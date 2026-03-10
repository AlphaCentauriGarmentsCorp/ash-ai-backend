<?php

namespace App\Services;

use App\Models\PrintMethod;
use App\Models\Screens;
use Illuminate\Database\Eloquent\Collection;

class ScreenServices
{
    public function getAll(): Collection
    {
        return Screens::all();
    }

    public function find(int $id): ?Screens
    {
        return Screens::find($id);
    }

    public function create(array $data): Screens
    {
        return Screens::create($data);
    }

    public function update(array $data, int $id): ?Screens
    {
        $screens = Screens::find($id);

        if (!$screens) {
            return null;
        }

        $screens->update($data);
        return $screens;
    }

    public function delete(int $id): bool
    {
        $screens = Screens::find($id);

        if (!$screens) {
            return false;
        }

        return $screens->delete();
    }
}
