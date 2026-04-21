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
        Schema::table('quotations', function (Blueprint $table) {
            $table->unsignedBigInteger('client_id')->nullable()->after('user_id');
            $table->string('client_facebook')->nullable()->after('client_email');
            $table->unsignedBigInteger('apparel_neckline_id')->nullable()->after('shirt_color');
            $table->json('item_config_json')->nullable()->after('breakdown_json');
            $table->decimal('discount_amount', 10, 2)->default(0)->after('discount_price');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('quotations', function (Blueprint $table) {
            $table->dropColumn([
                'client_id',
                'client_facebook',
                'apparel_neckline_id',
                'item_config_json',
                'discount_amount',
            ]);
        });
    }
};
