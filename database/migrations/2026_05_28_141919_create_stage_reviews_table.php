<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CSR Review Hub — per-stage review/feedback ledger.
 *
 * The Review Hub lets CSR / Super Admin / Admin APPROVE or REJECT the output
 * of any workflow stage, and lets the owning production role RESUBMIT after a
 * rejection. Each of those actions appends ONE immutable row here.
 *
 * Design (per the owner's confirmed spec, this session):
 *   - This is an ADVISORY layer. It does NOT move the workflow pointer and does
 *     NOT change order_stages.status. The proven linear stage engine is left
 *     untouched. "Is this stage currently rejected?" is derived from the LATEST
 *     row for the stage (a 'reject' with no later 'resubmit'/'approve'), NOT
 *     from order_stages.status — so a completed stage can carry an open
 *     rejection while the order keeps advancing.
 *   - Three decisions: 'approve', 'reject', 'resubmit'.
 *       approve   → reviewer (csr/super_admin/admin) accepts the output.
 *       reject    → reviewer rejects; comment REQUIRED, image OPTIONAL.
 *       resubmit  → owning role re-offers the work after fixing it; the
 *                   resubmit row closes the open rejection and bounces the
 *                   stage back into the hub for re-evaluation.
 *   - Mirrors stage_reject_logs' shape (photo on the public disk, order_id +
 *     order_stage_id, actor FK) so the frontend image-upload + display patterns
 *     are reused verbatim.
 */
return new class extends Migration {

    public function up(): void
    {
        if (Schema::hasTable('stage_reviews')) {
            return;
        }

        Schema::create('stage_reviews', function (Blueprint $t) {
            $t->id();

            $t->foreignId('order_id')
                ->constrained('orders')
                ->cascadeOnDelete();

            $t->foreignId('order_stage_id')
                ->constrained('order_stages')
                ->cascadeOnDelete();

            // The actor. For approve/reject this is the reviewer
            // (csr/super_admin/admin); for resubmit it's the owning-role user.
            $t->foreignId('actor_user_id')
                ->constrained('users')
                ->cascadeOnDelete();

            // 'approve' | 'reject' | 'resubmit'
            $t->string('decision', 16);

            // Required on reject (enforced at the request layer). Optional on
            // approve (reviewer praise/notes) and resubmit (what was fixed).
            $t->text('comment')->nullable();

            // Optional reject evidence image, stored on the 'public' disk under
            // stage-reviews/ — same convention as stage_reject_logs photos.
            $t->string('image_path')->nullable();

            $t->timestamps();

            // The hub reads "latest review per stage" constantly → index it.
            $t->index(['order_stage_id', 'id'], 'idx_stage_review_latest');
            $t->index(['order_id', 'created_at']);
            $t->index('decision');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stage_reviews');
    }
};