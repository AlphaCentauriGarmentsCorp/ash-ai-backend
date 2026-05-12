<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 5-A — Sample output uploads (front + back photos + remarks).
 *
 * Created by Cutter / Printer / Sewer at the end of their sub-task.
 * Sample status flips through pending → for_approval → approved/rejected.
 *
 * Multiple uploads per stage are allowed (e.g., a sewer uploads first,
 * then re-uploads after revisions). The "approved" record is the one
 * the workflow uses for stage completion.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('stage_sample_uploads', function (Blueprint $t) {
            $t->id();

            $t->foreignId('order_id')
                ->constrained('orders')
                ->cascadeOnDelete();

            $t->foreignId('order_stage_id')
                ->constrained('order_stages')
                ->cascadeOnDelete();

            $t->foreignId('uploaded_by_user_id')
                ->constrained('users');

            $t->string('photo_front_path')->nullable();
            $t->string('photo_back_path')->nullable();

            $t->text('remarks')->nullable();

            // pending → for_approval → approved | rejected
            $t->string('sample_status', 16)->default('for_approval');

            $t->timestamp('completed_at')->nullable();

            $t->timestamps();

            $t->index(['order_id', 'order_stage_id']);
            $t->index('sample_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stage_sample_uploads');
    }
};
