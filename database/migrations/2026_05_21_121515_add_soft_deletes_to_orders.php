<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds soft-delete support to the orders table.
 *
 * An order is referenced by ~27 downstream tables (order_stages,
 * stage_audit_logs, fabric/reject/ink logs, payments, purchase requests,
 * packing boxes, design files, etc.) and the stage audit trail is meant
 * to be immutable. Hard-deleting an order would either fail on FK
 * constraints or orphan all of that history.
 *
 * Soft-delete keeps the full record (and all related rows) intact and
 * recoverable while removing the order from normal listings. The Order
 * model uses the SoftDeletes trait, so all existing Eloquent queries
 * (index / withActiveStage / show) automatically exclude trashed orders
 * with no further changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->softDeletes(); // nullable deleted_at timestamp
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};