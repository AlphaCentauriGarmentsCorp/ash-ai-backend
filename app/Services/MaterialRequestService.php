<?php

namespace App\Services;

use App\Models\MaterialRequest;
use App\Models\MaterialRequestItem;
use App\Models\Materials;
use App\Models\Order;
use App\Models\OrderStage;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Phase 3 — MaterialRequestService
 *
 * Owns the MR lifecycle. Concretely:
 *
 *   create()  – validates the requester is the right role for the order's
 *               current stage, snapshots stock per item, creates pending MR.
 *   approve() – manager-only. If any item is short, auto-spawns a PR
 *               (linked back) and sets MR.status = auto_pr. Otherwise
 *               decrements materials.stock_on_hand and sets status = approved.
 *   reject()  – manager-only. Records rejection_reason, no stock changes.
 *
 * Stage-restriction rule (per architectural decision):
 *   Only the user assigned to the order's CURRENT order_stages row can
 *   create an MR. SuperAdmin / Admin / GeneralManager bypass this.
 *
 * SM Rework CP1 — create() now accepts an optional explicit stage_id.
 * Parallel forks (screen_making ‖ material_prep_sample share sequence 6)
 * make the "current" stage ambiguous, so a portal that knows exactly
 * which station the request is for passes stage_id. When present (and
 * belonging to the order) it is used both for the stage-restriction
 * check AND as the MR's stage_id, so the request reflects back in that
 * station's own portal section. When omitted, behaviour is unchanged.
 */
class MaterialRequestService
{
    public function __construct(
        protected NotificationService $notifications,
        protected PurchaseRequestService $purchases,
    ) {
    }

    // =====================================================================
    // CREATE
    // =====================================================================

    /**
     * Create a Material Request.
     *
     * @param  array  $data  {
     *     order_id: int,
     *     stage_id?: int,
     *     reason?: string,
     *     items: array<array{material_id:int, quantity_requested:numeric, notes?:string}>
     * }
     * @param  User|null  $actor  defaults to the authenticated user.
     * @return MaterialRequest
     * @throws ValidationException
     */
    public function create(array $data, ?User $actor = null): MaterialRequest
    {
        $actor = $actor ?? Auth::user();
        if (! $actor) {
            throw ValidationException::withMessages([
                'auth' => 'Authentication required to create a material request.',
            ]);
        }

        $order = Order::findOrFail($data['order_id']);

        // Resolve the target stage up front — honour an explicit stage_id
        // from the portal (validated to belong to the order); otherwise
        // fall back to the order's resolved current stage.
        $targetStage = $this->resolveStageForRequest($order, $data['stage_id'] ?? null);

        // Stage-restriction check, aligned with the target stage so a role
        // that owns the requested station (e.g. a screen maker on the
        // screen_making fork) is authorised even when the order's
        // current_stage_id points at the parallel fork.
        $this->assertRequesterCanRequestForOrder($actor, $order, $targetStage);

        $items = $data['items'] ?? [];
        if (! is_array($items) || empty($items)) {
            throw ValidationException::withMessages([
                'items' => 'At least one material item is required.',
            ]);
        }

        return DB::transaction(function () use ($data, $items, $order, $actor, $targetStage) {
            $mr = MaterialRequest::create([
                'mr_code'              => $this->generateCode('MR'),
                'order_id'             => $order->id,
                'stage_id'             => $targetStage?->id,
                'requested_by_user_id' => $actor->id,
                'status'               => MaterialRequest::STATUS_PENDING,
                'reason'               => $data['reason'] ?? null,
            ]);

            foreach ($items as $row) {
                $material = Materials::find($row['material_id'] ?? null);
                if (! $material) {
                    throw ValidationException::withMessages([
                        'items' => "Material id {$row['material_id']} does not exist.",
                    ]);
                }

                $requested = (float) ($row['quantity_requested'] ?? 0);
                if ($requested <= 0) {
                    throw ValidationException::withMessages([
                        'items' => "Quantity requested must be greater than 0 for {$material->name}.",
                    ]);
                }

                // Snapshot stock at request time for the manager's UI.
                $available = (float) ($material->stock_on_hand ?? 0);
                $short     = max(0.0, $requested - $available);

                MaterialRequestItem::create([
                    'material_request_id' => $mr->id,
                    'material_id'         => $material->id,
                    'quantity_requested'  => $requested,
                    'quantity_available'  => $available,
                    'quantity_short'      => $short,
                    'unit'                => $material->unit,
                    'notes'               => $row['notes'] ?? null,
                ]);
            }

            // Fire notifications post-commit (after the closure returns).
            return $mr->load(['items.material', 'order', 'stage', 'requestedBy']);
        }, 3);

        // NB: notifications fire from the controller / outside the
        // transaction so DB failures don't leave phantom alerts.
    }

