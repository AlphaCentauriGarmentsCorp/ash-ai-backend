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
        Schema::create('order_processes', function (Blueprint $table) {
            $table->id();

            $table->foreignId('po_id')
                ->constrained(table: 'orders', indexName: 'order_processes_po_id_index')
                ->cascadeOnDelete();

            $table->string('stage')->notNullable();

            $table->foreignId('assigned_by')
                ->constrained(table: 'users', indexName: 'order_processes_assigned_by_index')
                ->cascadeOnDelete();

            $table->foreignId('assigned_to')
                ->constrained(table: 'users', indexName: 'order_processes_assigned_to_index')
                ->cascadeOnDelete();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('deadline')->nullable();

            $table->string('status')->default('pending');
            $table->text('notes')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('order_processes');
    }
};
