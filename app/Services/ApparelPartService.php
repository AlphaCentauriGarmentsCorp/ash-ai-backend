<?php

namespace App\Services;

use App\Models\ApparelPart;
use Illuminate\Database\Eloquent\Collection;

class ApparelPartService
{
    public function getAll(): Collection
    {
        return ApparelPart::all();
    }

    public function find(int $id): ?ApparelPart
    {
        return ApparelPart::find($id);
    }

    public function create(array $data): ApparelPart
    {
        return ApparelPart::create($data);
    }

    public function update(array $data, int $id): ?ApparelPart
    {
        $apparelPart = ApparelPart::find($id);

        if (! $apparelPart) {
            return null;
        }

        $apparelPart->update($data);

        return $apparelPart;
    }

    public function delete(int $id): bool
    {
        $apparelPart = ApparelPart::find($id);

        if (! $apparelPart) {
            return false;
        }

        return $apparelPart->delete();
    }
}
