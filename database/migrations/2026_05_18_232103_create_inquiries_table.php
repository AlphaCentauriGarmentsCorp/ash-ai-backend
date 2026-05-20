<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 6-A — CSR Hub backend
 *
 * `inquiries` is the pre-quotation lead table. CSR captures inbound
 * client questions here BEFORE a quotation exists, then converts
 * promising inquiries into draft quotations via
 * InquiryService::convertToQuotation() (which calls
 * QuotationService::createDraft() — no email, no PDF).
 *
 * `quotation_id` is a back-reference; it's null until conversion and
 * is then set to point to the created quotation row.
 *
 * Foreign-key constraint names use short explicit identifiers (BUG-014)
 * even though the table+col combos are well under 64 chars, for
 * consistency with the rest of the Phase 6-A bundle.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inquiries', function (Blueprint $t) {
            $t->id();

            // Identity
            $t->string('inquiry_code', 32)->unique();   // INQ-YYYY-NNNNNN

            // Client linkage (optional — anon inquiry possible)
            $t->unsignedBigInteger('client_id')->nullable();
            $t->foreign('client_id', 'inq_client_id_fk')
                ->references('id')->on('clients')->nullOnDelete();

            // Snapshotted client identity (required even if client_id is null)
            $t->string('client_name');                  // REQUIRED
            $t->string('client_email')->nullable();
            $t->string('client_contact')->nullable();
            $t->string('brand_name')->nullable();

            // Lead-source channel
            // FB / TikTok / Walk-in / Referral / Repeat / Other
            $t->string('source', 32)->nullable();

            // Communication links (the same pattern Clients + Orders use)
            $t->string('messenger_link')->nullable();
            $t->string('facebook_link')->nullable();
            $t->string('gc_link')->nullable();

            // Lead intent — free-text product description
            $t->text('product_interest')->nullable();

            // Funnel status — default 'new'
            // new / contacted / quoted / converted / lost
            $t->string('status', 16)->default('new');

            // Ownership — assigned CSR (nullable, FK on users)
            $t->unsignedBigInteger('assigned_csr_user_id')->nullable();
            $t->foreign('assigned_csr_user_id', 'inq_assigned_csr_fk')
                ->references('id')->on('users')->nullOnDelete();

            // Conversion back-reference — set by InquiryService::convertToQuotation()
            $t->unsignedBigInteger('quotation_id')->nullable();
            $t->foreign('quotation_id', 'inq_quotation_id_fk')
                ->references('id')->on('quotations')->nullOnDelete();

            // Free-text internal notes (NOT shared with client)
            $t->text('internal_notes')->nullable();

            $t->timestamps();

            // Hot-path indexes (matches the dashboard "Pending Inquiries" + the
            // CSR's "my inquiries" view + client-detail page's inquiry list)
            $t->index('status',                'inq_status_idx');
            $t->index('assigned_csr_user_id',  'inq_assigned_csr_idx');
            $t->index('client_id',             'inq_client_id_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inquiries');
    }
};
