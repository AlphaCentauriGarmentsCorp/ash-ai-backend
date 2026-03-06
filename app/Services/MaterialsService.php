<?php

namespace App\Services;

use App\Models\Materials;
use Illuminate\Database\Eloquent\Collection;

class MaterialsService
{

    public function getAll(): Collection
    {
        return Materials::with('supplier')->get();
    }

    public function getBySupplier($id): Collection
    {
        return Materials::with('supplier')->where('supplier_id', $id)->get();
    }

    public function getByType($type): Collection
    {
        return Materials::with('supplier')->where('material_type', $type)->get();
    }

    public function create(array $data): Materials
    {
        return Materials::create($data);
    }

    public function delete(int $id): bool
    {
        $materials = Materials::find($id);

        if (!$materials) {
            return false;
        }

        return $materials->delete();
    }
}
