<?php

namespace App\Services;

use App\Models\SewingSubcontractor;
use Illuminate\Database\Eloquent\Collection;

class SewingSubcontractorService
{
    public function getAll(): Collection
    {
        return SewingSubcontractor::all();
    }

    public function find(int $id): ?SewingSubcontractor
    {
        return SewingSubcontractor::find($id);
    }

    public function create(array $data): SewingSubcontractor
    {
        return SewingSubcontractor::create($data);
    }

    public function update(array $data, int $id): ?SewingSubcontractor
    {
        $subcontractor = SewingSubcontractor::find($id);

        if (!$subcontractor) {
            return null;
        }

        $subcontractor->update($data);
        return $subcontractor;
    }

    public function delete(int $id): bool
    {
        $subcontractor = SewingSubcontractor::find($id);

        if (!$subcontractor) {
            return false;
        }

        return $subcontractor->delete();
    }
}
