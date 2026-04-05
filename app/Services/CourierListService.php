<?php

namespace App\Services;

use App\Models\CourierList;
use Illuminate\Database\Eloquent\Collection;

class CourierListService
{

    public function getAll(): Collection
    {
        return CourierList::all();
    }

    public function find(int $id): ?CourierList
    {
        return CourierList::find($id);
    }

    public function create(array $data): CourierList
    {
        return CourierList::create($data);
    }

    public function update(array $data, int $id): ?CourierList
    {
        $courierlist = CourierList::find($id);

        if (!$courierlist) {
            return null;
        }

        $courierlist->update($data);
        return $courierlist;
    }

    public function delete(int $id): bool
    {
        $courierlist = CourierList::find($id);

        if (!$courierlist) {
            return false;
        }

        return $courierlist->delete();
    }
}