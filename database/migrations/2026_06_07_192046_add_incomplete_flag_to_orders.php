<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Change 11 — superadmin "save anyway" override.
 *
 * Adds an Incomplete flag to orders so a superadmin can persist an order
 * that is missing SOFT-required fields. `is_incomplete` drives the UI
 * badge; `incomplete_fields` records WHICH soft fields were left blank.
 * The who/when of the override lives in csr_activity_logs (written via
 * CsrActivityLogger) rather than on the order itself.
 *
 * The hard floor — a client plus at least one line item — is enforced in
 * OrderService and is never bypassable. This flag only ever marks the
 * soft fields that were skipped.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->boolean('is_incomplete')->default(false)->after('workflow_status');
            $table->json('incomplete_fields')->nullable()->after('is_incomplete');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['is_incomplete', 'incomplete_fields']);
        });
    }
};
