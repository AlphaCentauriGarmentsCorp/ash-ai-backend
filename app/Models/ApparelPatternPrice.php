<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ApparelPatternPrice extends Model
{
    protected $table = 'apparel_pattern_prices';
    protected $fillable = [
        'apparel_type_id',
        'pattern_type_id',
        'apparel_type_name',
        'pattern_type_name',
        'price',
        'size_prices',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'size_prices' => 'array',
    ];

    /**
     * Resolve the base price for a given size name.
     *
     * Matches size_prices tolerantly: exact, then case-insensitive, then by a
     * canonical token so synonyms resolve to the same size (e.g. "S" ⇄ "Small",
     * "L" ⇄ "Large", "2XL" ⇄ "XXL"). This is important because the quotation
     * UI uses short labels (S/M/L) while the seeded size_prices use long labels
     * (Small/Medium/Large) — without this they would silently miss and fall
     * back to the single base price. Falls back to the legacy single `price`
     * only when the size genuinely has no entry.
     */
    public function priceForSize(?string $size): float
    {
        $map = is_array($this->size_prices) ? $this->size_prices : [];

        if ($size !== null && $map !== []) {
            // 1) Direct hit.
            if (array_key_exists($size, $map)) {
                return (float) $map[$size];
            }

            // 2) Case-insensitive match (e.g. "small" vs "Small").
            $needle = strtolower(trim($size));
            foreach ($map as $key => $value) {
                if (strtolower(trim((string) $key)) === $needle) {
                    return (float) $value;
                }
            }

            // 3) Canonical-token match so synonyms resolve (S ⇄ Small, etc.).
            $needleToken = static::canonicalSizeToken($size);
            if ($needleToken !== null) {
                foreach ($map as $key => $value) {
                    if (static::canonicalSizeToken((string) $key) === $needleToken) {
                        return (float) $value;
                    }
                }
            }
        }

        return (float) ($this->price ?? 0);
    }

    /**
     * Collapse a human size label to a canonical token so different spellings
     * of the same size compare equal. Unknown labels return their normalized
     * form so custom sizes still match themselves.
     */
    public static function canonicalSizeToken(?string $size): ?string
    {
        $s = strtolower(trim((string) $size));
        if ($s === '') {
            return null;
        }

        // Strip spaces/dashes so "extra large" and "extra-large" align.
        $compact = preg_replace('/[\s\-]+/', '', $s);

        return match ($compact) {
            'xs', 'extrasmall'                 => 'xs',
            's', 'small'                       => 's',
            'm', 'medium', 'med'               => 'm',
            'l', 'large'                       => 'l',
            'xl', 'extralarge'                 => 'xl',
            '2xl', 'xxl', '2x', 'xxlarge', 'doublexl', 'extraextralarge' => '2xl',
            '3xl', 'xxxl', '3x', 'xxxlarge', 'triplexl'                  => '3xl',
            '4xl', 'xxxxl', '4x'               => '4xl',
            default                            => $compact,
        };
    }
}