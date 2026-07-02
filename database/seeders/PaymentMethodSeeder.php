<?php

namespace Database\Seeders;

use App\Models\PaymentMethods;
use Illuminate\Database\Seeder;

/**
 * Seeds the payment_methods lookup used by the Enter Payment modal
 * (CSR "Record Payment"). Mirrors the Add Order form's paymentMethods
 * reference list (src/constants/formOptions/orderOptions.js) so the two
 * dropdowns offer the same choices.
 *
 * Idempotent: updateOrCreate on name — re-running never duplicates, and a
 * pre-existing row (e.g. the manually-added "Cash") just gets its
 * description filled in.
 */
class PaymentMethodSeeder extends Seeder
{
    public function run(): void
    {
        $methods = [
            ['name' => 'Cash',          'description' => 'Cash Payment'],
            ['name' => 'Credit Card',   'description' => 'Credit / Debit Card'],
            ['name' => 'Debit Card',    'description' => 'Debit Card'],
            ['name' => 'Bank Transfer', 'description' => 'Online Bank Transfer'],
            ['name' => 'GCash',         'description' => 'GCash E-Wallet'],
            ['name' => 'Maya',          'description' => 'Maya (PayMaya)'],
            ['name' => 'PayPal',        'description' => 'PayPal'],
        ];

        foreach ($methods as $m) {
            PaymentMethods::updateOrCreate(
                ['name' => $m['name']],
                ['description' => $m['description']],
            );
        }
    }
}
