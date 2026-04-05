<?php

namespace App\Services;


use App\Models\TshirtSize;
use Illuminate\Database\Eloquent\Collection;

class TshirtSizeService
{
    public function getAll(): Collection
    {
        return TshirtSize::all();
    }

    public function find(int $id): ?TshirtSize
    {
        return TshirtSize::find($id);
    }

    public function create(array $data): TshirtSize
    {
        return TshirtSize::create($data);
    }

    public function update(array $data, int $id): ?TshirtSize
    {
        $TshirtSize = TshirtSize::find($id);

        if (!$TshirtSize) {
            return null;
        }

        $TshirtSize->update($data);
        return $TshirtSize;
    }

    public function delete(int $id): bool
    {
        $TshirtSize = TshirtSize::find($id);

        if (!$TshirtSize) {
            return false;
        }

        return $TshirtSize->delete();
    }
}
