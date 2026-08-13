<?php

namespace App\Console\Commands\Storefront\Stock;

use App\Support\Storefront\Stock\InventoryData;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Repair designs stranded by the missing product-level activation.
 *
 * Until InventoryData::syncProductActive() existed, nothing in the codebase could
 * write products.is_active = 1. The Product Catalog's status pill flipped
 * product_variants.is_active, and the storefront filters on BOTH — so a design
 * whose sizes had been switched on quite deliberately still never reached the
 * shop. Every product ever created through the stock manager is in that state.
 *
 * This walks the same rule syncProductActive() applies from here on — a design is
 * live when at least one of its sizes is live — and backfills products.blurb,
 * which the Add Product form never set and the product page interpolates
 * unguarded, so a null arrives on screen as the four letters "null".
 *
 * Safe to re-run: it only writes rows that disagree with the rule.
 */
class RepairProductPublishState extends Command
{
    protected $signature = 'stock:repair-publish-state {--dry-run : List what would change without writing}';

    protected $description = 'Point every design\'s is_active at the truth of its sizes, and backfill missing storefront copy';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');

        // A design is live iff it has a live size. One grouped query rather than a
        // query per product, because this runs over the whole catalogue.
        $liveIds = DB::table('storefront_product_variants')
            ->where('is_active', 1)
            ->distinct()
            ->pluck('product_id')
            ->all();
        $live = array_flip($liveIds);

        $products = DB::table('storefront_products')->orderBy('id')->get();

        $publish = [];
        $retire = [];
        $blurbs = [];

        foreach ($products as $p) {
            $shouldBeLive = isset($live[$p->id]);
            if ($shouldBeLive && ! $p->is_active) {
                $publish[] = $p;
            } elseif (! $shouldBeLive && $p->is_active) {
                $retire[] = $p;
            }

            if ($p->blurb === null || trim((string) $p->blurb) === '') {
                $blurbs[] = $p;
            }
        }

        foreach ($publish as $p) {
            $this->line("  <fg=green>publish</>  {$p->name}  (/product/{$p->slug})");
        }
        foreach ($retire as $p) {
            $this->line("  <fg=yellow>retire </>  {$p->name}  — no active size");
        }
        foreach ($blurbs as $p) {
            $this->line("  <fg=cyan>blurb  </>  {$p->name}");
        }

        if (! $publish && ! $retire && ! $blurbs) {
            $this->info('Nothing to repair — every design already matches its sizes.');

            return self::SUCCESS;
        }

        if ($dry) {
            $this->comment(sprintf(
                'Dry run. %d to publish, %d to retire, %d blurb(s) to seed.',
                count($publish), count($retire), count($blurbs)
            ));

            return self::SUCCESS;
        }

        DB::transaction(function () use ($publish, $retire, $blurbs) {
            foreach (array_merge($publish, $retire) as $p) {
                InventoryData::syncProductActive((int) $p->id);
            }
            foreach ($blurbs as $p) {
                DB::table('storefront_products')->where('id', $p->id)->update([
                    'blurb' => InventoryData::openingBlurb((string) $p->name),
                    'updated_at' => now(),
                ]);
            }
        });

        $this->info(sprintf(
            'Repaired: %d published, %d retired, %d blurb(s) seeded.',
            count($publish), count($retire), count($blurbs)
        ));

        return self::SUCCESS;
    }
}
