<?php

namespace App\Services;

use App\Models\Materials;
use App\Models\Order;
use App\Models\PurchaseRequest;
use App\Models\PurchaseRequestItem;
use App\Models\MaterialRequest;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Phase 3 — PurchaseRequestService
 *
 * Owns the PR lifecycle:
 *
 *   createFromMaterialRequest()
 *               – called by MaterialRequestService::approve when stock
 *                 is short; auto-builds a PR for the shortage.
 *
 *   create()    – manual creation by purchasing/manager (rare in
 *                 v1; mostly used for ad-hoc top-up purchases).
 *
 *   approve()   – manager sets PR.status = approved.
 *   markOrdered()  – purchasing role records that the order was placed
 *                    with the supplier.
 *   markReceived() – warehouse records goods received → increments
 *                    materials.stock_on_hand.
 *   cancel()    – any non-received PR can be cancelled.
 *
 * Architecture note: ONE PR per order. If multiple MRs trigger PRs for
 * the same order, the second MR's items get *appended* to the existing
 * pending PR for that order rather than creating a new one. This
 * matches the "one PR per order" decision.
 */
class PurchaseRequestService
{
    public function __construct(
        protected NotificationService $notifications,
    ) {
    }

    // =====================================================================
    // CREATE (auto from MR)
    // =====================================================================

    /**
     * Build a Purchase Request from the short items of a Material Request.
     *
     * @param  MaterialRequest  $mr
     * @param  Collection<int, array{item:object, material:Materials, short_qty:float}>  $shortItems
     * @param  User  $actor  the manager approving the MR (becomes the PR creator)
     * @return PurchaseRequest
     */
    public function createFromMaterialRequest(
        MaterialRequest $mr,
        Collection $shortItems,
        User $actor,
    ): PurchaseRequest {
        return DB::transaction(function () use ($mr, $shortItems, $actor) {
            // Find an existing PENDING PR for this order. If one exists,
            // append the new short items to it. This honors the
            // "one PR per order" rule even when multiple MRs trigger.
            $pr = PurchaseRequest::where('order_id', $mr->order_id)
                ->where('status', PurchaseRequest::STATUS_PENDING)
                ->lockForUpdate()
                ->first();

            if (! $pr) {
                $pr = PurchaseRequest::create([
                    'pr_code'             => $this->generateCode('PR'),
                    'order_id'            => $mr->order_id,
                    'material_request_id' => $mr->id,
                    'supplier_id'         => $shortItems->first()['material']->supplier_id ?? null,
                    'status'              => PurchaseRequest::STATUS_PENDING,
                    'total_amount'        => 0,
                    'reason'              => "Auto-spawned from material request {$mr->mr_code}.",
                ]);
            }

            // Append the short items as PR line items.
            foreach ($shortItems as $entry) {
                $material = $entry['material'];
                $shortQty = (float) $entry['short_qty'];
                if ($shortQty <= 0) continue;

                $unitPrice = (float) ($material->price ?? 0);
                $lineTotal = round($shortQty * $unitPrice, 2);

                PurchaseRequestItem::create([
                    'purchase_request_id' => $pr->id,
                    'material_id'         => $material->id,
                    'quantity'            => $shortQty,
                    'unit_price'          => $unitPrice,
                    'line_total'          => $lineTotal,
                    'unit'                => $material->unit,
                ]);
            }

            // Recompute total_amount from all line items (covers both
            // freshly-created and appended-to PRs).
            $newTotal = PurchaseRequestItem::where('purchase_request_id', $pr->id)
                ->sum('line_total');
            $pr->update(['total_amount' => $newTotal]);

            return $pr->fresh(['items.material', 'order', 'materialRequest']);
        }, 3);
    }

    // =====================================================================
    // APPROVE
    // =====================================================================

