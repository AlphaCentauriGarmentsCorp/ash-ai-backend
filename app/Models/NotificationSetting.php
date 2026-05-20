<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Phase 7-B Bundle 1 — Generic key/value notification config row.
 *
 * Used initially for the QA/Packer reject-alert thresholds; reusable
 * for any future tunable notification behaviour.
 *
 * Static helpers (`getValue` / `setValue`) are intentionally minimal
 * — no caching layer in v1, since this table is hit at most a few
 * times per QA submit. If contention ever becomes a concern, wrap
 * `getValue` in Cache::remember.
 */
class NotificationSetting extends Model
{
    protected $table = 'notification_settings';

    protected $fillable = [
        'key',
        'value_json',
        'description',
    ];

    protected $casts = [
        'value_json' => 'array',
    ];

    /**
     * Read a setting by key. Returns the cast value, or $default
     * if the key isn't present.
     *
     * Note: because of the json cast, a stored scalar like `5` comes
     * back as int 5 (or whatever the underlying JSON was), and a
     * stored object/array comes back as a PHP array.
     */
    public static function getValue(string $key, mixed $default = null): mixed
    {
        $row = static::where('key', $key)->first();
        return $row?->value_json ?? $default;
    }

    /**
     * Upsert a setting by key.
     */
    public static function setValue(string $key, mixed $value, ?string $description = null): static
    {
        return static::updateOrCreate(
            ['key' => $key],
            array_filter([
                'value_json'  => $value,
                'description' => $description,
            ], fn ($v) => $v !== null),
        );
    }
}
