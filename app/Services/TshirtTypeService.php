<?php

namespace App\Services;


use App\Models\TshirtTypes;
use Illuminate\Database\Eloquent\Collection;

class TshirtTypeService
{
    public function getAll(): Collection
    {
        return TshirtTypes::all();
    }

    public function find(int $id): ?TshirtTypes
    {
        return TshirtTypes::find($id);
    }

    public function create(array $data): TshirtTypes
    {
        return TshirtTypes::create($data);
    }

    public function update(array $data, int $id): ?TshirtTypes
    {
        $TshirtTypes = TshirtTypes::find($id);

        if (!$TshirtTypes) {
            return null;
        }

        $TshirtTypes->update($data);
        return $TshirtTypes;
    }

    public function delete(int $id): bool
    {
        $TshirtTypes = TshirtTypes::find($id);

        if (!$TshirtTypes) {
            return false;
        }

        return $TshirtTypes->delete();
    }
}
