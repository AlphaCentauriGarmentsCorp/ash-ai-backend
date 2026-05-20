<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4 — Subcontract assignment tracker per order_stage.
 *
 * When a stage is farmed out (sewing/cutting/printing), this row records
 * which vendor got the work, the agreed rate, and the lifecycle of the
 * batch (sent → returned).
 *
 * Lifecycle status:
 *   pending   - assignment created, not yet shipped to vendor
 *   out       - shipped to vendor, awaiting return
 *   returned  - vendor returned the work; QA can then inspect
 *   cancelled - assignment voided
 */
return new class extends Migration {

    public function up(): void
    {
        Schema::create('stage_subcontract_assignments', function (Blueprint $t) {
            $t->id();

            $t->foreignId('order_id')
                ->constrained('orders')
                ->cascadeOnDelete();

            $t->foreignId('order_stage_id')
                ->constrained('order_stages')
                ->cascadeOnDelete();

            // FK references the renamed `subcontractors` table (see
            // 000001 migration). Kept nullable + nullOnDelete so the
            // assignment record survives if a vendor is removed from
            // the directory.
            $t->foreignId('subcontractor_id')
                ->nullable()
                ->constrained('subcontractors')
                ->nullOnDelete();

            $t->unsignedInteger('quantity_pcs');
            $t->decimal('rate_per_pcs', 10, 2)->default(0); // snapshot at assignment time
            $t->decimal('total_amount', 12, 2)->default(0); // qty × rate, computed in service

            $t->string('status', 16)->default('pending')->index();

            $t->timestamp('sent_at')->nullable();
            $t->timestamp('returned_at')->nullable();

            $t->text('notes')->nullable();

            $t->timestamps();

            $t->index(['order_id', 'status']);
            $t->index('order_stage_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stage_subcontract_assignments');
    }
};
