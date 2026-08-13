<?php

namespace App\Support\Storefront\Stock;

/**
 * Canonical apparel size ordering, server side.
 *
 * Port of the Stock manager's App\Support\SizeOrder, which is itself the twin of
 * the browser's utils/sizeOrder.js. The Inventory grid, the Product Catalog and
 * the Excel export all sort by it, so if the sequence changes it has to change
 * in both places or the three disagree about what "the size after LARGE" is.
 *
 * One addition for reefer_db: 'OS' (one size). The shop's own size vocabulary is
 * S|M|L|XL|2XL|OS — accessories and bags are all OS — and the ERP never had it.
 * It sorts AFTER the graded run rather than inside it: a one-size product has no
 * position in a small-to-large sequence, and pretending it does would scatter
 * bags through the middle of a tee's size list on any mixed view.
 */
class SizeOrder
{
    public const SEQUENCE = ['XS', 'SMALL', 'MEDIUM', 'LARGE', 'XL', '2XL', '3XL', '4XL', '5XL', 'OS'];

    private const ALIASES = [
        'XS' => 'XS', 'XSMALL' => 'XS', 'EXTRA SMALL' => 'XS',
        'S' => 'SMALL', 'SM' => 'SMALL', 'SMALL' => 'SMALL',
        'M' => 'MEDIUM', 'MED' => 'MEDIUM', 'MEDIUM' => 'MEDIUM',
        'L' => 'LARGE', 'LG' => 'LARGE', 'LARGE' => 'LARGE',
        'XL' => 'XL', 'XLARGE' => 'XL', 'EXTRA LARGE' => 'XL',
        '2XL' => '2XL', 'XXL' => '2XL', '2X' => '2XL',
        '3XL' => '3XL', 'XXXL' => '3XL', '3X' => '3XL',
        '4XL' => '4XL', 'XXXXL' => '4XL', '4X' => '4XL',
        '5XL' => '5XL', '5X' => '5XL',
        'OS' => 'OS', 'ONE SIZE' => 'OS', 'ONESIZE' => 'OS', 'FREE SIZE' => 'OS',
    ];

    private static function normalize($raw): string
    {
        $s = strtoupper((string) ($raw ?? ''));
        $s = preg_replace('/[-_.]/', ' ', $s);
        $s = preg_replace('/\s+/', ' ', $s);

        return trim($s);
    }

    /** Anything unrecognised or blank sorts after every known size. */
    public static function sizeRank($raw): int
    {
        $unknown = count(self::SEQUENCE) + 1;
        $key = self::normalize($raw);
        if ($key === '' || $key === '—') {
            return $unknown;
        }
        $canonical = self::ALIASES[$key] ?? null;

        return $canonical !== null ? array_search($canonical, self::SEQUENCE, true) : $unknown;
    }
}
