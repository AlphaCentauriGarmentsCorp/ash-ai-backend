<?php

namespace App\Services;

use App\Models\ScreenMaintenanceLogs;
use Illuminate\Database\Eloquent\Collection;

class ScreenMaintenanceLogsService
{
    public function getAll(): Collection
    {
        return ScreenMaintenanceLogs::with(['screen', 'employee'])->get();
    }

    public function find(int $id): ?ScreenMaintenanceLogs
    {
        return ScreenMaintenanceLogs::with(['screen', 'employee'])->find($id);
    }

    public function getByScreenId(int $id): Collection
    {
        return ScreenMaintenanceLogs::with(['screen', 'employee'])
            ->where('screen_id', $id)
            ->latest('created_at')
            ->get();
    }

    public function create(array $data): ScreenMaintenanceLogs
    {
        $maintenanceLog = ScreenMaintenanceLogs::create($data);

        return $maintenanceLog->load(['screen', 'employee']);
    }

    public function update(array $data, int $id): ?ScreenMaintenanceLogs
    {
        $maintenance = ScreenMaintenanceLogs::find($id);

        if (!$maintenance) {
            return null;
        }

        $maintenance->update($data);

        return $maintenance->load(['screen', 'employee']);
    }

    public function delete(int $id): bool
    {
        $maintenance = ScreenMaintenanceLogs::find($id);

        if (!$maintenance) {
            return false;
        }

        return $maintenance->delete();
    }
}
