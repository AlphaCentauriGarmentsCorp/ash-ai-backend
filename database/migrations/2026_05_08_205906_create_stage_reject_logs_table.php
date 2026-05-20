<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4 — QA reject log per order_stage.
 *
 * QA logs bad output that fails inspection. Mostly fired at the
 * quality_control stage, but the schema doesn't restrict to that
 * stage — there are edge cases where a downstream stage realises
 * earlier output was bad and needs to log a reject after the fact.
 *
 * Same shape as stage_waste_logs but kept as a separate table:
 *   - waste = production scrap (operator's loss)
 *   - reject = quality failure (output deemed unfit for delivery)
 *
 * Future fields will diverge (reject may grow a `disposition` column —
 * rework / scrap / customer-accept), so separating now avoids a
 * migration headache later.
 */
return new class extends Migration {

    public function up(): void
    {
        Schema::create('stage_reject_logs', function (Blueprint $t) {
            $t->id();

            $t->foreignId('order_id')
                ->constrained('orders')
                ->cascadeOnDelete();

            $t->foreignId('order_stage_id')
                ->constrained('order_stages')
                ->cascadeOnDelete();

            $t->foreignId('logged_by_user_id')
                ->constrained('users')
                ->cascadeOnDelete();

            $t->unsignedInteger('quantity_pcs');
            $t->string('photo_path')->nullable();
            $t->text('notes')->nullable();

            $t->timestamps();

            $t->index(['order_id', 'created_at']);
            $t->index('order_stage_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stage_reject_logs');
    }
};