    /**
     * Public helper that fires the "created" notification. Called by the
     * controller AFTER the transaction commits successfully.
     */
    public function announceCreated(MaterialRequest $mr): void
    {
        $this->notifications->materialRequestCreated($mr);
    }

    // =====================================================================
    // APPROVE
    // =====================================================================

    /**
     * Approve a pending MR. If any item is short on stock, auto-spawn a PR
     * and set status to `auto_pr`. Otherwise decrement stock and set
     * status to `approved`.
     */
    public function approve(MaterialRequest $mr, ?User $actor = null): MaterialRequest
    {
        $actor = $actor ?? Auth::user();
        if (! $actor) {
            throw ValidationException::withMessages([
                'auth' => 'Authentication required.',
            ]);
        }

        if (! $mr->isPending()) {
            throw ValidationException::withMessages([
                'status' => "Material request is already {$mr->status} and cannot be approved.",
            ]);
        }

        return DB::transaction(function () use ($mr, $actor) {
            $mr->load('items.material');

            // Recompute shortage at approval time using FRESH stock,
            // not the snapshot from request time. Stock may have moved.
            $shortItems = collect();
            foreach ($mr->items as $item) {
                $material = $item->material()->lockForUpdate()->first();
                if (! $material) continue;

                $available = (float) ($material->stock_on_hand ?? 0);
                $needed    = (float) $item->quantity_requested;

                if ($needed > $available) {
                    $shortItems->push([
                        'item'      => $item,
                        'material'  => $material,
                        'short_qty' => $needed - $available,
                    ]);
                }
            }

            if ($shortItems->isNotEmpty()) {
                // Hand off to the PR service, which will create the PR
                // and link it back. We don't decrement stock here — the
                // PR's "received" event will increment it later.
                $pr = $this->purchases->createFromMaterialRequest($mr, $shortItems, $actor);

                $mr->update([
                    'status'              => MaterialRequest::STATUS_AUTO_PR,
                    'approved_by_user_id' => $actor->id,
                    'approved_at'         => now(),
                    'purchase_request_id' => $pr->id,
                ]);
            } else {
                // Sufficient stock for every line item — decrement and approve.
                foreach ($mr->items as $item) {
                    $material = Materials::lockForUpdate()->find($item->material_id);
                    if (! $material) continue;

                    $material->decrement('stock_on_hand', (float) $item->quantity_requested);
                }

                $mr->update([
                    'status'              => MaterialRequest::STATUS_APPROVED,
                    'approved_by_user_id' => $actor->id,
                    'approved_at'         => now(),
                ]);
            }

            return $mr->fresh(['items.material', 'order', 'purchaseRequest']);
        }, 3);
    }

    /**
     * Fire the "decided" notification. Called by the controller after a
     * successful approve()/reject() returns.
     */
    public function announceDecided(MaterialRequest $mr): void
    {
        $this->notifications->materialRequestDecided($mr, $mr->status);
    }

    // =====================================================================
    // REJECT
    // =====================================================================

    public function reject(MaterialRequest $mr, string $reason, ?User $actor = null): MaterialRequest
    {
        $actor = $actor ?? Auth::user();
        if (! $actor) {
            throw ValidationException::withMessages([
                'auth' => 'Authentication required.',
            ]);
        }

        if (! $mr->isPending()) {
            throw ValidationException::withMessages([
                'status' => "Material request is already {$mr->status} and cannot be rejected.",
            ]);
        }

        $reason = trim($reason);
        if ($reason === '') {
            throw ValidationException::withMessages([
                'rejection_reason' => 'A rejection reason is required.',
            ]);
        }

        $mr->update([
            'status'              => MaterialRequest::STATUS_REJECTED,
            'rejection_reason'    => $reason,
            'approved_by_user_id' => $actor->id,
            'approved_at'         => now(),
        ]);

        return $mr->fresh(['items.material', 'order']);
    }

