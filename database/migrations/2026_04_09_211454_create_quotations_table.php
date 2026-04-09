<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quotations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('quotation_id')->unique();
            $table->string('client_name')->nullable();
            $table->string('client_email')->nullable();
            $table->string('client_brand')->nullable();

            // Shirt / Notes
            $table->string('shirt_color')->nullable();
            $table->string('free_items')->nullable();
            $table->text('notes')->nullable();

            // Pricing
            $table->decimal('subtotal', 10, 2)->default(0);
            $table->string('discount_type')->nullable();
            $table->decimal('discount_price', 10, 2)->default(0);
            $table->decimal('grand_total', 10, 2)->default(0);

            // JSON Fields
            $table->json('items_json')->nullable();
            $table->json('addons_json')->nullable();
            $table->json('breakdown_json')->nullable();
            $table->string('status')->default('Pending');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quotations');
    }
};
