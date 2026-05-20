<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 6-A — CSR Hub backend
 *
 * `client_approvals` is the generic approval-event table. Unlike the
 * existing `action.approve-quotation` flag (which only marks
 * quotation-level approval), this table tracks ALL approval kinds
 * a CSR shepherds through the workflow:
 *
 *   - quotation         — initial price acceptance
 *   - design            — final artwork before screen-making
 *   - mockup            — visual proof before sample creation
 *   - sample            — physical sample sign-off before mass-prod
 *   - production_change — mid-production change request
 *   - delivery          — final receipt confirmation
 *
 * The status flow is:
 *   waiting → approved | revision_requested | rejected
 *
 * Screenshots / approved-mockups are stored on Storage::disk('public');
 * `screenshot_path` is the relative path. Public URL is built at the
 * controller-presenter layer (Storage::disk('public')->url()).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_approvals', function (Blueprint $t) {
            $t->id();

            // Order linkage (cascade)
            $t->unsignedBigInteger('order_id');
            $t->foreign('order_id', 'ca_order_id_fk')
                ->references('id')->on('orders')->cascadeOnDelete();

            // Approval classification
            // quotation / design / mockup / sample / production_change / delivery
            $t->string('kind', 24);

            // State machine
            // waiting / approved / revision_requested / rejected
            $t->string('status', 24)->default('waiting');

            $t->timestamp('requested_at')->nullable();
            $t->timestamp('responded_at')->nullable();

            // Storage::disk('public') relative path
            $t->string('screenshot_path', 255)->nullable();

            // What the client said (verbatim or paraphrase)
            $t->text('client_response_notes')->nullable();

            // CSR-side context, not shown to client
            $t->text('internal_notes')->nullable();

            // Who triggered the approval request
            $t->unsignedBigInteger('requested_by_user_id')->nullable();
            $t->foreign('requested_by_user_id', 'ca_requested_by_fk')
                ->references('id')->on('users')->nullOnDelete();

            // Who recorded the client's response (may be CSR or admin)
            $t->unsignedBigInteger('recorded_by_user_id')->nullable();
            $t->foreign('recorded_by_user_id', 'ca_recorded_by_fk')
                ->references('id')->on('users')->nullOnDelete();

            $t->timestamps();

            // Hot path: dashboard "Client Approvals Needed" and per-order
            // approval-history view
            $t->index(['order_id', 'kind'], 'ca_order_kind_idx');
            $t->index('status',             'ca_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_approvals');
    }
};