    // =====================================================================
    // Internals
    // =====================================================================

    /**
     * The stage-restriction policy:
     *   - SuperAdmin / Admin / GeneralManager can request for any order.
     *   - Other roles must (a) have at least one role assigned and
     *     (b) be the user assigned to the target stage,
     *     OR have a role that matches the target stage's `assigned_role`.
     *
     * $stage is the stage the request will attach to (explicit stage_id
     * when the portal passed one, otherwise the resolved current stage).
     * If null we fall back to resolving the current stage.
     *
     * If neither condition is met we throw a 422 with a field-level
     * message that the alert UI will display.
     */
    protected function assertRequesterCanRequestForOrder(User $actor, Order $order, ?OrderStage $stage = null): void
    {
        $managerRoles = ['superadmin', 'admin', 'general_manager'];
        if ($actor->hasAnyRole($managerRoles)) {
            return; // bypass
        }

        $current = $stage ?? $this->resolveCurrentStage($order);
        if (! $current) {
            throw ValidationException::withMessages([
                'order' => 'Order has no active stage; cannot request materials right now.',
            ]);
        }

        // (a) Direct assignment beats role-match.
        if ($current->assigned_to && (int) $current->assigned_to === $actor->id) {
            return;
        }

        // (b) Role-match: the actor has a role that owns this stage.
        $stageRole = $current->assigned_role ?? null;
        if ($stageRole && $actor->hasRole($stageRole)) {
            return;
        }

        throw ValidationException::withMessages([
            'stage' => "You can only request materials during the order's current stage. "
                . "Current stage: {$current->stage}.",
        ]);
    }

    /**
     * Resolve the stage a request should attach to.
     *
     * When an explicit stage_id is provided it MUST belong to the order —
     * otherwise a 422 is thrown (a portal should never hand us a foreign
     * stage). When omitted, we fall back to the order's current stage.
     */
    protected function resolveStageForRequest(Order $order, $explicitStageId = null): ?OrderStage
    {
        if ($explicitStageId !== null && $explicitStageId !== '') {
            $stage = OrderStage::find($explicitStageId);
            if (! $stage || (int) $stage->order_id !== (int) $order->id) {
                throw ValidationException::withMessages([
                    'stage_id' => 'The selected stage does not belong to this order.',
                ]);
            }
            return $stage;
        }

        return $this->resolveCurrentStage($order);
    }

    /**
     * Returns the order's current OrderStage (the one in_progress or
     * the next pending one). Falls back to whichever sequence the
     * Order points at via current_stage_id.
     */
    protected function resolveCurrentStage(Order $order): ?OrderStage
    {
        if ($order->current_stage_id) {
            $stage = OrderStage::find($order->current_stage_id);
            if ($stage) return $stage;
        }

        // Fall back to the lowest-sequence in_progress stage,
        // or the lowest-sequence pending stage.
        return OrderStage::where('order_id', $order->id)
            ->whereIn('status', ['in_progress', 'pending'])
            ->orderByRaw("CASE status WHEN 'in_progress' THEN 0 WHEN 'pending' THEN 1 ELSE 2 END")
            ->orderBy('sequence')
            ->first();
    }

    /**
     * Generate a unique code in the form PREFIX-YYYY-NNNNNN.
     * Year-scoped sequence so codes restart each calendar year.
     *
     * Uses count-then-retry-on-collision rather than SUBSTRING_INDEX
     * so the query is portable across MySQL / SQLite (tests run on
     * in-memory SQLite). On collision (race), we increment until a
     * free code is found — capped at a reasonable retry limit.
     */
    protected function generateCode(string $prefix): string
    {
        $year = now()->year;
        $count = MaterialRequest::whereYear('created_at', $year)->count();

        for ($i = 1; $i <= 1000; $i++) {
            $candidate = sprintf('%s-%d-%06d', $prefix, $year, $count + $i);
            if (! MaterialRequest::where('mr_code', $candidate)->exists()) {
                return $candidate;
            }
        }

        // Extremely unlikely fallback — append a random suffix to avoid blocking.
        return sprintf('%s-%d-%06d-%s', $prefix, $year, $count + 1, substr(md5(uniqid()), 0, 6));
    }
}
