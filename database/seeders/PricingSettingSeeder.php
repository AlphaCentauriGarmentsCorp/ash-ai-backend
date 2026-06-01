<?php

namespace Database\Seeders;

use App\Models\PricingSetting;
use Illuminate\Database\Seeder;

/**
 * Seeds the default Superadmin-editable pricing rates.
 *
 * Idempotent: uses updateOrCreate on `key`, so running it again will NOT
 * duplicate rows. It only fills the label/unit/group/description metadata
 * and the seed default value; once Superadmin edits a value in the UI,
 * re-running the seeder would reset it — so by design we DO NOT overwrite
 * an existing value (see the closure below).
 */
class PricingSettingSeeder extends Seeder
{
    public function run(): void
    {
        $defaults = [
            [
                'key' => PricingSetting::SILKSCREEN_FIRST_COLOR,
                'label' => 'Silkscreen — First Color',
                'value' => 100.00,
                'unit' => '₱ flat',
                'group' => 'silkscreen',
                'description' => 'Flat charge for the first color of a silkscreen job (applied once per quotation).',
            ],
            [
                'key' => PricingSetting::SILKSCREEN_ADDITIONAL_COLOR,
                'label' => 'Silkscreen — Each Additional Color (Regular)',
                'value' => 20.00,
                'unit' => '₱ / color',
                'group' => 'silkscreen',
                'description' => 'Added for every color beyond the first that is printed on a regular-size placement.',
            ],
            [
                'key' => PricingSetting::SILKSCREEN_FIRST_COLOR_FULL,
                'label' => 'Silkscreen — First Color (Full Print)',
                'value' => 150.00,
                'unit' => '₱ flat',
                'group' => 'silkscreen',
                'description' => 'First-color charge when ANY placement in the job is a full print (larger than 14 × 20 inches). Replaces the regular ₱100 base.',
            ],
            [
                'key' => PricingSetting::SILKSCREEN_ADDITIONAL_COLOR_FULL,
                'label' => 'Silkscreen — Each Additional Color (Full Print)',
                'value' => 50.00,
                'unit' => '₱ / color',
                'group' => 'silkscreen',
                'description' => 'Added for every color beyond the first that is printed on a full-print placement.',
            ],
            [
                'key' => PricingSetting::SPECIAL_PRINT_PER_COLOR,
                'label' => 'Silkscreen — Special Print Surcharge (per Color)',
                'value' => 20.00,
                'unit' => '₱ / color',
                'group' => 'silkscreen',
                'description' => 'Added per color when a Special Print (e.g. High Density, Puff) is selected for a silkscreen job. Applied to every color in the job: surcharge = this rate × number of colors. Set to 0 to disable.',
            ],
            [
                'key' => PricingSetting::DTF_PRICE_PER_SQUARE_INCH,
                'label' => 'DTF — Price per Square Inch',
                'value' => 0.00,
                'unit' => '₱ / sq inch',
                'group' => 'dtf',
                'description' => 'Rate per square inch of DTF print. Set this based on your current film/labor/margin. Used as (width × height) × rate × pieces per placement.',
            ],
            [
                'key' => PricingSetting::EMBROIDERY_SMALL_PRICE,
                'label' => 'Embroidery — Small (Pocket / Left Chest)',
                'value' => 120.00,
                'unit' => '₱ / piece',
                'group' => 'embroidery',
                'description' => 'Flat per-piece charge for small embroidery (up to about hand-size, e.g. pocket or left chest). For larger embroidery the CSR enters a manual price (subcontractor quote + markup).',
            ],
            [
                'key' => PricingSetting::SUBLIMATION_JERSEY_FULL_PRICE,
                'label' => 'Sublimation — Jersey (Full Print)',
                'value' => 550.00,
                'unit' => '₱ / piece',
                'group' => 'sublimation',
                'description' => 'Per-piece price for a fully-sublimated Jersey (same price for all sizes). Subcontracted — update when the subcontractor rate changes.',
            ],
            [
                'key' => PricingSetting::SUBLIMATION_MESH_SHORTS_FULL_PRICE,
                'label' => 'Sublimation — Mesh Shorts (Full Print)',
                'value' => 650.00,
                'unit' => '₱ / piece',
                'group' => 'sublimation',
                'description' => 'Per-piece price for fully-sublimated Mesh Shorts (same price for all sizes). Subcontracted — update when the subcontractor rate changes. For small/partial sublimation the CSR enters a manual price.',
            ],
            [
                'key' => PricingSetting::CUSTOM_PATTERN_FEE,
                'label' => 'Custom Fit — Pattern-Making Fee',
                'value' => 500.00,
                'unit' => '₱ / order',
                'group' => 'custom',
                'description' => 'One-time fee added ONCE per order (not per piece) when the fit is Custom, to cover pattern making. The base price uses the nearest existing fit, chosen by the CSR.',
            ],
            [
                'key' => PricingSetting::HOODIE_ZIPPER_ADDON,
                'label' => 'Hoodie — Zipper Add-on',
                'value' => 50.00,
                'unit' => '₱ / piece',
                'group' => 'apparel',
                'description' => 'Added per piece to a hoodie base price when the hoodie has a zipper (default is pullover). Applies on top of whichever hoodie base (heavyweight / regular) is selected.',
            ],
            [
                'key' => PricingSetting::HOODIE_ADDITIONAL_POCKET_ADDON,
                'label' => 'Hoodie — Additional Pocket',
                'value' => 50.00,
                'unit' => '₱ / piece / pocket',
                'group' => 'apparel',
                'description' => 'A hoodie includes one kangaroo pocket by default (free). Each EXTRA pocket the client requests adds this charge per piece. Multiplied by the number of additional pockets.',
            ],
            [
                'key' => PricingSetting::HOODIE_STRINGS_ADDON,
                'label' => 'Hoodie — Hood Strings (Drawstrings)',
                'value' => 40.00,
                'unit' => '₱ / piece',
                'group' => 'apparel',
                'description' => 'Hoods have NO drawstrings by default. Added per piece when the client opts to add hood strings.',
            ],
            [
                'key' => PricingSetting::DOWNPAYMENT_DEFAULT_PERCENT,
                'label' => 'Downpayment — Default %',
                'value' => 60.00,
                'unit' => '%',
                'group' => 'payment',
                'description' => 'Standard downpayment percentage required to start an order. The balance is the remainder. CSRs may request a lower DP, subject to Superadmin approval and the minimum below.',
            ],
            [
                'key' => PricingSetting::DOWNPAYMENT_MINIMUM_PERCENT,
                'label' => 'Downpayment — Hard Minimum %',
                'value' => 50.00,
                'unit' => '%',
                'group' => 'payment',
                'description' => 'The lowest downpayment that can ever be approved. Requests below this are rejected outright — even Superadmin cannot approve below it. Owner rule: DP can never go under 50%.',
            ],
        ];

        foreach ($defaults as $row) {
            $existing = PricingSetting::where('key', $row['key'])->first();

            if ($existing) {
                // Preserve the Superadmin-set value; only refresh metadata.
                $existing->update([
                    'label' => $row['label'],
                    'unit' => $row['unit'],
                    'group' => $row['group'],
                    'description' => $row['description'],
                ]);
            } else {
                PricingSetting::create($row);
            }
        }
    }
}
