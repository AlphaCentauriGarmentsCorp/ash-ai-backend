<?php

namespace App\Console\Commands\Storefront\Stock;

use App\Support\Storefront\Stock\PendingProductEdits;
use Illuminate\Console\Command;

// The midnight batch behind "Push Product": applies every queued price / status /
// On Hand edit to the live catalog, then clears the queue. Scheduled daily at
// 12:00 AM Asia/Manila from routes/console.php; also safe to run by hand at any
// time.
//
// Unlike the standalone Stock manager — which held its own `inventory` table and
// pushed changes across to a separate website database — this module has only one
// database to write to. Draining the queue therefore writes straight to the shop's
// own `products` and `product_variants` rows (App\Support\Storefront\Stock\PendingProductEdits
// owns that mapping) and records each change in stock_inventory_log. That is the
// point of the queue: edits are staged in stock_pending_product_edits and reviewed
// before anything reaches live catalog data.
class PushPendingProductEdits extends Command
{
    protected $signature = 'stock:push-pending-product-edits';

    protected $description = 'Apply every queued Push Product edit (price / status / on-hand) to live inventory and clear the queue';

    public function handle(): int
    {
        $summary = PendingProductEdits::applyAll('scheduled');

        $this->info(sprintf(
            'Push Product: %d applied, %d already matched live, %d dropped (SKU deleted).',
            $summary['applied'],
            $summary['no_change'],
            $summary['sku_gone'],
        ));

        if (count($summary['failed']) > 0) {
            foreach ($summary['failed'] as $failure) {
                $this->error('Still queued (failed): '.$failure);
            }

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
