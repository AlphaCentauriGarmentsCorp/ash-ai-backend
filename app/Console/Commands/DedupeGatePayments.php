<?php

namespace App\Console\Commands;

use App\Models\OrderPayment;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Collapse duplicate gate payments created before uploadProof was taught to
 * reconcile with ensureGatePayment's stub.
 *
 * For each (order_id, payment_type) group holding more than one row, keep the
 * single best row and remove the redundant ones:
 *   - If any row is VERIFIED, that row is terminal: keep it, drop every other
 *     (non-verified) row in the group.
 *   - Otherwise keep the row WITH a proof (most recent on a tie); drop the rest
 *     (the bare auto-created stubs).
 *
 * Dry-run by default; pass --apply to delete. Idempotent — a second run reports
 * nothing to do.
 *
 * Usage:
 *     php artisan payments:dedupe-gate-payments            # dry-run preview
 *     php artisan payments:dedupe-gate-payments --apply    # actually delete
 */
class DedupeGatePayments extends Command
{
    protected $signature = 'payments:dedupe-gate-payments
                            {--apply : Actually delete the redundant rows (otherwise dry-run)}';

    protected $description = 'Collapse duplicate (order, payment_type) gate payments into a single row';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');

        // Groups with more than one payment row of the same type on one order.
        $groups = OrderPayment::query()
            ->select('order_id', 'payment_type', DB::raw('COUNT(*) as c'))
            ->groupBy('order_id', 'payment_type')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        if ($groups->isEmpty()) {
            $this->info('No duplicate gate payments found. Nothing to do.');

            return self::SUCCESS;
        }

        $deleteIds = [];
        $rows = [];

        foreach ($groups as $g) {
            $payments = OrderPayment::where('order_id', $g->order_id)
                ->where('payment_type', $g->payment_type)
                ->get();

            $verified = $payments->firstWhere('status', OrderPayment::STATUS_VERIFIED);

            if ($verified) {
                // Verified row is the source of truth; drop everything else.
                $keep = $verified;
                $drop = $payments->where('status', '!=', OrderPayment::STATUS_VERIFIED);
            } else {
                // Prefer a row with a proof; tie-break on the most recent id.
                $keep = $payments->sort(function ($a, $b) {
                    $ap = $a->proof_path !== null ? 1 : 0;
                    $bp = $b->proof_path !== null ? 1 : 0;

                    return $ap !== $bp ? ($bp <=> $ap) : ($b->id <=> $a->id);
                })->first();
                $drop = $payments->reject(fn ($p) => $p->id === $keep->id);
            }

            foreach ($drop as $d) {
                $deleteIds[] = $d->id;
                $rows[] = [
                    "#{$g->order_id}",
                    $g->payment_type,
                    "#{$keep->id}" . ($keep->proof_path ? ' (proof)' : ''),
                    "#{$d->id} [{$d->status}]" . ($d->proof_path ? ' (proof)' : ''),
                ];
            }
        }

        $this->table(['order', 'type', 'keep', 'remove'], $rows);

        if (! $apply) {
            $this->warn(count($deleteIds) . ' redundant row(s) would be removed. Re-run with --apply to delete.');

            return self::SUCCESS;
        }

        $deleted = OrderPayment::whereIn('id', $deleteIds)->delete();
        $this->info("Removed {$deleted} redundant gate payment row(s).");

        return self::SUCCESS;
    }
}
