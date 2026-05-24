<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PRICING ENGINE FOUNDATION — central, Superadmin-editable rate store.
 *
 * Blueprint Section 2 / 3: "Lahat ng rate ay itinatakda ng Superadmin sa
 * Settings — hindi hardcoded." Until now there was no settings table for
 * pricing, so per-color rates were passed from the frontend per request.
 *
 * This is a simple KEY / VALUE store (not one column per rate) so new rates
 * can be added later WITHOUT a schema change — important because DTF,
 * Embroidery, and Sublimation rates are still TBD (waiting for Superadmin's
 * sheets). Each row is one editable rate with a human label and a unit, so
 * the Settings UI can render it cleanly for a non-technical Superadmin.
 *
 * Seeded defaults (see PricingSettingSeeder):
 *   silkscreen_first_color_price      = 100.00  (flat, 1st color of the job)
 *   silkscreen_additional_color_price =  20.00  (per each additional color)
 *   dtf_price_per_square_inch         =   0.00  (placeholder; Superadmin sets)
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('pricing_settings')) {
            return;
        }

        Schema::create('pricing_settings', function (Blueprint $table) {
            $table->id();

            // Machine key the pricing engine looks up (e.g.
            // "silkscreen_first_color_price"). Stable, never shown to users.
            $table->string('key', 100)->unique();

            // Human-friendly label shown in the Settings UI
            // (e.g. "Silkscreen — First Color").
            $table->string('label', 191);

            // The editable numeric value (a peso amount or a per-unit rate).
            $table->decimal('value', 12, 2)->default(0);

            // Display unit for the UI (e.g. "₱ flat", "₱ / color",
            // "₱ / sq inch"). Pure presentation; not used in math.
            $table->string('unit', 50)->nullable();

            // Optional grouping for the Settings page
            // (e.g. "silkscreen", "dtf"). Lets the UI section the rates.
            $table->string('group', 50)->nullable();

            // Optional helper text shown under the field in the UI.
            $table->string('description', 255)->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pricing_settings');
    }
};