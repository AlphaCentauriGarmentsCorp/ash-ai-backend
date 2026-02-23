<?php

namespace App\Services;

use App\Models\PrintMethod;
use Illuminate\Database\Eloquent\Collection;

class PrintMethodService
{

    public function getAll(): Collection
    {
        return PrintMethod::all();
    }

    public function find(int $id): ?PrintMethod
    {
        return PrintMethod::find($id);
    }

    public function create(array $data): PrintMethod
    {
        return PrintMethod::create($data);
    }

    public function update(array $data, int $id): ?PrintMethod
    {
        $printMethod = PrintMethod::find($id);

        if (!$printMethod) {
            return null;
        }

        $printMethod->update($data);
        return $printMethod;
    }

    public function delete(int $id): bool
    {
        $printMethod = PrintMethod::find($id);

        if (!$printMethod) {
            return false;
        }

        return $printMethod->delete();
    }
}
