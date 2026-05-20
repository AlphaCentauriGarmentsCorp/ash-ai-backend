<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2 — Notifications system.
 *
 * Each notification belongs to a user. The `type` field is a stable slug
 * (e.g. "stage.delayed", "stage.assigned", "order.completed") that the
 * frontend uses to pick an icon/colour. The `data` column stores any
 * extra context (order id, stage id, link target, etc.) as JSON.
 */
return new class extends Migration {

    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('user_id');
            $table->string('type', 64)->index();
            $table->string('title');
            $table->text('body')->nullable();

            // Arbitrary structured data (order_id, stage_id, link, etc.).
            $table->json('data')->nullable();

            // When this was clicked / acknowledged. NULL = unread.
            $table->timestamp('read_at')->nullable()->index();

            $table->timestamps();

            $table->index(['user_id', 'read_at']);
            $table->index(['user_id', 'created_at']);

            $table->foreign('user_id')
                ->references('id')
                ->on('users')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
