<?php

namespace App\Services;

use App\Models\OrderProcesses;
use Illuminate\Database\Eloquent\Collection;


class OrderProcessesService
{
    public function getAll(): Collection
    {
        return OrderProcesses::all();
    }

    public function find(int $id): ?OrderProcesses
    {
        return OrderProcesses::find($id);
    }

	public function create(array $data): OrderProcesses
    {
        return OrderProcesses::create($data);
    }

    public function update(
        OrderProcesses $process,
        array $data
    ): OrderProcesses {
        $process->update($data);
        return $process;
    }

    public function delete(OrderProcesses $process): bool
    {
        return $process->delete();
    }
}