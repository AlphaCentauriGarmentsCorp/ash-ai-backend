<?php

namespace App\Console\Commands\Storefront\Stock;

use App\Support\Storefront\Stock\OrderPipeline;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Recompute product_variants.allocated from the orders that actually exist.
 *
 * `allocated` is DERIVED, not physical: it means "units spoken for by an order
 * that has not yet left the warehouse" (see OrderPipeline::POSITION — the
 * statuses in ALLOCATED_STATUSES hold stock, the rest do not). Checkout adds to
 * it and the stock manager's status transitions move it, so it is only ever
 * correct as long as every path that changes an order also changes stock.
 *
 * Two ways it drifts, both seen in this database:
 *
 *   1. The storefront's DEMO order tracker (POST /orders/{order}/advance, gated
 *      on reefer.orders.allow_manual_advance) used to write `stage` and `status`
 *      without calling OrderStock::apply(). An order walked to Delivered from the
 *      customer's own tracker kept its units reserved forever, which reads on the
 *      shop as SOLD OUT while the ERP still shows the stock on hand.
 *
 *   2. PhotographedProductSeeder used to seed `allocated` to hardcoded constants,
 *      asserting reservations against orders that never existed.
 *
 * Since `allocated` is fully derivable, drift is always repairable — that is the
 * advantage of not storing what you can compute. `on_hand` is NOT derivable (it
 * is a physical count) and is never touched here.
 *
 * Safe to re-run; only writes rows that disagree with the orders table.
 */
class ReconcileAllocatedStock extends Command
{
    protected $signature = 'stock:reconcile-allocated {--dry-run : Show the differences without writing}';

    protected $description = 'Recompute product_variants.allocated from open orders (never touches on_hand)';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');

        // Orders that still hold stock. `stage` is the index shared by
        // config('reefer.stages') and OrderPipeline::PIPELINE, so it — not the
        // human-readable status column — is what decides the ERP status.
        $holding = array_values(array_filter(
            array_keys(OrderPipeline::PIPELINE),
            fn ($i) => in_array(OrderPipeline::PIPELINE[$i], OrderPipeline::ALLOCATED_STATUSES, true)
        ));

        // order_items has no variant_id — a line identifies its variant by
        // (product_id, size), which is exactly the pair product_variants is
        // uniquely indexed on. Joining on both is what makes this a lookup rather
        // than a guess.
        $expected = DB::table('storefront_order_items as oi')
            ->join('storefront_orders as o', 'o.id', '=', 'oi.order_id')
            ->join('storefront_product_variants as v', function ($j) {
                $j->on('v.product_id', '=', 'oi.product_id')->on('v.size', '=', 'oi.size');
            })
            ->whereIn('o.stage', $holding)
            ->groupBy('v.id')
            ->select('v.id', DB::raw('SUM(oi.qty) AS units'))
            ->pluck('units', 'v.id')
            ->all();

        $drift = [];
        foreach (DB::table('storefront_product_variants as v')->join('storefront_products as p', 'p.id', '=', 'v.product_id')
            ->select('v.id', 'v.sku', 'v.size', 'v.on_hand', 'v.allocated', 'p.name')->get() as $v) {
            $want = (int) ($expected[$v->id] ?? 0);
            if ($want !== (int) $v->allocated) {
                $drift[] = [$v, $want];
            }
        }

        if ($drift === []) {
            $this->info('allocated already matches the orders table — nothing to reconcile.');

            return self::SUCCESS;
        }

        foreach ($drift as [$v, $want]) {
            $before = max(0, (int) $v->on_hand - (int) $v->allocated);
            $after = max(0, (int) $v->on_hand - $want);
            $this->line(sprintf(
                '  %-18s %-9s %-4s on_hand=%-4d allocated %d -> %d   sellable %d -> %d%s',
                $v->name, $v->sku, $v->size, $v->on_hand, $v->allocated, $want, $before, $after,
                $after > $before ? '  <fg=green>(back on sale)</>' : ''
            ));
        }

        if ($dry) {
            $this->comment(count($drift).' variant(s) would change. Re-run without --dry-run to apply.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($drift) {
            foreach ($drift as [$v, $want]) {
                DB::table('storefront_product_variants')->where('id', $v->id)
                    ->update(['allocated' => $want, 'updated_at' => now()]);
            }
        });

        $this->info('Reconciled '.count($drift).' variant(s).');

        return self::SUCCESS;
    }
}
