<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Customer-opened returns.
 *
 * A return is a request, not a refund — no money is stored here. What the return
 * is worth is recomputed from the order_items it points at every time it is read,
 * so a repriced catalog can never change what an old return was owed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('storefront_return_requests', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique();   // RET-000001, derived from the id
            $table->foreignId('order_id')->constrained('storefront_orders')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('storefront_users')->cascadeOnDelete();

            $table->string('status')->default('requested'); // config('reefer.returns.statuses')
            $table->string('reason');                       // key of config('reefer.returns.reasons')
            $table->text('note')->nullable();

            $table->timestamp('requested_at')->nullable();
            $table->timestamp('resolved_at')->nullable();   // set when it stops being pending
            $table->timestamps();

            // The account page reads by owner, newest first.
            $table->index(['user_id', 'requested_at'], 'storefront_return_requests_user_requested_idx');

            // Every new request asks "what is still live against this order?" before
            // it is allowed to claim anything.
            $table->index(['order_id', 'status'], 'storefront_return_requests_order_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('storefront_return_requests');
    }
};
