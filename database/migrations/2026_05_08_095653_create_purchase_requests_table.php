<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3 — Purchase Requests.
 *
 * One PR per order (per the architecture choice). Auto-created when
 * an MR is approved but inventory was short. Goes through its own
 * approve / order / receive lifecycle, and on receive() it
 * increments stock on the underlying materials.
 */
return new class extends Migration {

    public function up(): void
    {
        Schema::create('purchase_requests', function (Blueprint $table) {
            $table->id();
            $table->string('pr_code')->unique();

            $table->foreignId('order_id')
                ->constrained('orders')
                ->cascadeOnDelete();

            // The MR that triggered this PR (nullable in case a manager
            // creates a PR ad-hoc without an originating MR).
            $table->foreignId('material_request_id')
                ->nullable()
                ->constrained('material_requests')
                ->nullOnDelete();

            $table->foreignId('supplier_id')
                ->nullable()
                ->constrained('suppliers')
                ->nullOnDelete();

            // Lifecycle:
            //   pending   — waiting for approval
            //   approved  — manager approved, ready to be ordered
            //   ordered   — actually placed with the supplier
            //   received  — goods arrived, stock incremented
            //   cancelled — abandoned at any stage before received
            $table->string('status', 16)->default('pending')->index();

            $table->decimal('total_amount', 12, 2)->default(0);

            $table->text('reason')->nullable();

            // Audit.
            $table->foreignId('approved_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('ordered_at')->nullable();
            $table->timestamp('received_at')->nullable();

            $table->timestamps();

            $table->index(['order_id', 'status']);
        });

        // Add the deferred FK from material_requests.purchase_request_id
        // now that purchase_requests exists.
        if (Schema::hasTable('material_requests')
            && Schema::hasColumn('material_requests', 'purchase_request_id')) {
            Schema::table('material_requests', function (Blueprint $table) {
                $table->foreign('purchase_request_id')
                    ->references('id')->on('purchase_requests')
                    ->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        // Drop the FK we added above, if present.
        if (Schema::hasTable('material_requests')) {
            Schema::table('material_requests', function (Blueprint $table) {
                // dropForeign with array argument is safer across drivers.
                try {
                    $table->dropForeign(['purchase_request_id']);
                } catch (\Throwable $e) {
                    // Foreign key may not exist in some envs; ignore.
                }
            });
        }

        Schema::dropIfExists('purchase_requests');
    }
};
