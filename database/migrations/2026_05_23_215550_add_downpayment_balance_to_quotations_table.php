<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add first-class `downpayment` and `balance` columns to quotations.
 *
 * These mirror the 60/40 payment split (Addendum 5.4) that QuotationService
 * computes from the grand total. They are stored in breakdown_json too, but
 * promoting them to real columns lets the system REPORT and FILTER on them
 * — e.g. an "outstanding balances" view, total downpayments collected this
 * month, or sorting an order list by amount owed (supports the Blueprint
 * Issue 13 dashboard's payment-tracking panels).
 *
 * Guarded with hasColumn() so it is safe to run on any environment.
 */
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('quotations')) {
            return;
        }

        Schema::table('quotations', function (Blueprint $table) {
            if (! Schema::hasColumn('quotations', 'downpayment')) {
                // 60% of grand_total. Indexed for "who still owes" style queries.
                $table->decimal('downpayment', 10, 2)->default(0)->after('grand_total');
            }
            if (! Schema::hasColumn('quotations', 'balance')) {
                // 40% of grand_total (the remaining amount due).
                $table->decimal('balance', 10, 2)->default(0)->after('downpayment');
            }
        });

        // Index balance so an "outstanding balance" report can filter/sort
        // efficiently. Wrapped in hasColumn to avoid erroring on re-run.
        if (Schema::hasColumn('quotations', 'balance')) {
            Schema::table('quotations', function (Blueprint $table) {
                $table->index('balance', 'quotations_balance_idx');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('quotations')) {
            return;
        }

        Schema::table('quotations', function (Blueprint $table) {
            if (Schema::hasColumn('quotations', 'balance')) {
                $table->dropIndex('quotations_balance_idx');
            }
            $columns = array_values(array_filter(
                ['downpayment', 'balance'],
                fn ($c) => Schema::hasColumn('quotations', $c)
            ));
            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};