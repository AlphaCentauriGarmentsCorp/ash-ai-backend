<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Services\OrderService;
use Illuminate\Console\Command;

/**
 * Fold each existing order's sample into its Grand Total + 60/40 split so the
 * order matches the quotation (the conversion previously priced orders
 * sample-free, keeping the sample only on the separate OrderSamples table).
 *
 * Idempotent: the sample-free production subtotal is rebuilt from the order's
 * own items/addons/fees each run, so re-running never double-folds.
 *
 * Usage:
 *     php artisan orders:fold-sample-total                       # dry-run preview
 *     php artisan orders:fold-sample-total --apply               # actually save
 *     php artisan orders:fold-sample-total --apply --order=ASH-2026-000017
 */
class FoldSampleIntoOrderTotal extends Command
{
    protected $signature = 'orders:fold-sample-total
                            {--apply : Actually save (otherwise dry-run only)}
                            {--order= : Limit to a single po_code}';

    protected $description = "Fold each order's sample into its Grand Total + 60/40 split, matching the quotation";

    public function handle(OrderService $orders): int
    {
        $apply = (bool) $this->option('apply');
        $poCode = $this->option('order');

        $query = Order::query()->with('samples');
        if ($poCode) {
            $query->where('po_code', $poCode);
        }

        $total = $query->count();
        if ($total === 0) {
            $this->warn('No orders found.');
            return self::SUCCESS;
        }

        $this->info(($apply ? 'Folding' : 'Dry-run over') . " {$total} order(s)...");

        $updated = 0;
        $skipped = 0;

        $query->orderBy('id')->chunkById(100, function ($chunk) use ($orders, $apply, &$updated, &$skipped) {
            foreach ($chunk as $order) {
                $samples = $order->samples->map(fn ($s) => [
                    'size'        => $s->size,
                    'quantity'    => $s->quantity,
                    'unit_price'  => $s->unit_price,
                    'total_price' => $s->total_price,
                ])->all();

                // Rebuild the sample-free production subtotal from the order's
                // own components so the fold is idempotent.
                $items = is_array($order->items_json) ? $order->items_json : [];
                $addons = is_array($order->addons_json) ? $order->addons_json : [];
                $bd = is_array($order->breakdown_json) ? $order->breakdown_json : [];
                $itemsTotal = collect($items)->sum(fn ($i) => (float) ($i['total_amount'] ?? $i['total'] ?? 0));
                $addonsTotal = collect($addons)->sum(fn ($a) => (float) ($a['total'] ?? 0));
                $fees = (float) ($bd['custom_pattern_fee'] ?? 0) + (float) ($bd['dtf_order_total'] ?? 0);
                $sampleFreeSubtotal = round($itemsTotal + $addonsTotal + $fees, 2);

                $fold = $orders->foldSampleIntoTotals(
                    $sampleFreeSubtotal,
                    $samples,
                    $order->discount_type,
                    (float) $order->discount_price,
                );

                if ($fold === null) {
                    $skipped++;
                    continue;
                }

                $alreadyFolded =
                    abs((float) $order->grand_total - $fold['grand_total']) < 0.01 &&
                    abs((float) $order->subtotal - $fold['subtotal']) < 0.01;
                if ($alreadyFolded) {
                    $skipped++;
                    continue;
                }

                if (! $apply) {
                    $updated++;
                    $this->line("  • {$order->po_code}: ₱{$order->grand_total} -> ₱{$fold['grand_total']}");
                    continue;
                }

                $bd['sample_breakdown'] = $fold['sample_breakdown'];
                $bd['downpayment'] = $fold['downpayment'];
                $bd['balance'] = $fold['balance'];

                $order->subtotal = $fold['subtotal'];
                $order->discount_amount = $fold['discount_amount'];
                $order->grand_total = $fold['grand_total'];
                $order->breakdown_json = $bd;
                $order->saveQuietly();

                $updated++;
                $this->line("  ✓ {$order->po_code}: ₱{$fold['grand_total']}");
            }
        });

        $this->newLine();
        $this->info(($apply ? 'Updated' : 'Would update') . " {$updated} order(s); skipped {$skipped} (no sample / already folded).");
        if (! $apply) {
            $this->comment('Dry-run only — re-run with --apply to save.');
        }

        return self::SUCCESS;
    }
}
