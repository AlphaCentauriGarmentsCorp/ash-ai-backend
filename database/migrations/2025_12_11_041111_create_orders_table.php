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
            $table->bigInteger('po_number');
            $table->bigInteger('client_id')->unsigned()->index()->nullable();
            $table->foreign('client_id')->references('id')->on('client')->onDelete('cascade');  
            $table->bigInteger('brand_id')->unsigned()->index()->nullable();
            $table->foreign('brand_id')->references('id')->on('client_brands')->onDelete('cascade');  
            $table->string('channel');                
            $table->string('order_type');         
            $table->string('design_name');   
            
            $table->bigInteger('fabric_id')->unsigned()->index()->nullable();
            $table->foreign('fabric_id')->references('id')->on('fabrics')->onDelete('cascade');     

            $table->bigInteger('size_id')->unsigned()->index()->nullable();
            $table->foreign('fabrisize_idc_id')->references('id')->on('sizes')->onDelete('cascade');         

            $table->bigInteger('printing_method_id')->unsigned()->index()->nullable();
            $table->foreign('printing_method_id')->references('id')->on('printing_method')->onDelete('cascade');  
    
            $table->bigInteger('garment_id')->unsigned()->index()->nullable();
            $table->foreign('garment_id')->references('id')->on('garments')->onDelete('cascade');  

            $table->string('design_files');      
            $table->string('artist_filename');      
            $table->string('mockup_url');      
            $table->string('mockup_images');      
            $table->string('mockup_notes');      
            $table->string('print_location');      
            $table->string('total_quantity');      
            $table->string('size_breakdown');      
            $table->string('target_date');           
            $table->string('instruction_files');           
            $table->string('insturction_notes'); 

            // decimal? 
            $table->decimal('unit_price');     
                  
            $table->integer('deposit_percentage');           
            $table->string('payment_terms');          
            $table->string('currency');               
            $table->string('status');                
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
