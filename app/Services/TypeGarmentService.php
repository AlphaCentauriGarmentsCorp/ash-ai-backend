<?php

namespace App\Services;

use App\Models\TypeGarment;
use Illuminate\Database\Eloquent\Collection;


class TypeGarmentService
{
    public function getAll(): Collection
    {
        return TypeGarment::all();
    }

    public function find(int $id): ?TypeGarment
    {
        return TypeGarment::find($id);
    }

	public function create(array $data): TypeGarment
    {
        return TypeGarment::create($data);
    }

    public function update(
        TypeGarment $typeGarment,
        array $data
    ): TypeGarment {
        $typeGarment->update($data);
        return $typeGarment;
    }

    public function delete(TypeGarment $typeGarment): bool
    {
        return $typeGarment->delete();
    }
}