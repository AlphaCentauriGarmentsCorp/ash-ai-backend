<?php

namespace App\Services;

use App\Models\PatternType;
use Illuminate\Database\Eloquent\Collection;

class PatternTypeService
{

    public function getAll(): Collection
    {
        return PatternType::all();
    }

    public function find(int $id): ?PatternType
    {
        return PatternType::find($id);
    }

    public function create(array $data): PatternType
    {
        return PatternType::create($data);
    }

    public function update(array $data, int $id): ?PatternType
    {
        $patternType = PatternType::find($id);

        if (!$patternType) {
            return null;
        }

        $patternType->update($data);
        return $patternType;
    }

    public function delete(int $id): bool
    {
        $patternType = PatternType::find($id);

        if (!$patternType) {
            return false;
        }

        return $patternType->delete();
    }
}
