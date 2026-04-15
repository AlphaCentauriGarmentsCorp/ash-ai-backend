<?php

namespace App\Services;

use App\Models\ShippingMethod;
use Illuminate\Database\Eloquent\Collection;

class ShippingMethodService
{

    public function getAll()
    {
        return ShippingMethod::with('courier')->get();
    }

    public function find(int $id): ?ShippingMethod
    {
        return ShippingMethod::find($id);
    }

    public function create(array $data): ShippingMethod
    {
        return ShippingMethod::create($data);
    }

    public function update(array $data, int $id): ?ShippingMethod
    {
        $shippingmethod = ShippingMethod::find($id);

        if (!$shippingmethod) {
            return null;
        }

        $shippingmethod->update($data);
        return $shippingmethod;
    }

    public function delete(int $id): bool
    {
        $shippingmethod = ShippingMethod::find($id);

        if (!$shippingmethod) {
            return false;
        }

        return $shippingmethod->delete();
    }
}