<?php

namespace App\Services;

use App\Models\Materials;
use App\Models\PurchaseRequest;
use App\Models\PurchaseRequestItem;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * Phase 5-G — Material Preparation Portal context aggregator.
 *
 * Unlike Cutter/Sewer/etc which are bound to a specific OrderStage,
 * Material Prep is bound to a Purchase Request. The Purchaser handles
 * one PR at a time: see what to buy, pick a supplier, mark ordered,
 * mark received.
 *
 * Active PR statuses (what the Purchaser still has to action):
 *   - pending    — waiting for manager approval (visible read-only)
 *   - approved   — approved by manager, Purchaser needs to action
 *   - ordered    — Purchaser has placed the order, waiting for delivery
 *
 * Once 'received' or 'cancelled', the PR drops off the active list.
 *
 * Sections returned by buildContext (per Material_Preparation.png mockup):
 *   1. Purchase Request Summary
 *   2. Materials to Buy (line items)
 *   3. Supplier & Contact Details (current + alternatives)
 *   4. Payment Links (from supplier; PARTIAL — uses supplier fields)
 *   5. Total Amount (Estimated)
 */
class MaterialPrepPortalService
{
    /**
     * Statuses that should appear in the Purchaser's "active" list.
     */
    public const ACTIVE_STATUSES = ['pending', 'approved', 'ordered'];

    /**
     * Resolve which PRs this user should action.
     *
     * Returns the same shape as PortalAssignmentService::myActive():
     *   - {status: 'none'}
     *   - {status: 'single', assignment: {...}}
     *   - {status: 'multiple', assignments: [{...}]}
     *
     * Note: PRs aren't assigned to a specific user (unlike stages).
     * Anyone with portal.material-prep permission sees ALL active PRs.
     * This is intentional — small shops typically have 1 Purchaser
     * handling everything, and even with multiple, workload sharing
     * works better than rigid assignment.
     */
    public function myActiveRequests(User $user): array
    {
        $prs = PurchaseRequest::query()
            ->whereIn('status', self::ACTIVE_STATUSES)
            ->with(['order:id,po_code,client_brand,client_name', 'supplier:id,name'])
            // Custom sort order: approved first (most actionable), then pending,
            // then ordered. Uses CASE for portability — FIELD() is MySQL-only
            // and breaks under SQLite (used in Pest tests).
            ->orderByRaw("CASE status "
                . "WHEN 'approved' THEN 1 "
                . "WHEN 'pending'  THEN 2 "
                . "WHEN 'ordered'  THEN 3 "
                . "ELSE 4 END")
            ->orderBy('updated_at', 'desc')
            ->get();

        if ($prs->isEmpty()) {
            return ['status' => 'none'];
        }

        if ($prs->count() === 1) {
            return [
                'status'     => 'single',
                'assignment' => $this->summarizePR($prs->first()),
            ];
        }

        return [
            'status'      => 'multiple',
            'assignments' => $prs->map(fn ($pr) => $this->summarizePR($pr))->all(),
        ];
    }

    /**
     * Full context for a specific PR.
     */
    public function buildContext(int $purchaseRequestId): array
    {
        $pr = PurchaseRequest::with([
            'order:id,po_code,client_brand,client_name',
            'supplier',
            'items.material',
            'materialRequest:id,mr_code',
            'approvedBy:id,name',
        ])->find($purchaseRequestId);

        if (! $pr) {
            throw ValidationException::withMessages([
                'purchase_request_id' => 'Purchase Request not found.',
            ]);
        }

        return [
            'pr'                  => $this->prSummary($pr),
            'order'               => $this->orderSummary($pr),
            'items'               => $this->itemsBreakdown($pr),
            'supplier'            => $this->supplierDetails($pr->supplier),
            'alternative_suppliers' => $this->alternativeSuppliers($pr),
            'totals'              => $this->totals($pr),
            'permissions'         => $this->permissionFlags($pr),
        ];
    }

    // ── Section builders ────────────────────────────────────────

