<?php

namespace App\Services;

use App\Models\ApparelPatternPrice;
use App\Models\ApparelType;
use App\Models\PatternType;
use Illuminate\Database\Eloquent\Collection;

class ApparelPatternPriceService
{
    public function getAll(): Collection
    {
        return ApparelPatternPrice::all();
    }

    public function find(int $id): ?ApparelPatternPrice
    {
        return ApparelPatternPrice::find($id);
    }

    public function findByNames(string $apparelTypeName, string $patternTypeName): ?ApparelPatternPrice
    {
        return ApparelPatternPrice::where('apparel_type_name', $apparelTypeName)
            ->where('pattern_type_name', $patternTypeName)
            ->first();
    }

    public function create(array $data): ApparelPatternPrice
    {
        // Fetch and populate names from IDs
        if (isset($data['apparel_type_id'])) {
            $apparel = ApparelType::find($data['apparel_type_id']);
            if ($apparel) {
                $data['apparel_type_name'] = $apparel->name;
            }
        }

        if (isset($data['pattern_type_id'])) {
            $pattern = PatternType::find($data['pattern_type_id']);
            if ($pattern) {
                $data['pattern_type_name'] = $pattern->name;
            }
        }

        return ApparelPatternPrice::create($data);
    }

    public function update(array $data, int $id): ?ApparelPatternPrice
    {
        $apparelPatternPrice = ApparelPatternPrice::find($id);

        if (!$apparelPatternPrice) {
            return null;
        }

        // Fetch and populate names from IDs if they are provided
        if (isset($data['apparel_type_id'])) {
            $apparel = ApparelType::find($data['apparel_type_id']);
            if ($apparel) {
                $data['apparel_type_name'] = $apparel->name;
            }
        }

        if (isset($data['pattern_type_id'])) {
            $pattern = PatternType::find($data['pattern_type_id']);
            if ($pattern) {
                $data['pattern_type_name'] = $pattern->name;
            }
        }

        $apparelPatternPrice->update($data);
        return $apparelPatternPrice;
    }

    public function delete(int $id): bool
    {
        $apparelPatternPrice = ApparelPatternPrice::find($id);

        if (!$apparelPatternPrice) {
            return false;
        }

        return $apparelPatternPrice->delete();
    }

    public function getPrice(string $apparelTypeName, string $patternTypeName): ?float
    {
        $record = $this->findByNames($apparelTypeName, $patternTypeName);
        return $record ? (float) $record->price : null;
    }
}
