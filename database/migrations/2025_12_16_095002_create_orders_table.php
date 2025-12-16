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
        Schema::create('orders', function (Blueprint $table) {
            $table->id();

            $table->bigInteger('po_number')->unique();

            $table->foreignId('client_id')
                ->constrained(table: 'clients', indexName: 'orders_client_id_index')
                ->cascadeOnDelete();

            $table->foreignId('brand_id')
                ->constrained(table: 'client_brands', indexName: 'orders_brand_id_index')
                ->cascadeOnDelete();

            $table->string('channel');
            $table->string('order_type');
            $table->string('design_name');

            $table->foreignId('type_fabric')
                ->constrained(table: 'type_fabrics', indexName: 'orders_type_fabric_index')
                ->cascadeOnDelete();

            $table->foreignId('type_size')
                ->constrained(table: 'type_sizes', indexName: 'orders_type_size_index')
                ->cascadeOnDelete();

            $table->foreignId('type_garment')
                ->constrained(table: 'type_garments', indexName: 'orders_type_garment_index')
                ->cascadeOnDelete();

            $table->foreignId('type_printing_method')
                ->constrained(table: 'type_printing_methods', indexName: 'orders_type_printing_method_index')
                ->cascadeOnDelete();

            $table->text('design_files')->nullable();
            $table->string('artist_filename')->nullable();
            $table->string('mockup_url')->nullable();
            $table->text('mockup_images')->nullable();
            $table->string('mockup_notes')->nullable();
            $table->text('print_location')->nullable();
            $table->integer('total_quantity')->nullable();
            $table->text('size_breakdown')->nullable();
            $table->date('target_date')->nullable();
            $table->text('instruction_files')->nullable();
            $table->string('instruction_notes')->nullable();
            $table->integer('unit_price')->nullable();
            $table->integer('desposit_percentage')->nullable();
            $table->string('payment_terms')->nullable();
            $table->string('currency')->nullable();
            $table->string('status')->default('pending')->notNullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
