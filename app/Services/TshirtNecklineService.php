<?php

namespace App\Services;


use App\Models\TshirtNecklines;
use Illuminate\Database\Eloquent\Collection;

class TshirtNecklineService
{
    public function getAll(): Collection
    {
        return TshirtNecklines::all();
    }

    public function find(int $id): ?TshirtNecklines
    {
        return TshirtNecklines::find($id);
    }

    public function create(array $data): TshirtNecklines
    {
        return TshirtNecklines::create($data);
    }

    public function update(array $data, int $id): ?TshirtNecklines
    {
        $TshirtNecklines = TshirtNecklines::find($id);

        if (!$TshirtNecklines) {
            return null;
        }

        $TshirtNecklines->update($data);
        return $TshirtNecklines;
    }

    public function delete(int $id): bool
    {
        $TshirtNecklines = TshirtNecklines::find($id);

        if (!$TshirtNecklines) {
            return false;
        }

        return $TshirtNecklines->delete();
    }
}