    protected function prSummary(PurchaseRequest $pr): array
    {
        return [
            'id'              => $pr->id,
            'pr_code'         => $pr->pr_code,
            'status'          => $pr->status,
            'total_amount'    => (float) $pr->total_amount,
            'reason'          => $pr->reason,
            'approved_by'     => $pr->approvedBy ? [
                'id'   => $pr->approvedBy->id,
                'name' => $pr->approvedBy->name,
            ] : null,
            'approved_at'     => $pr->approved_at?->toDateTimeString(),
            'ordered_at'      => $pr->ordered_at?->toDateTimeString(),
            'received_at'     => $pr->received_at?->toDateTimeString(),
            'created_at'      => $pr->created_at?->toDateTimeString(),
            'material_request_code' => $pr->materialRequest?->mr_code,
        ];
    }

    protected function orderSummary(PurchaseRequest $pr): ?array
    {
        if (! $pr->order) {
            return null;
        }
        return [
            'id'           => $pr->order->id,
            'po_code'      => $pr->order->po_code,
            'client_name'  => $pr->order->client_name,
            'client_brand' => $pr->order->client_brand,
        ];
    }

    protected function itemsBreakdown(PurchaseRequest $pr): array
    {
        return $pr->items->map(function ($item) {
            $material = $item->material;
            return [
                'id'             => $item->id,
                'material_id'    => $item->material_id,
                'material_name'  => $material?->name,
                'material_type'  => $material?->material_type,
                'quantity'       => (float) $item->quantity,
                'unit'           => $item->unit,
                'unit_price'     => (float) $item->unit_price,
                'line_total'     => (float) $item->line_total,
                'current_stock'  => $material ? (float) $material->stock_on_hand : null,
                'notes'          => $item->notes,
            ];
        })->all();
    }

    protected function supplierDetails(?Supplier $supplier): ?array
    {
        if (! $supplier) {
            return null;
        }
        return [
            'id'             => $supplier->id,
            'name'           => $supplier->name,
            'contact_person' => $supplier->contact_person,
            'contact_number' => $supplier->contact_number,
            'email'          => $supplier->email,
            'address'        => $supplier->address,
            'notes'          => $supplier->notes,
        ];
    }

    /**
     * Other suppliers that carry the materials in this PR.
     * Used to populate the "alternative suppliers" section
     * so the Purchaser can switch while the PR is still pending.
     *
     * Matching logic: each material has a unique row per supplier
     * (e.g., "Cotton Fabric 20s" exists as material #1 under Supplier A
     * AND as material #2 under Supplier B). We match by NAME (+ material_type
     * for safety) rather than ID so we find suppliers carrying the same
     * item across different material rows.
     *
     * Excludes:
     *   - the PR's current supplier
     *   - suppliers that don't carry ALL the items in the PR (so the
     *     Purchaser doesn't switch to a supplier missing half the order)
     */
    protected function alternativeSuppliers(PurchaseRequest $pr): array
    {
        // Build the set of (name, material_type) tuples this PR needs.
        $neededMaterials = $pr->items
            ->map(fn ($item) => $item->material)
            ->filter()
            ->map(fn ($m) => [
                'name'          => $m->name,
                'material_type' => $m->material_type,
            ])
            ->unique(fn ($row) => $row['name'] . '|' . $row['material_type'])
            ->values();

        if ($neededMaterials->isEmpty()) {
            return [];
        }

        // Find all materials matching any of these (name, type) tuples,
        // grouped by supplier. Each match contributes one of the needed
        // items to that supplier's coverage set.
        $matchingMaterials = Materials::query()
            ->where(function ($q) use ($neededMaterials) {
                foreach ($neededMaterials as $needed) {
                    $q->orWhere(function ($qq) use ($needed) {
                        $qq->where('name', $needed['name'])
                           ->where('material_type', $needed['material_type']);
                    });
                }
            })
            ->where('supplier_id', '!=', $pr->supplier_id)
            ->get(['id', 'supplier_id', 'name', 'material_type']);

        // Group by supplier and count distinct (name, type) coverage.
        // Suppliers that cover every needed material are kept; others dropped.
        $coverageBySupplier = $matchingMaterials
            ->groupBy('supplier_id')
            ->map(function ($materials) {
                return $materials
                    ->map(fn ($m) => $m->name . '|' . $m->material_type)
                    ->unique()
                    ->count();
            });

        $neededCount = $neededMaterials->count();
        $qualifyingSupplierIds = $coverageBySupplier
            ->filter(fn ($coverage) => $coverage >= $neededCount)
            ->keys();

        if ($qualifyingSupplierIds->isEmpty()) {
            return [];
        }

        return Supplier::whereIn('id', $qualifyingSupplierIds)
            ->orderBy('name')
            ->get(['id', 'name', 'contact_person', 'contact_number', 'email', 'address', 'notes'])
            ->map(fn ($s) => [
                'id'             => $s->id,
                'name'           => $s->name,
                'contact_person' => $s->contact_person,
                'contact_number' => $s->contact_number,
                'email'          => $s->email,
                'address'        => $s->address,
                'notes'          => $s->notes,
            ])
            ->all();
    }

