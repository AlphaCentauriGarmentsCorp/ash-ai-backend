<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * ASH AI — Drop the three pre-production workflow stages
 * (inquiry, quotation, quotation_approval) from every order's pipeline.
 *
 * WHY: those steps are owned UPSTREAM by the Inquiry/Quotation modules, before
 * an Order ever exists. Inside an Order they were redundant. WorkflowStages.php
 * is the source of truth for NEW orders (it no longer lists them); this
 * migration is data hygiene for any EXISTING order rows:
 *
 *   1. delete order_stages rows for the three removed slugs, and
 *   2. repoint any order whose cached workflow_status was one of them to
 *      'payment_verification_sample' (the new first/active stage).
 *
 * The orders.workflow_status column is always (re)computed by
 * OrderStagesService from the live stages, so its stale 'inquiry' DB *default*
 * is harmless and intentionally left untouched (changing a column default is
 * the kind of schema op that has bitten this project before — not worth it for
 * a value that is never read in practice).
 *
 * down() is intentionally a no-op: deleted stage rows cannot be faithfully
 * resurrected, and on this project's data (test fixtures) there is nothing to
 * restore.
 */
return new class extends Migration
{
    /** @var array<int,string> */
    private const REMOVED = ['inquiry', 'quotation', 'quotation_approval'];

    public function up(): void
    {
        DB::table('order_stages')
            ->whereIn('stage', self::REMOVED)
            ->delete();

        DB::table('orders')
            ->whereIn('workflow_status', self::REMOVED)
            ->update(['workflow_status' => 'payment_verification_sample']);
    }

    public function down(): void
    {
        // No-op by design — the removed stage rows are lossy and not restored.
    }
};
