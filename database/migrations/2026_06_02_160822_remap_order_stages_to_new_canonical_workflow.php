<?php

use App\Models\Order;
use App\Services\OrderStagesService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Remap every existing order's stages onto the new canonical workflow
 * (ASH AI Change Request 2026-06-02 — Changes 19, 20, 21).
 *
 * The old flat workflow collapsed sample + mass build into single stages
 * (sample_creation, mass_production) and had a generic quality_control /
 * packing pair. The new workflow routes both phases through discrete,
 * role-handled stages, adds a Material Prep stage + a Balance payment gate,
 * and forks Screen Making ‖ Material Prep in the sample phase.
 *
 * Strategy:
 *   1. RENAME legacy slugs to their new equivalents IN PLACE. This preserves
 *      each row's id, timestamps, audit logs and uploads (which reference
 *      order_stage_id, not the slug). Renaming first is essential: if we let
 *      initializeForOrder() run on the old slugs it would PRUNE them as
 *      "non-canonical" and destroy that history.
 *   2. Re-run OrderStagesService::initializeForOrder() per order. That service
 *      re-sequences rows to the new tiers, BACKFILLS the brand-new stages
 *      (material_prep_sample, sample_printing/sewing/packing, mass_printing/
 *      sewing, payment_verification_balance) — marking them completed when
 *      they sit behind the order's progress high-water mark and pending when
 *      ahead — and promotes the now-eligible tier. It writes audit rows but
 *      fires NO notifications, so the migration is silent.
 *
 * Down() is a deliberate no-op: this is a one-way data reshape and the old
 * collapsed stages cannot be faithfully reconstructed from the split ones.
 */
return new class extends Migration
{
    /**
     * Legacy slug => new slug. Only slugs that CHANGED are listed; unchanged
     * slugs (inquiry, quotation, screen_making, sample_approval,
     * payment_verification_sample/_mass, delivery, order_completed,
     * client_notification, graphic_artwork, quotation_approval) are left as-is.
     */
    private array $slugRemap = [
        'sample_creation'   => 'sample_cutting',     // sample build entry point
        'mass_production'   => 'mass_cutting',        // mass build entry point
        'purchase_materials'=> 'material_prep_mass',  // sourcing/prep stage
        'quality_control'   => 'mass_qa',             // trimming/QA
        'packing'           => 'mass_packing',        // mass packing
    ];

    public function up(): void
    {
        if (! DB::getSchemaBuilder()->hasTable('order_stages')) {
            return;
        }

        // 1. Rename legacy slugs in place (id / history preserved). Guard
        //    against collisions: only rename when the target slug doesn't
        //    already exist for that order (defensive — shouldn't happen, the
        //    new slugs didn't exist before this migration).
        foreach ($this->slugRemap as $old => $new) {
            $rows = DB::table('order_stages')->where('stage', $old)->get(['id', 'order_id']);
            foreach ($rows as $row) {
                $clash = DB::table('order_stages')
                    ->where('order_id', $row->order_id)
                    ->where('stage', $new)
                    ->exists();
                if (! $clash) {
                    DB::table('order_stages')->where('id', $row->id)->update(['stage' => $new]);
                }
            }
        }

        // 2. Re-initialize each order so the service re-sequences + backfills
        //    the new stages and promotes the eligible tier. Use the real
        //    service so the backfill / high-water logic stays single-sourced.
        $service = app(OrderStagesService::class);

        Order::query()
            ->when(
                in_array('deleted_at', DB::getSchemaBuilder()->getColumnListing('orders'), true),
                fn ($q) => $q->withTrashed()
            )
            ->orderBy('id')
            ->chunkById(100, function ($orders) use ($service) {
                foreach ($orders as $order) {
                    $service->initializeForOrder($order);
                }
            });
    }

    public function down(): void
    {
        // One-way data reshape — not safely reversible.
    }
};