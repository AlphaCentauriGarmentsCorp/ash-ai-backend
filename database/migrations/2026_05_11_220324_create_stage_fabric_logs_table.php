<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 5-A — Track fabric usage and waste against an order_stage.
 *
 * Used by the Cutter portal (and any other role tracking fabric).
 * usable_remaining_kg is computed at write time as
 * (fabric_used_kg - waste_kg) by the controller, but stored so reads
 * are cheap.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('stage_fabric_logs', function (Blueprint $t) {
            $t->id();

            $t->foreignId('order_id')
                ->constrained('orders')
                ->cascadeOnDelete();

            $t->foreignId('order_stage_id')
                ->constrained('order_stages')
                ->cascadeOnDelete();

            $t->foreignId('logged_by_user_id')
                ->constrained('users');

            $t->decimal('fabric_used_kg', 10, 2);
            $t->decimal('waste_kg', 10, 2)->default(0);
            $t->decimal('usable_remaining_kg', 10, 2)->default(0);

            $t->string('fabric_roll_id', 64)->nullable();   // optional batch / roll code
            $t->text('notes')->nullable();

            $t->timestamps();

            $t->index(['order_id', 'order_stage_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stage_fabric_logs');
    }
};
