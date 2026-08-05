<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Customer orders.
 *
 * Squashed from create_orders_table + add_discount_to_orders_table +
 * add_delivered_at_to_orders_table.
 *
 * The table is `storefront_orders`, NOT `orders`: the ERP's own `orders` table is a
 * production order with 20+ migrations behind it and nothing in common with this one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('storefront_orders', function (Blueprint $table) {
            $table->id();
            $table->string('order_number')->unique();   // RFR-PH0019005

            // null = guest checkout. Explicit target table: the inferred name would be
            // `users`, the ERP's staff table.
            $table->foreignId('user_id')->nullable()->constrained('storefront_users')->nullOnDelete();

            // Contact
            $table->string('email');
            $table->string('ship_to_name');
            $table->string('phone');

            // Shipping address snapshot (frozen at purchase time)
            $table->string('street');
            $table->string('barangay')->nullable();
            $table->string('city');
            $table->string('province');
            $table->string('region')->nullable();
            $table->string('postal', 10)->nullable();

            // Shipping + money (whole pesos)
            $table->string('shipping_method');          // golocal | express
            $table->unsignedInteger('subtotal');

            /*
             * What the order actually redeemed, snapshotted like the address is: the
             * code as text rather than a foreign key, so deleting a retired voucher can
             * never rewrite the history of an order that used it.
             */
            $table->string('discount_code', 40)->nullable();
            $table->unsignedInteger('discount_amount')->default(0);

            $table->unsignedInteger('shipping_fee');
            $table->unsignedInteger('total');

            // Payment
            $table->string('payment_method');           // gcash | maya | card | cod
            $table->string('payment_status')->default('pending'); // pending | paid | cod | failed
            $table->string('payment_ref')->nullable();  // gateway reference (PayMongo later)

            // Fulfilment
            $table->string('status')->default('Processing'); // Processing | Delivered | Canceled
            $table->unsignedTinyInteger('stage')->default(0); // 0..4 -> config('reefer.stages')
            $table->string('courier')->nullable();
            $table->string('tracking_number')->nullable();
            $table->date('eta')->nullable();

            /*
             * When the parcel actually landed.
             *
             * The returns window had to guess at this before: it fell back to `eta`
             * (the date the shop PLANNED to deliver, which is not the date it arrived)
             * and then to placed_at. Stamping a real timestamp the moment an order
             * reaches the Delivered stage removes the guess.
             *
             * The upstream migration also backfilled existing delivered orders; on a
             * fresh table there is nothing to backfill.
             */
            $table->timestamp('delivered_at')->nullable();

            $table->timestamp('placed_at')->nullable();
            $table->timestamps();

            $table->index('user_id', 'storefront_orders_user_idx');
            $table->index('status', 'storefront_orders_status_idx');

            // Exactly the per_user_limit lookup: this account's prior orders on
            // this code.
            $table->index(['user_id', 'discount_code'], 'storefront_orders_user_discount_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('storefront_orders');
    }
};
