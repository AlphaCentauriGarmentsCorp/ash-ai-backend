<?php

namespace App\Services;

use App\Models\PaymentMethods;
use Illuminate\Database\Eloquent\Collection;

class PaymentMethodsService
{

    public function getAll(): Collection
    {
        return PaymentMethods::all();
    }

    public function find(int $id): ?PaymentMethods
    {
        return PaymentMethods::find($id);
    }

    public function create(array $data): PaymentMethods
    {
        return PaymentMethods::create($data);
    }

    public function update(array $data, int $id): ?PaymentMethods
    {
        $paymentmethod = PaymentMethods::find($id);

        if (!$paymentmethod) {
            return null;
        }

        $paymentmethod->update($data);
        return $paymentmethod;
    }

    public function delete(int $id): bool
    {
        $paymentmethod = PaymentMethods::find($id);

        if (!$paymentmethod) {
            return false;
        }

        return $paymentmethod->delete();
    }
}
