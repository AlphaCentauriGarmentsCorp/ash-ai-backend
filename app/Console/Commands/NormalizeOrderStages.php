<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Services\OrderStagesService;
use Illuminate\Console\Command;

/**
 * Normalises the workflow stages for every Order in the system.
 *
 * Use this once after applying Phase 1 to clean up orders that may
 * have legacy stage rows (e.g. `graphic_editing`, `sample_cutting`,
 * etc.) mixed with the new canonical 14-stage workflow.
 *
 * Usage:
 *     php artisan stages:normalize           # dry-run preview
 *     php artisan stages:normalize --apply   # actually run
 *     php artisan stages:normalize --apply --order=ASH-2026-000001
 */
class NormalizeOrderStages extends Command
{
    protected $signature = 'stages:normalize
                            {--apply : Actually run (otherwise dry-run only)}
                            {--order= : Limit to a single po_code}';

    protected $description = 'Prune legacy workflow stages and ensure every order has the canonical 14-stage sequence';

    public function handle(OrderStagesService $service): int
    {
        $apply = (bool) $this->option('apply');
        $poCode = $this->option('order');

        $query = Order::query();
        if ($poCode) {
            $query->where('po_code', $poCode);
        }

        $orders = $query->get();
        $total = $orders->count();

        if ($total === 0) {
            $this->warn('No orders found.');
            return self::SUCCESS;
        }

        $this->info(sprintf(
            '%s %d order(s)…',
            $apply ? 'Normalising' : '[Dry-run] Would normalise',
            $total
        ));

        $changed = 0;

        foreach ($orders as $order) {
            $before = $order->orderStages()->count();

            if ($apply) {
                $service->initializeForOrder($order);
                $after = $order->orderStages()->count();
            } else {
                // For dry-run we don't touch anything but report.
                $after = '?';
            }

            $this->line(sprintf(
                '  %s  (stages: %s → %s)',
                $order->po_code,
                $before,
                $after
            ));

            if ($apply && $before !== $after) {
                $changed++;
            }
        }

        if ($apply) {
            $this->info("Done. {$changed} order(s) had their stage list changed.");
        } else {
            $this->warn('Dry-run only. Re-run with --apply to actually write changes.');
        }

        return self::SUCCESS;
    }
}
