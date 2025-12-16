<?php

namespace App\Services;

use App\Models\OrdersPayment;
use Illuminate\Database\Eloquent\Collection;


class OrdersPaymentService
{
    public function getAll(): Collection
    {
        return OrdersPayment::all();
    }

    public function find(int $id): ?OrdersPayment
    {
        return OrdersPayment::find($id);
    }

	public function create(array $data): OrdersPayment
    {
        return OrdersPayment::create($data);
    }

    public function update(
        OrdersPayment $payment,
        array $data
    ): OrdersPayment {
        $payment->update($data);
        return $payment;
    }

    public function delete(OrdersPayment $payment): bool
    {
        return $payment->delete();
    }
}