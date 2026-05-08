<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3 — Line items of a Material Request.
 *
 * Each row is one material the requester wants, along with a
 * snapshot of stock-at-request-time so the manager can see
 * whether a shortage exists without having to query inventory
 * separately. quantity_short = max(0, requested - available).
 */
return new class extends Migration {

    public function up(): void
    {
        Schema::create('material_request_items', function (Blueprint $table) {
            $table->id();

            $table->foreignId('material_request_id')
                ->constrained('material_requests')
                ->cascadeOnDelete();

            $table->foreignId('material_id')
                ->constrained('materials')
                ->cascadeOnDelete();

            $table->decimal('quantity_requested', 12, 2);
            $table->decimal('quantity_available', 12, 2)->default(0); // snapshot
            $table->decimal('quantity_short', 12, 2)->default(0);     // computed at request time

            // Snapshot of unit so the row remains meaningful even if
            // the parent material's `unit` is renamed later.
            $table->string('unit')->nullable();

            $table->text('notes')->nullable();

            $table->timestamps();

            $table->index('material_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('material_request_items');
    }
};
