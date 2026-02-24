<?php

namespace App\Services;

use App\Models\AdditionalOption;
use Illuminate\Database\Eloquent\Collection;

class AdditionalOptionService
{

    public function getAll(): Collection
    {
        return AdditionalOption::all();
    }

    public function find(int $id): ?AdditionalOption
    {
        return AdditionalOption::find($id);
    }

    public function create(array $data): AdditionalOption
    {
        return AdditionalOption::create($data);
    }

    public function update(array $data, int $id): ?AdditionalOption
    {
        $additionalOption = AdditionalOption::find($id);

        if (!$additionalOption) {
            return null;
        }

        $additionalOption->update($data);
        return $additionalOption;
    }

    public function delete(int $id): bool
    {
        $additionalOption = AdditionalOption::find($id);

        if (!$additionalOption) {
            return false;
        }

        return $additionalOption->delete();
    }
}