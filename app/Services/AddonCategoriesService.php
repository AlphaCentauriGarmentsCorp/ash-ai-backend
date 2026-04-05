<?php

namespace App\Services;


use App\Models\AddonCategories;
use Illuminate\Database\Eloquent\Collection;

class AddonCategoriesService
{
    public function getAll(): Collection
    {
        return AddonCategories::all();
    }

    public function find(int $id): ?AddonCategories
    {
        return AddonCategories::find($id);
    }

    public function create(array $data): AddonCategories
    {
        return AddonCategories::create($data);
    }

    public function update(array $data, int $id): ?AddonCategories
    {
        $AddonCategories = AddonCategories::find($id);

        if (!$AddonCategories) {
            return null;
        }

        $AddonCategories->update($data);
        return $AddonCategories;
    }

    public function delete(int $id): bool
    {
        $AddonCategories = AddonCategories::find($id);

        if (!$AddonCategories) {
            return false;
        }

        return $AddonCategories->delete();
    }
}
