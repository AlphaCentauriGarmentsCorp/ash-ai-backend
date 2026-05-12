<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 5-A — Track ink usage and waste against an order_stage.
 *
 * Used by the Printer portal. Same shape as stage_fabric_logs but
 * with ink-specific fields (color, paint vs ink distinction).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('stage_ink_logs', function (Blueprint $t) {
            $t->id();

            $t->foreignId('order_id')
                ->constrained('orders')
                ->cascadeOnDelete();

            $t->foreignId('order_stage_id')
                ->constrained('order_stages')
                ->cascadeOnDelete();

            $t->foreignId('logged_by_user_id')
                ->constrained('users');

            $t->string('ink_color', 64)->nullable();        // e.g. "White", "Pantone 186 C"
            $t->decimal('ink_used_kg', 10, 3);              // 3 decimals — inks are measured finer
            $t->decimal('ink_waste_kg', 10, 3)->default(0);
            $t->decimal('usable_remaining_kg', 10, 3)->default(0);

            $t->text('notes')->nullable();

            $t->timestamps();

            $t->index(['order_id', 'order_stage_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stage_ink_logs');
    }
};
