<?php

namespace App\Services;

use App\Models\Freebie;
use Illuminate\Database\Eloquent\Collection;

class FreebieService
{

    public function getAll(): Collection
    {
        return Freebie::all();
    }

    public function find(int $id): ?Freebie
    {
        return Freebie::find($id);
    }

    public function create(array $data): Freebie
    {
        return Freebie::create($data);
    }

    public function update(array $data, int $id): ?Freebie
    {
        $freebie = Freebie::find($id);

        if (!$freebie) {
            return null;
        }

        $freebie->update($data);
        return $freebie;
    }

    public function delete(int $id): bool
    {
        $freebie = Freebie::find($id);

        if (!$freebie) {
            return false;
        }

        return $freebie->delete();
    }
}