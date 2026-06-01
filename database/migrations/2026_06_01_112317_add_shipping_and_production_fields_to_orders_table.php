<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Restores the shipping/courier and production fields that the Add Order form
 * collects (and StoreOrderRequest validates) but the recreated orders schema
 * dropped. Without these columns the data was silently discarded on save and
 * Order Details had nothing to show.
 *
 * All nullable so existing rows and quotation-converted orders are unaffected.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // ── Shipping / courier ───────────────────────────────────────
            $table->string('courier')->nullable()->after('gc_link');
            $table->string('method')->nullable()->after('courier');           // shipping method
            $table->string('receiver_name')->nullable()->after('method');
            $table->string('contact_number')->nullable()->after('receiver_name');
            $table->string('street_address')->nullable()->after('contact_number');
            $table->string('barangay_address')->nullable()->after('street_address');
            $table->string('city_address')->nullable()->after('barangay_address');
            $table->string('province_address')->nullable()->after('city_address');
            $table->string('postal_address')->nullable()->after('province_address');

            // ── Production details ───────────────────────────────────────
            $table->string('design_name')->nullable()->after('postal_address');
            $table->string('service_type')->nullable()->after('design_name');
            $table->string('print_service')->nullable()->after('service_type');
            $table->string('size_label')->nullable()->after('print_service');
            $table->string('print_label_placement')->nullable()->after('size_label');
            $table->string('fabric_type')->nullable()->after('print_label_placement');
            $table->string('fabric_supplier')->nullable()->after('fabric_type');
            $table->string('fabric_color')->nullable()->after('fabric_supplier');
            $table->string('thread_color')->nullable()->after('fabric_color');
            $table->string('ribbing_color')->nullable()->after('thread_color');

            // ── Freebies + payment ───────────────────────────────────────
            $table->string('freebie_items')->nullable()->after('ribbing_color');
            $table->string('freebie_color')->nullable()->after('freebie_items');
            $table->text('freebie_others')->nullable()->after('freebie_color');
            $table->string('payment_plan')->nullable()->after('freebie_others');
            $table->string('payment_method')->nullable()->after('payment_plan');
            $table->decimal('deposit_percentage', 5, 2)->nullable()->after('payment_method');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn([
                'courier', 'method', 'receiver_name', 'contact_number',
                'street_address', 'barangay_address', 'city_address',
                'province_address', 'postal_address',
                'design_name', 'service_type', 'print_service', 'size_label',
                'print_label_placement', 'fabric_type', 'fabric_supplier',
                'fabric_color', 'thread_color', 'ribbing_color',
                'freebie_items', 'freebie_color', 'freebie_others',
                'payment_plan', 'payment_method', 'deposit_percentage',
            ]);
        });
    }
};
