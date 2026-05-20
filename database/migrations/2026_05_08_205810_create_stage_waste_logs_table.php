<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4 — Production waste log per order_stage.
 *
 * Production-floor users (cutter, printer, sewer, etc.) log unusable
 * scrap during their stage. Distinct from `stage_reject_logs` which is
 * QA-side rejection of bad output.
 */
return new class extends Migration {

    public function up(): void
    {
        Schema::create('stage_waste_logs', function (Blueprint $t) {
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
        Schema::dropIfExists('stage_waste_logs');
    }
};
