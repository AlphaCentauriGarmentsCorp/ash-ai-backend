<?php

namespace App\Services;

use App\Models\PrintLabelPlacement;
use Illuminate\Database\Eloquent\Collection;

class PrintLabelPlacementService
{

    public function getAll(): Collection
    {
        return PrintLabelPlacement::all();
    }

    public function find(int $id): ?PrintLabelPlacement
    {
        return PrintLabelPlacement::find($id);
    }

    public function create(array $data): PrintLabelPlacement
    {
        return PrintLabelPlacement::create($data);
    }

    public function update(array $data, int $id): ?PrintLabelPlacement
    {
        $printLabelPlacement = PrintLabelPlacement::find($id);

        if (!$printLabelPlacement) {
            return null;
        }

        $printLabelPlacement->update($data);
        return $printLabelPlacement;
    }

    public function delete(int $id): bool
    {
        $printLabelPlacement = PrintLabelPlacement::find($id);

        if (!$printLabelPlacement) {
            return false;
        }

        return $printLabelPlacement->delete();
    }
}
