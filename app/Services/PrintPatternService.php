<?php

namespace App\Services;

use App\Models\PrintPattern;
use Illuminate\Database\Eloquent\Collection;

class PrintPatternService
{
    public function getAll(): Collection
    {
        return PrintPattern::all();
    }

    public function find(int $id): ?PrintPattern
    {
        return PrintPattern::find($id);
    }

    public function create(array $data): PrintPattern
    {
        return PrintPattern::create($data);
    }

    public function update(array $data, int $id): ?PrintPattern
    {
        $PrintPattern = PrintPattern::find($id);

        if (!$PrintPattern) {
            return null;
        }

        $PrintPattern->update($data);
        return $PrintPattern;
    }

    public function delete(int $id): bool
    {
        $PrintPattern = PrintPattern::find($id);

        if (!$PrintPattern) {
            return false;
        }

        return $PrintPattern->delete();
    }
}
