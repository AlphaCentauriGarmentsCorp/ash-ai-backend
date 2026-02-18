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

            $table->string('po_code')->unique();
            $table->foreignId('client_id')->nullable()->constrained('clients')->nullOnDelete();
            $table->string('client_brand');
            $table->date('deadline');
            $table->string('priority');
            $table->string('brand');


            $table->string('courier');
            $table->string('method');
            $table->string('receiver_name');
            $table->string('receiver_contact');
            $table->string('address')->nullable();


            $table->string('design_name');
            $table->string('apparel_type');
            $table->string('pattern_type');
            $table->string('service_type');
            $table->string('print_method');
            $table->string('print_service');
            $table->string('size_label');
            $table->string('print_label_placement');


            $table->string('fabric_type');
            $table->string('fabric_supplier');
            $table->string('fabric_color');
            $table->string('thread_color');
            $table->string('ribbing_color');


            $table->string('placement_measurements')->nullable();
            $table->text('notes')->nullable();
            $table->text('options')->nullable();


            $table->string('freebie_items')->nullable();
            $table->string('freebie_color')->nullable();
            $table->string('freebie_others')->nullable();

            $table->string('payment_method')->nullable();
            $table->string('payment_plan')->nullable();
            $table->decimal('total_price', 15, 2)->default(0);
            $table->decimal('average_unit_price', 15, 2)->default(0);
            $table->integer('total_quantity')->nullable();
            $table->integer('deposit')->nullable();

            $table->text('design_files')->nullable();
            $table->text('design_mockup')->nullable();
            $table->text('size_label_files')->nullable();
            $table->text('freebies_files')->nullable();

            $table->text('qr_path')->nullable();
            $table->text('barcode_path')->nullable();

            $table->string('status')->default('Pending Approval');
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
