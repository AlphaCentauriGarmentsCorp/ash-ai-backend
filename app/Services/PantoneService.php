<?php

namespace App\Services;

use App\Models\Pantone;
use Illuminate\Database\Eloquent\Collection;

class PantoneService
{

    public function getAll(): Collection
    {
        return Pantone::all();
    }

    public function find(int $id): ?Pantone
    {
        return Pantone::find($id);
    }

    public function create(array $data): Pantone
    {
        return Pantone::create($data);
    }

    public function update(array $data, int $id): ?Pantone
    {
        $pantone = Pantone::find($id);

        if (!$pantone) {
            return null;
        }

        $pantone->update($data);
        return $pantone;
    }

    public function delete(int $id): bool
    {
        $pantone = Pantone::find($id);

        if (!$pantone) {
            return false;
        }

        return $pantone->delete();
    }
}