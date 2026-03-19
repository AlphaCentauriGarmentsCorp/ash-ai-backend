<?php

namespace App\Services;

use App\Models\ScreenMaintenance;
use Illuminate\Database\Eloquent\Collection;

class ScreenMaintenanceService
{
    public function getAll(): Collection
    {
        return ScreenMaintenance::with(['screen', 'employee'])->get();
    }

    public function find(int $id): ?ScreenMaintenance
    {
        return ScreenMaintenance::with(['screen', 'employee'])->find($id);
    }

    public function getByUserId($id): Collection
    {
        return ScreenMaintenance::with(['screen', 'employee'])->where('assigned_to', $id)->get();
    }

    public function create(array $data): ScreenMaintenance
    {
        // Auto-set timestamps based on status
        $data = $this->prepareTimestamps($data);

        return ScreenMaintenance::create($data);
    }

    public function update(array $data, int $id): ?ScreenMaintenance
    {
        $maintenance = ScreenMaintenance::find($id);

        if (!$maintenance) {
            return null;
        }

        // Auto-set timestamps based on status changes
        $data = $this->prepareTimestamps($data, $maintenance);

        $maintenance->update($data);

        return $maintenance;
    }

    public function delete(int $id): bool
    {
        $maintenance = ScreenMaintenance::find($id);

        if (!$maintenance) {
            return false;
        }

        return $maintenance->delete();
    }

    /**
     * Prepare timestamp data based on maintenance status
     */
    private function prepareTimestamps(array $data, ?ScreenMaintenance $existing = null): array
    {
        $status = $data['status'] ?? ($existing?->status ?? null);
        $hasStartInput = array_key_exists('start_timestamp', $data);
        $hasEndInput = array_key_exists('end_timestamp', $data);

        // Pending: clear timestamps
        if ($status === 'Pending') {
            $data['start_timestamp'] = null;
            $data['end_timestamp'] = null;
        }
        // In Progress: auto-set start if not provided
        elseif ($status === 'In Progress') {
            if (empty($data['start_timestamp']) && (!$existing || !$existing->start_timestamp)) {
                $data['start_timestamp'] = now();
            }
            $data['end_timestamp'] = null;
        }
        // Completed: allow backfilled timestamps, auto-fill only when missing
        elseif ($status === 'Completed') {
            if (!$hasStartInput || empty($data['start_timestamp'])) {
                if ($existing && $existing->start_timestamp) {
                    // keep existing start timestamp
                } elseif ($hasEndInput && !empty($data['end_timestamp'])) {
                    $data['start_timestamp'] = $data['end_timestamp'];
                } else {
                    $data['start_timestamp'] = now();
                }
            }

            if (!$hasEndInput || empty($data['end_timestamp'])) {
                $data['end_timestamp'] = now();
            }
        }

        return $data;
    }
}
