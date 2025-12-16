<?php

namespace App\Services;

use App\Models\Order;
use Illuminate\Database\Eloquent\Collection;


class OrderService
{
    public function getAll(): Collection
    {
        return Order::all();
    }

    public function find(int $id): ?Order
    {
        return Order::find($id);
    }

	public function create(array $data): Order
    {
        return Order::create($data);
    }

    public function update(
        Order $order,
        array $data
    ): Order {
        $order->update($data);
        return $order;
    }

    public function delete(Order $order): bool
    {
        return $order->delete();
    }
}