<?php

namespace App\Services;

use App\Models\PricingSetting;
use Illuminate\Database\Eloquent\Collection;

/**
 * Manages the Superadmin-editable pricing rates.
 *
 * Rates are seeded with fixed keys (the engine looks them up by key), so the
 * UI only ever LISTS and UPDATES values — it does not create or delete keys.
 * That keeps the engine from ever missing a key it depends on.
 */
class PricingSettingService
{
    public function getAll(): Collection
    {
        return PricingSetting::orderBy('group')->orderBy('id')->get();
    }

    public function find(int $id): ?PricingSetting
    {
        return PricingSetting::find($id);
    }

    /**
     * Update a single rate's value (the only field a Superadmin edits).
     */
    public function update(array $data, int $id): ?PricingSetting
    {
        $setting = PricingSetting::find($id);

        if (! $setting) {
            return null;
        }

        $setting->update(['value' => $data['value']]);

        return $setting;
    }
}
