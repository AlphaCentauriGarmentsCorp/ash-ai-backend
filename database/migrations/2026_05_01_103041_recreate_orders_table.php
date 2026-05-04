<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Restructure the orders table to align with the Quotation-to-Order bridge:
     *  - Drop all legacy columns from the original order form
     *  - Add new columns that mirror the Quotation module fields
     *
     * FK checks are disabled during the drop phase to avoid constraint errors.
     */
    public function up(): void
    {
        // ── Step 1: Drop legacy columns ───────────────────────────────────────
        DB::statement('SET FOREIGN_KEY_CHECKS=0;');

        Schema::table('orders', function (Blueprint $table) {
            $legacyColumns = [
                'deadline', 'priority', 'brand', 'courier', 'method',
                'receiver_name', 'receiver_contact', 'address',
                'design_name', 'apparel_type', 'pattern_type', 'service_type',
                'print_method', 'print_service', 'size_label', 'print_label_placement',
                'fabric_type', 'fabric_supplier', 'fabric_color', 'thread_color',
                'ribbing_color', 'placement_measurements', 'options',
                'freebie_items', 'freebie_color', 'freebie_others',
                'payment_method', 'payment_plan', 'total_price',
                'average_unit_price', 'total_quantity', 'deposit',
                'design_files', 'design_mockup', 'size_label_files', 'freebies_files',
            ];

            // Only drop columns that actually exist (safe re-run)
            $existing = Schema::getColumnListing('orders');
            $toDrop = array_intersect($legacyColumns, $existing);

            if (!empty($toDrop)) {
                $table->dropColumn($toDrop);
            }
        });

        DB::statement('SET FOREIGN_KEY_CHECKS=1;');

        // ── Step 2: Add new columns (skip if already present — safe re-run) ──
        Schema::table('orders', function (Blueprint $table) {
            $existing = Schema::getColumnListing('orders');

            if (!in_array('quotation_id', $existing)) {
                $table->foreignId('quotation_id')
                      ->nullable()
                      ->after('id')
                      ->constrained('quotations')
                      ->nullOnDelete();
            }

            if (!in_array('client_name', $existing)) {
                $table->string('client_name')->nullable()->after('client_id');
            }

            if (!in_array('apparel_type_id', $existing)) {
                $table->unsignedBigInteger('apparel_type_id')->nullable()->after('client_brand');
            }

            if (!in_array('pattern_type_id', $existing)) {
                $table->unsignedBigInteger('pattern_type_id')->nullable()->after('apparel_type_id');
            }

            if (!in_array('apparel_neckline_id', $existing)) {
                $table->unsignedBigInteger('apparel_neckline_id')->nullable()->after('pattern_type_id');
            }

            if (!in_array('print_method_id', $existing)) {
                $table->unsignedBigInteger('print_method_id')->nullable()->after('apparel_neckline_id');
            }

            if (!in_array('shirt_color', $existing)) {
                $table->string('shirt_color')->nullable()->after('print_method_id');
            }

            if (!in_array('special_print', $existing)) {
                $table->string('special_print')->nullable()->after('shirt_color');
            }

            if (!in_array('print_area', $existing)) {
                $table->string('print_area')->nullable()->default('Regular')->after('special_print');
            }

            if (!in_array('free_items', $existing)) {
                $table->string('free_items')->nullable()->after('print_area');
            }

            if (!in_array('discount_type', $existing)) {
                $table->string('discount_type')->nullable()->after('notes');
            }

            if (!in_array('discount_price', $existing)) {
                $table->decimal('discount_price', 10, 2)->default(0)->after('discount_type');
            }

            if (!in_array('discount_amount', $existing)) {
                $table->decimal('discount_amount', 10, 2)->default(0)->after('discount_price');
            }

            if (!in_array('subtotal', $existing)) {
                $table->decimal('subtotal', 10, 2)->default(0)->after('discount_amount');
            }

            if (!in_array('grand_total', $existing)) {
                $table->decimal('grand_total', 10, 2)->default(0)->after('subtotal');
            }

            if (!in_array('item_config_json', $existing)) {
                $table->json('item_config_json')->nullable()->after('grand_total');
            }

            if (!in_array('items_json', $existing)) {
                $table->json('items_json')->nullable()->after('item_config_json');
            }

            if (!in_array('addons_json', $existing)) {
                $table->json('addons_json')->nullable()->after('items_json');
            }

            if (!in_array('breakdown_json', $existing)) {
                $table->json('breakdown_json')->nullable()->after('addons_json');
            }

            if (!in_array('print_parts_json', $existing)) {
                $table->json('print_parts_json')->nullable()->after('breakdown_json');
            }
        });
    }

    public function down(): void
    {
        DB::statement('SET FOREIGN_KEY_CHECKS=0;');

        // Remove added columns
        Schema::table('orders', function (Blueprint $table) {
            $existing = Schema::getColumnListing('orders');
            $toRemove = array_intersect([
                'quotation_id', 'client_name', 'apparel_type_id', 'pattern_type_id',
                'apparel_neckline_id', 'print_method_id', 'shirt_color', 'special_print',
                'print_area', 'free_items', 'discount_type', 'discount_price',
                'discount_amount', 'subtotal', 'grand_total',
                'item_config_json', 'items_json', 'addons_json', 'breakdown_json', 'print_parts_json',
            ], $existing);

            if (!empty($toRemove)) {
                $table->dropColumn($toRemove);
            }
        });

        DB::statement('SET FOREIGN_KEY_CHECKS=1;');

        // Restore legacy columns
        Schema::table('orders', function (Blueprint $table) {
            $table->date('deadline')->nullable();
            $table->string('priority')->nullable();
            $table->string('brand')->nullable();
            $table->string('courier')->nullable();
            $table->string('method')->nullable();
            $table->string('receiver_name')->nullable();
            $table->string('receiver_contact')->nullable();
            $table->string('address')->nullable();
            $table->string('design_name')->nullable();
            $table->string('apparel_type')->nullable();
            $table->string('pattern_type')->nullable();
            $table->string('service_type')->nullable();
            $table->string('print_method')->nullable();
            $table->string('print_service')->nullable();
            $table->string('size_label')->nullable();
            $table->string('print_label_placement')->nullable();
            $table->string('fabric_type')->nullable();
            $table->string('fabric_supplier')->nullable();
            $table->string('fabric_color')->nullable();
            $table->string('thread_color')->nullable();
            $table->string('ribbing_color')->nullable();
            $table->string('placement_measurements')->nullable();
            $table->text('options')->nullable();
            $table->string('freebie_items')->nullable();
            $table->string('freebie_color')->nullable();
            $table->string('freebie_others')->nullable();
            $table->string('payment_method')->nullable();
            $table->string('payment_plan')->nullable();
            $table->decimal('total_price', 15, 2)->default(0);
            $table->decimal('average_unit_price', 15, 2)->default(0);
            $table->integer('total_quantity')->nullable();
            $table->integer('deposit')->nullable();
            $table->text('design_files')->nullable();
            $table->text('design_mockup')->nullable();
            $table->text('size_label_files')->nullable();
            $table->text('freebies_files')->nullable();
        });
    }
};
