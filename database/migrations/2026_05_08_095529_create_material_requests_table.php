<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3 — Material Requests.
 *
 * A Material Request is created by a production-role user during
 * their order's active workflow stage, asking for materials needed
 * to do that stage's work. Managers + Admin + SuperAdmin approve
 * or reject. Approval auto-triggers a Purchase Request if any
 * line item is short on stock.
 */
return new class extends Migration {

    public function up(): void
    {
        Schema::create('material_requests', function (Blueprint $table) {
            $table->id();
            $table->string('mr_code')->unique();

            // What order + stage this MR belongs to.
            $table->foreignId('order_id')
                ->constrained('orders')
                ->cascadeOnDelete();

            $table->foreignId('stage_id')
                ->nullable()
                ->constrained('order_stages')
                ->nullOnDelete();

            // Who made the request.
            $table->foreignId('requested_by_user_id')
                ->constrained('users')
                ->cascadeOnDelete();

            // Workflow status:
            //   pending  — waiting for manager decision
            //   approved — fully approved, stock decremented
            //   rejected — denied, no stock changes
            //   auto_pr  — approved but stock was short; PR auto-created
            $table->string('status', 16)->default('pending')->index();

            $table->text('reason')->nullable();
            $table->text('rejection_reason')->nullable();

            // Approval audit.
            $table->foreignId('approved_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamp('approved_at')->nullable();

            // If a PR was auto-spawned, link it here for traceability.
            // (FK added in the create_purchase_requests migration since
            //  that table doesn't exist yet at this point in the run.)
            $table->unsignedBigInteger('purchase_request_id')->nullable();

            $table->timestamps();

            $table->index(['order_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('material_requests');
    }
};
