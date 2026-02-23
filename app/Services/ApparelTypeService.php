<?php

namespace App\Services;

use App\Models\ApparelType;
use Illuminate\Database\Eloquent\Collection;

class ApparelTypeService
{

    public function getAll(): Collection
    {
        return ApparelType::all();
    }

    public function find(int $id): ?ApparelType
    {
        return ApparelType::find($id);
    }

    public function create(array $data): ApparelType
    {
        return ApparelType::create($data);
    }

    public function update(array $data, int $id): ?ApparelType
    {
        $apparelType = ApparelType::find($id);

        if (!$apparelType) {
            return null;
        }

        $apparelType->update($data);
        return $apparelType;
    }

    public function delete(int $id): bool
    {
        $apparelType = ApparelType::find($id);

        if (!$apparelType) {
            return false;
        }

        return $apparelType->delete();
    }
}
