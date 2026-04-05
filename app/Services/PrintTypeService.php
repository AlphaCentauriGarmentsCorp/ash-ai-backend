<?php

namespace App\Services;


use App\Models\PrintTypes;
use Illuminate\Database\Eloquent\Collection;

class PrintTypeService
{
    public function getAll(): Collection
    {
        return PrintTypes::all();
    }

    public function find(int $id): ?PrintTypes
    {
        return PrintTypes::find($id);
    }

    public function create(array $data): PrintTypes
    {
        return PrintTypes::create($data);
    }

    public function update(array $data, int $id): ?PrintTypes
    {
        $PrintTypes = PrintTypes::find($id);

        if (!$PrintTypes) {
            return null;
        }

        $PrintTypes->update($data);
        return $PrintTypes;
    }

    public function delete(int $id): bool
    {
        $PrintTypes = PrintTypes::find($id);

        if (!$PrintTypes) {
            return false;
        }

        return $PrintTypes->delete();
    }
}
