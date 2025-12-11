<?php

namespace App\Services;

use App\Models\Order;
use Illuminate\Database\Eloquent\Collection;

class OrderService
{
    /**
     * Get all types.
     */
    public function getAll(): Collection
    {
        return Order::all();
    }

    /**
     * Find type by ID.
     */
    public function find(int $id): ?Order
    {
        return Order::find($id);
    }

    /**
     * Create a new type.
     */
    public function create(array $data): Order
    {
        return Order::create($data);
    }

    /**
     * Update an existing type.
     */
    public function update(int $id, array $data): ?Order
    {
        $order = Order::find($id);

        if (! $order) {
            return null;
        }

        $order->update($data);

        return $order;
    }

    /**
     * Delete a type.
     */
    public function delete(int $id): bool
    {
        $order = Order::find($id);

        if (! $order) {
            return false;
        }

        return $order->delete();
    }
}
