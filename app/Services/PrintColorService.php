<?php

namespace App\Services;


use App\Models\PrintColors;
use Illuminate\Database\Eloquent\Collection;

class PrintColorService
{
    public function getAll(): Collection
    {
        return PrintColors::with('printType')->get();
    }

    public function find(int $id): ?PrintColors
    {
        return PrintColors::with('printType')->find($id);
    }

    public function create(array $data): PrintColors
    {
        return PrintColors::create($data);
    }

    public function update(array $data, int $id): ?PrintColors
    {
        $PrintColors = PrintColors::find($id);

        if (!$PrintColors) {
            return null;
        }

        $PrintColors->update($data);
        return $PrintColors;
    }

    public function delete(int $id): bool
    {
        $PrintColors = PrintColors::find($id);

        if (!$PrintColors) {
            return false;
        }

        return $PrintColors->delete();
    }
}
