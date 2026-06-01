<?php

namespace App\Services;

use App\Models\SpecialPrint;
use Illuminate\Database\Eloquent\Collection;

class SpecialPrintService
{
    public function getAll(): Collection
    {
        return SpecialPrint::all();
    }

    public function find(int $id): ?SpecialPrint
    {
        return SpecialPrint::find($id);
    }

    public function create(array $data): SpecialPrint
    {
        return SpecialPrint::create($data);
    }

    public function update(array $data, int $id): ?SpecialPrint
    {
        $specialPrint = SpecialPrint::find($id);

        if (! $specialPrint) {
            return null;
        }

        $specialPrint->update($data);
        return $specialPrint;
    }

    public function delete(int $id): bool
    {
        $specialPrint = SpecialPrint::find($id);

        if (! $specialPrint) {
            return false;
        }

        return $specialPrint->delete();
    }
}
