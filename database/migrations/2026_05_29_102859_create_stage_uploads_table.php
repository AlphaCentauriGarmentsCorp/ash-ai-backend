<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3 — generic per-stage proof-of-work uploads.
 *
 * A single, stage-agnostic attachment table so ANY workflow stage can attach
 * photo/file evidence of its output. This closes the Review-Hub gap for stages
 * that previously produced no reviewable artifact (ScreenMaker) and for
 * mass-production cut/print/sew proof-of-work (the *sample* versions already
 * have stage_sample_uploads; mass production had nothing).
 *
 * Deliberately generic and additive:
 *   - It does NOT replace the specialized tables (order_design_files,
 *     stage_sample_uploads, QA final photos, stage_reject_logs). Those keep
 *     their richer, domain-specific shapes. This is the catch-all for stages
 *     that only need "attach a few photos/files as proof".
 *   - One row per file (unlike the front/back columns of stage_sample_uploads)
 *     so a stage can have any number of attachments.
 *   - `category` is a free-text tag (e.g. 'proof', 'screen', 'cutting',
 *     'printing', 'sewing') so the same table serves many stages without a
 *     migration per stage — mirrors how order_stages.stage is free-text.
 */
return new class extends Migration {

    public function up(): void
    {
        if (Schema::hasTable('stage_uploads')) {
            return;
        }

        Schema::create('stage_uploads', function (Blueprint $t) {
            $t->id();

            $t->foreignId('order_id')
                ->constrained('orders')
                ->cascadeOnDelete();

            $t->foreignId('order_stage_id')
                ->constrained('order_stages')
                ->cascadeOnDelete();

            $t->foreignId('uploaded_by_user_id')
                ->constrained('users')
                ->cascadeOnDelete();

            // Free-text grouping tag (e.g. 'proof', 'screen', 'cutting').
            // Defaults to 'proof' for the common proof-of-work case.
            $t->string('category', 32)->default('proof');

            // File metadata — stored on the 'public' disk under stage-uploads/.
            $t->string('file_path', 255);
            $t->string('original_name', 255)->nullable();
            $t->string('mime_type', 64)->nullable();
            $t->unsignedBigInteger('size_bytes')->nullable();

            // Optional caption/notes for the attachment.
            $t->text('notes')->nullable();

            $t->timestamps();

            $t->index(['order_id', 'order_stage_id']);
            $t->index(['order_stage_id', 'category']);
            $t->index('uploaded_by_user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stage_uploads');
    }
};