    public function approve(PurchaseRequest $pr, ?User $actor = null): PurchaseRequest
    {
        $actor = $actor ?? Auth::user();
        if (! $actor) {
            throw ValidationException::withMessages(['auth' => 'Authentication required.']);
        }

        if (! $pr->isPending()) {
            throw ValidationException::withMessages([
                'status' => "Purchase request is {$pr->status}; only pending PRs can be approved.",
            ]);
        }

        $pr->update([
            'status'              => PurchaseRequest::STATUS_APPROVED,
            'approved_by_user_id' => $actor->id,
            'approved_at'         => now(),
        ]);

        return $pr->fresh(['items.material', 'order']);
    }

    // =====================================================================
    // MARK ORDERED
    // =====================================================================

    public function markOrdered(PurchaseRequest $pr, ?User $actor = null): PurchaseRequest
    {
        $actor = $actor ?? Auth::user();
        if (! $actor) {
            throw ValidationException::withMessages(['auth' => 'Authentication required.']);
        }

        if (! $pr->isApproved()) {
            throw ValidationException::withMessages([
                'status' => "Purchase request must be approved before it can be marked ordered (current: {$pr->status}).",
            ]);
        }

        $pr->update([
            'status'     => PurchaseRequest::STATUS_ORDERED,
            'ordered_at' => now(),
        ]);

        return $pr->fresh(['items.material', 'order']);
    }

    // =====================================================================
    // MARK RECEIVED  (← this is where stock goes UP)
    // =====================================================================

    public function markReceived(PurchaseRequest $pr, ?User $actor = null): PurchaseRequest
    {
        $actor = $actor ?? Auth::user();
        if (! $actor) {
            throw ValidationException::withMessages(['auth' => 'Authentication required.']);
        }

        if (! $pr->isOrdered()) {
            throw ValidationException::withMessages([
                'status' => "Purchase request must be marked ordered before it can be received (current: {$pr->status}).",
            ]);
        }

        return DB::transaction(function () use ($pr) {
            // Increment stock for each line item.
            $pr->load('items');
            foreach ($pr->items as $item) {
                $material = Materials::lockForUpdate()->find($item->material_id);
                if (! $material) continue;
                $material->increment('stock_on_hand', (float) $item->quantity);
            }

            $pr->update([
                'status'      => PurchaseRequest::STATUS_RECEIVED,
                'received_at' => now(),
            ]);

            return $pr->fresh(['items.material', 'order']);
        }, 3);
    }

    // =====================================================================
    // CANCEL
    // =====================================================================

    public function cancel(PurchaseRequest $pr, ?User $actor = null): PurchaseRequest
    {
        $actor = $actor ?? Auth::user();
        if (! $actor) {
            throw ValidationException::withMessages(['auth' => 'Authentication required.']);
        }

        if ($pr->isReceived()) {
            throw ValidationException::withMessages([
                'status' => 'Cannot cancel a received purchase request — stock has already been added.',
            ]);
        }
        if ($pr->isCancelled()) {
            throw ValidationException::withMessages([
                'status' => 'Purchase request is already cancelled.',
            ]);
        }

        $pr->update(['status' => PurchaseRequest::STATUS_CANCELLED]);
        return $pr->fresh(['items.material', 'order']);
    }

    // =====================================================================
    // Notifications wrappers — called by the controller after each
    // service method commits.
    // =====================================================================

    public function announceCreated(PurchaseRequest $pr): void
    {
        $this->notifications->purchaseRequestCreated($pr);
    }

    public function announceDecided(PurchaseRequest $pr, string $decision): void
    {
        $this->notifications->purchaseRequestDecided($pr, $decision);
    }

    public function announceReceived(PurchaseRequest $pr): void
    {
        $this->notifications->purchaseRequestReceived($pr);
    }

    // =====================================================================
    // Internals
    // =====================================================================

    protected function generateCode(string $prefix): string
    {
        $year = now()->year;

        $last = PurchaseRequest::whereYear('created_at', $year)
            ->lockForUpdate()
            ->selectRaw("CAST(SUBSTRING_INDEX(pr_code, '-', -1) AS UNSIGNED) AS num")
            ->orderByDesc('num')
            ->value('num');

        $next = ($last ?? 0) + 1;
        return sprintf('%s-%d-%06d', $prefix, $year, $next);
    }
}
