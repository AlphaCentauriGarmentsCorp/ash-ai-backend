<?php

namespace App\Services\Storefront;

use App\Mail\Storefront\BackInStockMail;
use App\Models\Storefront\StockAlert;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * The restock half of back-in-stock alerts.
 *
 * Nothing pushes here — stock moves in half a dozen places (checkout, a cancelled
 * order, a manual restock), and hanging a mail off every one of them would be a
 * different bug each time. This sweeps instead: whatever is waiting on a variant
 * that now has stock gets told, once.
 */
class StockAlertNotifier
{
    /** @return int how many mails actually went out */
    public function run(): int
    {
        $alerts = StockAlert::query()
            ->pending()
            ->whereHas('variant', fn ($q) => $q
                ->whereRaw('on_hand > allocated')
                ->where('is_active', true)
                // A pulled product is not "back" — it is gone. Do not mail a link to
                // a page the catalog no longer serves.
                ->whereHas('product', fn ($p) => $p->where('is_active', true)))
            // Everything the mail needs, in two extra queries rather than two per
            // alert. (The standalone app also had Model::preventLazyLoading() on, so
            // a missed eager load threw outright; that switch is global and is NOT
            // set here, because it would police the ERP's own queries too. The eager
            // load still earns its keep on query count alone.)
            ->with(['user', 'variant.product'])
            // Oldest ask first: whoever has been waiting longest hears first.
            ->orderBy('id')
            ->limit(max((int) config('reefer.stock_alerts.max_per_run'), 0))
            ->get();

        $sent = 0;

        foreach ($alerts as $alert) {
            if ($this->notify($alert)) {
                $sent++;
            }
        }

        return $sent;
    }

    private function notify(StockAlert $alert): bool
    {
        // Claim the row before the mail goes out, not after. The UPDATE only matches
        // while notified_at is still null, so it is both the stamp and the decision
        // to send: two overlapping runs cannot both win it, and a crash between here
        // and the send loses one mail rather than sending two.
        $claimed = DB::transaction(fn () => StockAlert::query()
            ->whereKey($alert->getKey())
            ->whereNull('notified_at')
            ->update(['notified_at' => now()]));

        if ($claimed === 0) {
            return false;
        }

        try {
            Mail::to($alert->user->email)->send(new BackInStockMail($alert->user, $alert->variant));
        } catch (Throwable $e) {
            // One dead address is not a reason to abandon the rest of the batch.
            report($e);

            return false;
        }

    
        return true;
    }
}
