<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 5-A — Add subcontract-tracking fields to support the Sewer
 * and Logistics portals (per Sewer.png and Logistic_Staff.png mockups).
 *
 * Existing columns from Phase 4: order_id, order_stage_id,
 * subcontractor_id, quantity_pcs, rate_per_pcs, total_amount, status,
 * sent_at, returned_at, notes, timestamps.
 *
 * New columns:
 *   payment_terms          - e.g. "After Turnover / 7 days"
 *   agreed_price_per_sample- decimal nullable (sample-specific override
 *                            of rate_per_pcs; useful when sample run
 *                            is priced differently from mass)
 *   waybill_number         - shipping reference, ties into Logistics
 *   gc_chat_link           - URL to Messenger/WhatsApp/etc for vendor
 *   vendor_contact_number  - independent of subcontractors.contact_number
 *                            so changes to vendor record don't rewrite history
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('stage_subcontract_assignments', function (Blueprint $t) {
            $t->string('payment_terms', 64)->nullable()->after('total_amount');
            $t->decimal('agreed_price_per_sample', 10, 2)->nullable()->after('payment_terms');
            $t->string('waybill_number', 64)->nullable()->after('agreed_price_per_sample');
            $t->string('gc_chat_link', 255)->nullable()->after('waybill_number');
            $t->string('vendor_contact_number', 32)->nullable()->after('gc_chat_link');
        });
    }

    public function down(): void
    {
        Schema::table('stage_subcontract_assignments', function (Blueprint $t) {
            $t->dropColumn([
                'payment_terms',
                'agreed_price_per_sample',
                'waybill_number',
                'gc_chat_link',
                'vendor_contact_number',
            ]);
        });
    }
};
