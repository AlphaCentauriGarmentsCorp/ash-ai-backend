<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3 — Line items of a Purchase Request.
 *
 * Each row is a material to buy, with snapshotted unit price so
 * the historical line_total stays accurate even if material.price
 * is updated later.
 */
return new class extends Migration {

    public function up(): void
    {
        Schema::create('purchase_request_items', function (Blueprint $table) {
            $table->id();

            $table->foreignId('purchase_request_id')
                ->constrained('purchase_requests')
                ->cascadeOnDelete();

            $table->foreignId('material_id')
                ->constrained('materials')
                ->cascadeOnDelete();

            $table->decimal('quantity', 12, 2);
            $table->decimal('unit_price', 12, 2)->default(0);
            $table->decimal('line_total', 12, 2)->default(0);

            $table->string('unit')->nullable();
            $table->text('notes')->nullable();

            $table->timestamps();

            $table->index('material_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_request_items');
    }
};
