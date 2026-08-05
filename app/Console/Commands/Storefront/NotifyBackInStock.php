<?php

namespace App\Console\Commands\Storefront;

use App\Services\Storefront\StockAlertNotifier;
use Illuminate\Console\Command;

/**
 * Meant to run on the scheduler (every few minutes is plenty). Safe to run by hand
 * and safe to run twice: the notifier claims each alert before it sends.
 */
class NotifyBackInStock extends Command
{
    protected $signature = 'reefer:notify-back-in-stock';

    protected $description = 'Email anyone waiting on a size that is back in stock.';

    public function handle(StockAlertNotifier $notifier): int
    {
        $sent = $notifier->run();

        $this->info($sent === 1 ? '1 back-in-stock alert sent.' : "{$sent} back-in-stock alerts sent.");

        return self::SUCCESS;
    }
}
