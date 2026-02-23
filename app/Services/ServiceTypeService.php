<?php

namespace App\Services;

use App\Models\ServiceType;
use Illuminate\Database\Eloquent\Collection;

class ServiceTypeService
{

    public function getAll(): Collection
    {
        return ServiceType::all();
    }

    public function find(int $id): ?ServiceType
    {
        return ServiceType::find($id);
    }

    public function create(array $data): ServiceType
    {
        return ServiceType::create($data);
    }

    public function update(array $data, int $id): ?ServiceType
    {
        $serviceType = ServiceType::find($id);

        if (!$serviceType) {
            return null;
        }

        $serviceType->update($data);
        return $serviceType;
    }

    public function delete(int $id): bool
    {
        $serviceType = ServiceType::find($id);

        if (!$serviceType) {
            return false;
        }

        return $serviceType->delete();
    }
}
