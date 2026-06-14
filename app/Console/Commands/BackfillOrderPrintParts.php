<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Services\OrderService;
use Illuminate\Console\Command;

/**
 * Backfill orders.print_parts_json (artwork + per-colour price) from each
 * order's linked quotation.
 *
 * Orders created via the Add Order form persisted a stripped print-parts
 * payload (placement + colour count only — no image, no price), so the
 * order's Print Parts table and the orders-list thumbnail rendered empty.
 * This re-hydrates existing quotation-linked orders from their quotation's
 * stored rows. DISPLAY data only; pricing is untouched.
 *
 * Usage:
 *     php artisan orders:backfill-print-parts                       # dry-run preview
 *     php artisan orders:backfill-print-parts --apply               # actually save
 *     php artisan orders:backfill-print-parts --apply --order=ASH-2026-000017
 */
class BackfillOrderPrintParts extends Command
{
    protected $signature = 'orders:backfill-print-parts
                            {--apply : Actually save (otherwise dry-run only)}
                            {--order= : Limit to a single po_code}';

    protected $description = "Re-hydrate orders.print_parts_json (artwork + price) from each order's linked quotation";

    public function handle(OrderService $orders): int
    {
        $apply = (bool) $this->option('apply');
        $poCode = $this->option('order');

        $query = Order::query()->whereNotNull('quotation_id');
        if ($poCode) {
            $query->where('po_code', $poCode);
        }

        $total = $query->count();
        if ($total === 0) {
            $this->warn('No quotation-linked orders found.');
            return self::SUCCESS;
        }

        $this->info(($apply ? 'Backfilling' : 'Dry-run over') . " {$total} quotation-linked order(s)...");

        $updated = 0;
        $skipped = 0;

        $query->orderBy('id')->chunkById(100, function ($chunk) use ($orders, $apply, &$updated, &$skipped) {
            foreach ($chunk as $order) {
                $current = is_array($order->print_parts_json) ? $order->print_parts_json : [];

                if (empty($current)) {
                    $skipped++;
                    continue;
                }

                // Rows already carrying artwork AND price on every part need no work.
                if (! $this->partsNeedBackfill($current)) {
                    $skipped++;
                    continue;
                }

                $enriched = $orders->enrichPrintPartsFromQuotation($current, $order->quotation_id);

                if (! $apply) {
                    $updated++;
                    $this->line("  • {$order->po_code} (would update)");
                    continue;
                }

                $order->print_parts_json = $enriched;
                $order->saveQuietly();
                $updated++;
                $this->line("  ✓ {$order->po_code}");
            }
        });

        $this->newLine();
        $this->info(($apply ? 'Updated' : 'Would update') . " {$updated} order(s); skipped {$skipped}.");
        if (! $apply) {
            $this->comment('Dry-run only — re-run with --apply to save.');
        }

        return self::SUCCESS;
    }

    /**
     * A parts array needs backfill when any row is missing artwork or price.
     *
     * @param  array<int, mixed>  $parts
     */
    protected function partsNeedBackfill(array $parts): bool
    {
        foreach ($parts as $row) {
            if (! is_array($row)) {
                continue;
            }
            $hasImage = ! empty($row['image']) || ! empty($row['image_path']) || ! empty($row['image_link']);
            $hasPrice = isset($row['price_per_color']) || isset($row['pricePerColor']);
            if (! $hasImage || ! $hasPrice) {
                return true;
            }
        }
        return false;
    }
}
