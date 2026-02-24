<?php

namespace App\Services;

use App\Models\PlacementMeasurement;
use Illuminate\Database\Eloquent\Collection;

class PlacementMeasurementService
{

    public function getAll(): Collection
    {
        return PlacementMeasurement::all();
    }

    public function find(int $id): ?PlacementMeasurement
    {
        return PlacementMeasurement::find($id);
    }

    public function create(array $data): PlacementMeasurement
    {
        return PlacementMeasurement::create($data);
    }

    public function update(array $data, int $id): ?PlacementMeasurement
    {
        $placementMeasurement = PlacementMeasurement::find($id);

        if (!$placementMeasurement) {
            return null;
        }

        $placementMeasurement->update($data);
        return $placementMeasurement;
    }

    public function delete(int $id): bool
    {
        $placementMeasurement = PlacementMeasurement::find($id);

        if (!$placementMeasurement) {
            return false;
        }

        return $placementMeasurement->delete();
    }
}