    protected function totals(PurchaseRequest $pr): array
    {
        $totalItems    = $pr->items->count();
        $totalQuantity = (float) $pr->items->sum('quantity');
        $totalAmount   = (float) $pr->items->sum('line_total');

        return [
            'total_items'    => $totalItems,
            'total_quantity' => $totalQuantity,
            'total_amount'   => $totalAmount,
        ];
    }

    /**
     * Permission flags the frontend uses to enable/disable action buttons.
     *
     * Supplier change is allowed while status='pending' only (per the
     * Phase 5-G architectural decision: change only while pending, lock
     * once approved or beyond).
     */
    protected function permissionFlags(PurchaseRequest $pr): array
    {
        $actor = Auth::user();
        $canAct = $actor && $actor->can('action.process-purchase');

        return [
            'can_change_supplier' => $canAct && $pr->status === 'pending',
            'can_mark_ordered'    => $canAct && $pr->status === 'approved',
            'can_mark_received'   => $canAct && $pr->status === 'ordered',
            'can_cancel'          => $canAct && in_array($pr->status, ['pending', 'approved', 'ordered'], true),
        ];
    }

    /**
     * Compact PR representation for the picker (multiple-assignment case).
     */
    protected function summarizePR(PurchaseRequest $pr): array
    {
        return [
            'id'           => $pr->id,
            'pr_code'      => $pr->pr_code,
            'status'       => $pr->status,
            'total_amount' => (float) $pr->total_amount,
            'created_at'   => $pr->created_at?->toDateTimeString(),
            'order' => $pr->order ? [
                'id'           => $pr->order->id,
                'po_code'      => $pr->order->po_code,
                'client_brand' => $pr->order->client_brand,
                'client_name'  => $pr->order->client_name,
            ] : null,
            'supplier' => $pr->supplier ? [
                'id'   => $pr->supplier->id,
                'name' => $pr->supplier->name,
            ] : null,
        ];
    }

    /**
     * Assign / change the supplier on a PR.
     *
     * Per Phase 5-G architectural decision:
     *   - Allowed ONLY while status === 'pending'.
     *   - Once approved/ordered/received, supplier is locked.
     *
     * Validation:
     *   - Actor must have action.process-purchase permission.
     *   - Target supplier must exist.
     *   - PR must be in 'pending' status.
     */
    public function assignSupplier(int $prId, int $supplierId, ?User $actor = null): PurchaseRequest
    {
        $actor = $actor ?? Auth::user();
        $this->ensureCan($actor);

        $pr = PurchaseRequest::find($prId);
        if (! $pr) {
            throw ValidationException::withMessages([
                'purchase_request_id' => 'Purchase Request not found.',
            ]);
        }

        if ($pr->status !== 'pending') {
            throw ValidationException::withMessages([
                'status' => "Cannot change supplier — PR is already '{$pr->status}'.",
            ]);
        }

        $supplier = Supplier::find($supplierId);
        if (! $supplier) {
            throw ValidationException::withMessages([
                'supplier_id' => 'Supplier not found.',
            ]);
        }

        $pr->update(['supplier_id' => $supplier->id]);
        return $pr->fresh(['supplier', 'items.material', 'order']);
    }

    protected function ensureCan(?User $actor): void
    {
        if (! $actor) {
            throw ValidationException::withMessages([
                'actor' => 'No authenticated user.',
            ]);
        }
        if (! $actor->can('action.process-purchase')) {
            throw ValidationException::withMessages([
                'permission' => 'You do not have permission to process purchases.',
            ]);
        }
    }
}
