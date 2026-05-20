<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 7-B Bundle 1 — QA & Packing checklist lookup tables.
 *
 * v1 is "fixed 7+7 items" per spec, but storing them in lookup tables
 * (not hardcoded constants) gives us future configurability for free.
 * Same architectural choice as reject_reasons.
 *
 * Per Phase 7-B.8 Q5 decision: in-progress tick state lives in the
 * browser's localStorage. Completed (post-submit) tick state lands in
 * a `checklist_state_json` column on the qa_packer_task_completions
 * table (NOT created here — that's a Bundle 4 artefact when SUBMIT
 * COMPLETED is wired).
 *
 * Two tiny tables with the same shape rather than one unified table
 * with a `kind` discriminator — keeps queries readable and avoids
 * accidentally mixing QA and packing items in a single FK reference.
 */
return new class extends Migration {

    public function up(): void
    {
        Schema::create('qa_checklist_items', function (Blueprint $t) {
            $t->id();
            $t->string('slug', 64)->unique();
            $t->string('label', 128);
            $t->unsignedSmallInteger('display_order')->default(0);
            $t->boolean('active')->default(true);
            $t->timestamps();

            $t->index('active');
            $t->index('display_order');
        });

        Schema::create('packing_checklist_items', function (Blueprint $t) {
            $t->id();
            $t->string('slug', 64)->unique();
            $t->string('label', 128);
            $t->unsignedSmallInteger('display_order')->default(0);
            $t->boolean('active')->default(true);
            $t->timestamps();

            $t->index('active');
            $t->index('display_order');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('packing_checklist_items');
        Schema::dropIfExists('qa_checklist_items');
    }
};
