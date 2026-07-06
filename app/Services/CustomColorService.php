<?php

namespace App\Services;

use App\Models\CustomColor;
use App\Models\User;
use Illuminate\Support\Facades\Schema;

/**
 * GA Portal CP1 — custom color find-or-create with de-dup on hex.
 *
 * Decision "D" ("always save to a separate custom catalog") is served
 * here without growing duplicates: a submitted color is matched against
 * the normalised hexcolor of existing rows. On a hit the existing row is
 * reused and its pick_count is bumped; on a miss a new row is inserted.
 * The canonical `pantones` catalog is never written from this path.
 *
 * Every row is stored with a canonical '#RRGGBB' upper-case hex, so the
 * de-dup lookup is a plain equality match (no DB-specific SQL function —
 * safe under both MySQL and the SQLite test driver).
 */
class CustomColorService
{
    /**
     * Normalise a hex string to canonical '#RRGGBB' upper-case form.
     * Accepts '#rgb', 'rgb', '#rrggbb', 'rrggbb'. Returns null when the
     * input cannot be parsed as a hex color.
     */
    public function normalizeHex(?string $hex): ?string
    {
        if ($hex === null) {
            return null;
        }

        $h = ltrim(trim($hex), '#');
        if (! preg_match('/^(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $h)) {
            return null;
        }

        if (strlen($h) === 3) {
            $h = $h[0] . $h[0] . $h[1] . $h[1] . $h[2] . $h[2];
        }

        return '#' . strtoupper($h);
    }

    /**
     * Find an existing custom color by normalised hex, or create one.
     * Bumps pick_count on every successful find-or-create so the picker
     * can surface "most used" customs.
     *
     * @param array{name?:string|null,hexcolor?:string|null,pantone_code?:string|null} $data
     */
    public function findOrCreate(array $data, ?User $actor = null): CustomColor
    {
        $hex = $this->normalizeHex($data['hexcolor'] ?? null);
        if ($hex === null) {
            throw new \InvalidArgumentException('A valid hexcolor is required to create a custom color.');
        }

        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            $name = $hex; // auto-name to the hex per the agreed default
        }

        $code = trim((string) ($data['pantone_code'] ?? ''));
        $code = $code !== '' ? $code : null;

        $existing = CustomColor::where('hexcolor', $hex)->first();
        if ($existing) {
            $existing->increment('pick_count');

            // Backfill a friendlier name / code onto a row that only had
            // the hex placeholder.
            $dirty = false;
            if (($existing->name === null || $existing->name === '' || $existing->name === $existing->hexcolor)
                && $name !== $hex) {
                $existing->name = $name;
                $dirty = true;
            }
            if ($existing->pantone_code === null && $code !== null) {
                $existing->pantone_code = $code;
                $dirty = true;
            }
            if ($dirty) {
                $existing->save();
            }

            return $existing->refresh();
        }

        return CustomColor::create([
            'name'         => $name,
            'hexcolor'     => $hex,
            'pantone_code' => $code,
            'pick_count'   => 1,
            'created_by'   => $actor?->id,
        ]);
    }

    /**
     * All custom colors for the GA picker's "Custom" group, most-used
     * first. No-ops to an empty list when the table is absent (older
     * hand-built test schemas that call buildContext but do not create
     * custom_colors).
     *
     * @return array<int, array<string, mixed>>
     */
    public function options(): array
    {
        if (! Schema::hasTable('custom_colors')) {
            return [];
        }

        return CustomColor::orderByDesc('pick_count')
            ->orderBy('name')
            ->get(['id', 'name', 'hexcolor', 'pantone_code', 'pick_count'])
            ->map(fn ($c) => [
                'source'       => 'custom',
                'id'           => $c->id,
                'name'         => $c->name,
                'hexcolor'     => $c->hexcolor,
                'pantone_code' => $c->pantone_code,
                'pick_count'   => (int) $c->pick_count,
            ])
            ->all();
    }
}
