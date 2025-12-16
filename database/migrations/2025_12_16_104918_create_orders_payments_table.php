<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('orders_payments', function (Blueprint $table) {
            $table->id();

            $table->foreignId('po_id')
                ->constrained(table: 'orders', indexName: 'orders_payment_po_id_index')
                ->cascadeOnDelete();

            $table->string('payment_type');
            $table->decimal('amount', 12, 2);
            $table->string('currency', 10);
            $table->string('payment_method');
            $table->string('reference_number')->nullable();
            $table->text('proof')->nullable();
            $table->string('remarks')->nullable();

            $table->foreignId('verified_by')
                ->nullable()
                ->constrained(table: 'users', indexName: 'orders_payment_verified_by_index')
                ->nullOnDelete();

            $table->timestamp('verified_at')->nullable();
            $table->string('status')->default('pending');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('orders_payments');
    }
};
