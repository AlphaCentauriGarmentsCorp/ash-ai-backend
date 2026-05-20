<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 7-B Bundle 4a — QA/Packer task completion audit row.
 *
 * One row per successful SUBMIT COMPLETED action. Captures everything
 * the system needs to reconstruct what the packer ticked, photographed,
 * and noted at submit time — even if the stage's own status changes
 * later (e.g., manager reverts, packer redoes).
 *
 * Why a dedicated table (not just JSON columns on order_stages):
 *   - A stage can be completed multiple times in pathological cases
 *     (manager reverts, packer resubmits). One row per submit.
 *   - Keeps order_stages lean — that table is hot, this audit is cold.
 *   - Makes Super Admin's "show me what happened on submit X" query
 *     trivial.
 *
 * checklist_state_json shape:
 *   {
 *     "qa":      { "correct_print": true, "correct_size": true, ... },
 *     "packing": { "fold_and_pack": true, ... }
 *   }
 *
 * final_photos_json shape:
 *   {
 *     "completed_product": "path/to/file.jpg",
 *     "packed_boxes":      "path/to/file.jpg",
 *     "shipping_photo":    "path/to/file.jpg"
 *   }
 *
 * reject_summary_json mirrors the live tally computed at submit:
 *   { "total_pcs": 5, "pct": 0.05, "exceeds_threshold": true }
 */
return new class extends Migration {

    public function up(): void
    {
        Schema::create('qa_packer_task_completions', function (Blueprint $t) {
            $t->id();

            $t->foreignId('order_id')
                ->constrained('orders')
                ->cascadeOnDelete();

            $t->foreignId('order_stage_id')
                ->constrained('order_stages')
                ->cascadeOnDelete();

            $t->foreignId('submitted_by_user_id')
                ->constrained('users')
                ->restrictOnDelete();

            $t->json('checklist_state_json')->nullable();
            $t->json('final_photos_json')->nullable();
            $t->json('reject_summary_json')->nullable();

            $t->text('notes')->nullable();

            $t->timestamp('submitted_at')->useCurrent();
            $t->timestamps();

            $t->index(['order_id', 'submitted_at']);
            $t->index('order_stage_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('qa_packer_task_completions');
    }
};
