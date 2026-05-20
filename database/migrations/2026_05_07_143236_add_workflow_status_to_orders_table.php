<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('workflow_status', 32)
                ->default('inquiry')
                ->after('status')
                ->index();

            // Optional: when delays are detected, cache the first-noticed time.
            $table->timestamp('delayed_at')->nullable()->after('workflow_status');

            // Optional: cache the active stage's id so the role-portal
            // queries don't have to re-scan order_stages every render.
            $table->unsignedBigInteger('current_stage_id')
                ->nullable()
                ->after('delayed_at');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['workflow_status', 'delayed_at', 'current_stage_id']);
        });
    }
};
