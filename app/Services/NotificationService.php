<?php

namespace App\Services;

use App\Models\Notification;
use App\Models\Order;
use App\Models\OrderStage;
use App\Models\User;
use Illuminate\Support\Collection;
use Spatie\Permission\Models\Role;

/**
 * NotificationService – the single entry point for emitting in-app
 * notifications. All Phase 1 trigger points (stage transitions, order
 * lifecycle) call into this class.
 *
 * Recipient resolution is intentional: each event type knows which
 * roles should receive it. We push notifications to ALL users with
 * those roles + the explicitly-assigned user (if any).
 *
 * Channels:  in-app only (this is Phase 2 v1).
 * Future:    email + WebSocket push will be added without changing
 *            the call sites — extend `dispatch()` here.
 */
class NotificationService
{
    /**
     * Roles that always receive operational notifications about an order
     * (delays, holds, completions). Mirrors the "managers" group.
     */
    protected array $managerRoles = ['superadmin', 'admin', 'general_manager'];

    // =====================================================================
    // Public event API
    // =====================================================================

    /**
     * A stage was flagged as delayed.
     * → Notify managers + CSR + assignee.
     */
    public function stageDelayed(OrderStage $stage, ?string $reason = null): void
    {
        $recipients = $this->collectRecipients(
            roles: array_merge($this->managerRoles, ['csr']),
            assignedUserId: $stage->assigned_to,
        );

        $this->dispatch($recipients, [
            'type'  => 'stage.delayed',
            'title' => "Stage delayed: {$this->stageLabel($stage)}",
            'body'  => $reason
                ? "Reason: {$reason}"
                : 'No reason provided.',
            'data'  => $this->stageContext($stage),
        ]);
    }

    /**
     * A stage was put on hold.
     * → Notify managers + CSR + assignee.
     */
    public function stageOnHold(OrderStage $stage, ?string $reason = null): void
    {
        $recipients = $this->collectRecipients(
            roles: array_merge($this->managerRoles, ['csr']),
            assignedUserId: $stage->assigned_to,
        );

        $this->dispatch($recipients, [
            'type'  => 'stage.on_hold',
            'title' => "Stage on hold: {$this->stageLabel($stage)}",
            'body'  => $reason ? "Reason: {$reason}" : null,
            'data'  => $this->stageContext($stage),
        ]);
    }

    /**
     * A stage moved into `for_approval` and is waiting on an approver.
     * → Notify CSR + managers (they're the approvers in our model).
     */
    public function stageForApproval(OrderStage $stage, ?string $notes = null): void
    {
        $recipients = $this->collectRecipients(
            roles: array_merge($this->managerRoles, ['csr']),
        );

        $this->dispatch($recipients, [
            'type'  => 'stage.for_approval',
            'title' => "Awaiting approval: {$this->stageLabel($stage)}",
            'body'  => $notes ?: 'A stage is waiting for your review.',
            'data'  => $this->stageContext($stage),
        ]);
    }

    /**
     * CSR Review Hub — a reviewer REJECTED a stage's output.
     * → Notify the role that OWNS this stage (the people who must fix it),
     *   plus its explicitly-assigned user if any. Deliberately NOT the
     *   managers/CSR: the rejection is a "your work needs rework" alert
     *   aimed at the producing role, not an approval queue item.
     *
     * The owning role is taken from the stage's assigned_role (set at
     * initialization from the canonical WorkflowStages role), falling back
     * to the stage definition's role so the notification still routes even
     * if assigned_role was never populated.
     *
     * @param string|null $comment the reviewer's required reject comment
     */
    public function stageRejected(OrderStage $stage, ?string $comment = null): void
    {
        $roleSlug = $stage->assigned_role
            ?: (\App\Support\WorkflowStages::find($stage->stage)['role'] ?? null);

        $recipients = $this->collectRecipients(
            roles: $roleSlug ? [$roleSlug] : [],
            assignedUserId: $stage->assigned_to,
        );

        $this->dispatch($recipients, [
            'type'  => 'stage.rejected',
            'title' => "Rework needed: {$this->stageLabel($stage)}",
            'body'  => $comment
                ? "Your {$this->stageLabel($stage)} output was rejected: {$comment}"
                : "Your {$this->stageLabel($stage)} output was rejected and needs rework.",
            'data'  => $this->stageContext($stage),
        ]);
    }

    /**
     * CSR Review Hub — the owning role RESUBMITTED a previously-rejected
     * stage's output.
     * → Notify the reviewers (CSR + managers) that there's work to re-review,
     *   mirroring stageForApproval's audience.
     */
    public function stageResubmitted(OrderStage $stage, ?string $comment = null): void
    {
        $recipients = $this->collectRecipients(
            roles: array_merge($this->managerRoles, ['csr']),
        );

        $this->dispatch($recipients, [
            'type'  => 'stage.resubmitted',
            'title' => "Resubmitted for review: {$this->stageLabel($stage)}",
            'body'  => $comment
                ? "Corrected and resubmitted: {$comment}"
                : 'A previously-rejected stage was corrected and resubmitted for review.',
            'data'  => $this->stageContext($stage),
        ]);
    }

    /**
     * A stage was assigned to a specific user.
     * → Notify ONLY that user (no spamming managers).
     */
    public function stageAssigned(OrderStage $stage, ?int $assignedUserId): void
    {
        if (! $assignedUserId) {
            return; // role-only assignments don't trigger this
        }

        $recipient = User::find($assignedUserId);
        if (! $recipient) {
            return;
        }

        $this->dispatch(collect([$recipient]), [
            'type'  => 'stage.assigned',
            'title' => "You've been assigned: {$this->stageLabel($stage)}",
            'body'  => "You are now responsible for the {$this->stageLabel($stage)} stage.",
            'data'  => $this->stageContext($stage),
        ]);
    }

    /**
     * A stage transitioned to in_progress and is now the active step.
     * → Notify users with the role that owns this stage + the assigned user.
     *
     * Use this to give people a "your turn" alert when their stage starts.
     */
    public function stageInProgress(OrderStage $stage): void
    {
        $roleSlug = $stage->assigned_role;

        $recipients = $this->collectRecipients(
            roles: $roleSlug ? [$roleSlug] : [],
            assignedUserId: $stage->assigned_to,
        );

        if ($recipients->isEmpty()) {
            return;
        }

        $this->dispatch($recipients, [
            'type'  => 'stage.in_progress',
            'title' => "Your turn: {$this->stageLabel($stage)}",
            'body'  => 'This stage is now ready to work on.',
            'data'  => $this->stageContext($stage),
        ]);
    }

    /**
     * The order finished its full 14-stage workflow.
     * → Notify CSR + managers.
     */
    public function orderCompleted(Order $order): void
    {
        $recipients = $this->collectRecipients(
            roles: array_merge($this->managerRoles, ['csr']),
        );

        $this->dispatch($recipients, [
            'type'  => 'order.completed',
            'title' => "Order completed: {$order->po_code}",
            'body'  => 'All workflow stages are done.',
            'data'  => $this->orderContext($order),
        ]);
    }

    /**
     * A quotation was approved or rejected.
     * → Notify CSR + managers + finance.
     */
    public function quotationDecided(Order $order, string $decision): void
    {
        $recipients = $this->collectRecipients(
            roles: array_merge($this->managerRoles, ['csr', 'finance']),
        );

        $isApproved = $decision === 'approved';

        $this->dispatch($recipients, [
            'type'  => $isApproved ? 'quotation.approved' : 'quotation.rejected',
            'title' => $isApproved
                ? "Quotation approved: {$order->po_code}"
                : "Quotation rejected: {$order->po_code}",
            'body'  => $isApproved
                ? 'The client has approved the quotation.'
                : 'The client rejected the quotation.',
            'data'  => $this->orderContext($order),
        ]);
    }

    /**
     * A material request was created.
     * → Notify managers + purchasing + warehouse_manager.
     *
     * Accepts either an Order (legacy v1 signature) or a MaterialRequest
     * so callers in Phase 3+ can pass the full MR for richer context.
     * The two shapes converge on the same payload.
     */
    public function materialRequestCreated($subject, array $extra = []): void
    {
        // Resolve the underlying Order regardless of input type.
        $order = $subject instanceof Order
            ? $subject
            : ($subject->order ?? null);

        if (! $order) {
            return; // nothing to dispatch about
        }

        $recipients = $this->collectRecipients(
            roles: array_merge(
                $this->managerRoles,
                ['purchasing', 'warehouse_manager'],
            ),
        );

        // If the subject was an MR, surface its code in the body.
        $title = $subject instanceof \App\Models\MaterialRequest && $subject->mr_code
            ? "New material request: {$subject->mr_code} (Order {$order->po_code})"
            : "New material request: {$order->po_code}";

        $body = $extra['summary']
            ?? ($subject instanceof \App\Models\MaterialRequest && $subject->reason
                ? $subject->reason
                : 'A new material request was created.');

        $data = array_merge($this->orderContext($order), $extra);
        if ($subject instanceof \App\Models\MaterialRequest) {
            $data['material_request_id'] = $subject->id;
            $data['mr_code'] = $subject->mr_code;
            $data['link'] = "/material-requests/{$subject->id}";
        }

        $this->dispatch($recipients, [
            'type'  => 'material_request.created',
            'title' => $title,
            'body'  => $body,
            'data'  => $data,
        ]);
    }

    /**
     * A material request was approved or rejected.
     * → Notify the requester + managers (so they have visibility).
     *
     * `$decision` is one of: approved | rejected | auto_pr.
     */
    public function materialRequestDecided(\App\Models\MaterialRequest $mr, string $decision): void
    {
        $order = $mr->order;
        if (! $order) {
            return;
        }

        // Requester always gets pinged so they know.
        $recipients = $this->collectRecipients(
            roles: $this->managerRoles,
            assignedUserId: $mr->requested_by_user_id,
        );

        $verb = match ($decision) {
            'approved' => 'approved',
            'rejected' => 'rejected',
            'auto_pr'  => 'approved (auto-PR triggered)',
            default    => $decision,
        };

        $body = $decision === 'rejected' && $mr->rejection_reason
            ? "Reason: {$mr->rejection_reason}"
            : "Material request {$mr->mr_code} for order {$order->po_code} was {$verb}.";

        $this->dispatch($recipients, [
            'type'  => "material_request.{$decision}",
            'title' => "Material request {$verb}: {$mr->mr_code}",
            'body'  => $body,
            'data'  => array_merge($this->orderContext($order), [
                'material_request_id' => $mr->id,
                'mr_code'             => $mr->mr_code,
                'decision'            => $decision,
                'link'                => "/material-requests/{$mr->id}",
            ]),
        ]);
    }

    /**
     * A purchase request was created (auto-spawned from MR or manual).
     * → Notify managers + purchasing.
     */
    public function purchaseRequestCreated(\App\Models\PurchaseRequest $pr): void
    {
        $order = $pr->order;
        if (! $order) {
            return;
        }

        $recipients = $this->collectRecipients(
            roles: array_merge($this->managerRoles, ['purchasing', 'warehouse_manager']),
        );

        $this->dispatch($recipients, [
            'type'  => 'purchase_request.created',
            'title' => "New purchase request: {$pr->pr_code}",
            'body'  => "Order {$order->po_code} – pending approval.",
            'data'  => array_merge($this->orderContext($order), [
                'purchase_request_id' => $pr->id,
                'pr_code'             => $pr->pr_code,
                'total_amount'        => $pr->total_amount,
                'link'                => "/purchase-requests/{$pr->id}",
            ]),
        ]);
    }

    /**
     * A purchase request was approved, ordered, or cancelled.
     * → Notify purchasing + originating MR's requester.
     *
     * `$decision` is one of: approved | ordered | cancelled.
     */
    public function purchaseRequestDecided(\App\Models\PurchaseRequest $pr, string $decision): void
    {
        $order = $pr->order;
        if (! $order) {
            return;
        }

        // Notify purchasing + original MR requester (if any).
        $assignedUserId = $pr->materialRequest?->requested_by_user_id;

        $recipients = $this->collectRecipients(
            roles: array_merge($this->managerRoles, ['purchasing', 'warehouse_manager']),
            assignedUserId: $assignedUserId,
        );

        $title = match ($decision) {
            'approved'  => "Purchase request approved: {$pr->pr_code}",
            'ordered'   => "Purchase request ordered: {$pr->pr_code}",
            'cancelled' => "Purchase request cancelled: {$pr->pr_code}",
            default     => "Purchase request {$decision}: {$pr->pr_code}",
        };

        $this->dispatch($recipients, [
            'type'  => "purchase_request.{$decision}",
            'title' => $title,
            'body'  => "Order {$order->po_code}",
            'data'  => array_merge($this->orderContext($order), [
                'purchase_request_id' => $pr->id,
                'pr_code'             => $pr->pr_code,
                'decision'            => $decision,
                'link'                => "/purchase-requests/{$pr->id}",
            ]),
        ]);
    }

    /**
     * A purchase request's goods were marked as received.
     * → Notify managers + purchasing + warehouse + the original requester.
     */
    public function purchaseRequestReceived(\App\Models\PurchaseRequest $pr): void
    {
        $order = $pr->order;
        if (! $order) {
            return;
        }

        $assignedUserId = $pr->materialRequest?->requested_by_user_id;

        $recipients = $this->collectRecipients(
            roles: array_merge($this->managerRoles, ['purchasing', 'warehouse_manager']),
            assignedUserId: $assignedUserId,
        );

        $this->dispatch($recipients, [
            'type'  => 'purchase_request.received',
            'title' => "Goods received: {$pr->pr_code}",
            'body'  => "Stock has been updated for order {$order->po_code}.",
            'data'  => array_merge($this->orderContext($order), [
                'purchase_request_id' => $pr->id,
                'pr_code'             => $pr->pr_code,
                'link'                => "/purchase-requests/{$pr->id}",
            ]),
        ]);
    }


    /**
     * A new order was created.
     * → Notify CSR + managers (so they can pick it up).
     */
    public function orderCreated(Order $order): void
    {
        $recipients = $this->collectRecipients(
            roles: array_merge($this->managerRoles, ['csr']),
        );

        $this->dispatch($recipients, [
            'type'  => 'order.created',
            'title' => "New order: {$order->po_code}",
            'body'  => "Client: {$order->client_brand}",
            'data'  => $this->orderContext($order),
        ]);
    }

    /**
     * Role-directed order note — a Hub reviewer posted an instruction
     * aimed at a production role (Hub → portal channel).
     * → Notify every user holding the audience role. Deliberately NOT
     *   the managers/CSR: like stageRejected, this is a "for you" alert
     *   aimed at the producing role, and the author already knows they
     *   wrote it.
     */
    public function roleNoteCreated(Order $order, \App\Models\OrderRoleNote $note): void
    {
        $recipients = $this->collectRecipients(
            roles: [$note->audience_role],
        );

        $this->dispatch($recipients, [
            'type'  => 'role_note.created',
            'title' => "New instructions: {$order->po_code}",
            'body'  => \Illuminate\Support\Str::limit($note->body, 140),
            'data'  => array_merge($this->orderContext($order), [
                'role_note_id'  => $note->id,
                'audience_role' => $note->audience_role,
            ]),
        ]);
    }

    // =====================================================================
    // Inbox API used by NotificationController
    // =====================================================================

    /**
     * Returns the user's notifications, newest first, paginated.
     */
    /**
     * Issue 8 — a CSR sent a quotation to the Graphic Artist for review.
     * → Notify the graphic_artist role (their entry point; there is no queue).
     */
    public function designReviewRequested(\App\Models\Quotation $quotation): void
    {
        $recipients = $this->collectRecipients(roles: ['graphic_artist']);

        $this->dispatch($recipients, [
            'type'  => 'quotation.design_review_requested',
            'title' => "Design review requested: {$quotation->quotation_id}",
            'body'  => 'A CSR sent a quotation design for colours/clarity review.',
            'data'  => [
                'quotation_id' => $quotation->id,
                'quotation_code' => $quotation->quotation_id,
                'link' => "/quotation-reviews/{$quotation->id}",
            ],
        ]);
    }

    /**
     * Issue 8 — the GA set a verdict on a quotation's design.
     * → Notify the CSR who created it (+ the csr role for visibility).
     */
    public function designReviewDecided(\App\Models\Quotation $quotation, string $status): void
    {
        $recipients = $this->collectRecipients(
            roles: ['csr'],
            assignedUserId: $quotation->user_id,
        );

        $this->dispatch($recipients, [
            'type'  => 'quotation.design_review_decided',
            'title' => "Design review: {$status} ({$quotation->quotation_id})",
            'body'  => $quotation->design_review_note
                ? "GA note: {$quotation->design_review_note}"
                : "The Graphic Artist marked this design \"{$status}\".",
            'data'  => [
                'quotation_id' => $quotation->id,
                'quotation_code' => $quotation->quotation_id,
                'status' => $status,
                'color_count' => $quotation->design_color_count,
                'link' => "/quotations/view/{$quotation->id}",
            ],
        ]);
    }

    public function listForUser(int $userId, int $perPage = 20)
    {
        return Notification::forUser($userId)
            ->orderByDesc('created_at')
            ->paginate($perPage);
    }

    /**
     * Returns the most recent N notifications for the bell dropdown.
     */
    public function recentForUser(int $userId, int $limit = 10): Collection
    {
        return Notification::forUser($userId)
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();
    }

    public function unreadCount(int $userId): int
    {
        return Notification::forUser($userId)->unread()->count();
    }

    public function markRead(int $notificationId, int $userId): ?Notification
    {
        $n = Notification::forUser($userId)->find($notificationId);
        if ($n) {
            $n->markRead();
        }
        return $n;
    }

    public function markAllRead(int $userId): int
    {
        return Notification::forUser($userId)
            ->unread()
            ->update(['read_at' => now()]);
    }

    public function delete(int $notificationId, int $userId): bool
    {
        $n = Notification::forUser($userId)->find($notificationId);
        if (! $n) {
            return false;
        }
        return (bool) $n->delete();
    }

    // =====================================================================
    // Internals
    // =====================================================================

    /**
     * Build a deduplicated User collection from a list of role slugs and
     * an optional explicitly-assigned user id.
     *
     * Resilient to missing roles: if a role slug doesn't exist in the
     * database (e.g. during early seeding, or in tests that haven't
     * created every role), it is silently skipped rather than throwing.
     * Spatie's User::role() is strict by default and would otherwise
     * raise RoleDoesNotExist.
     */
    protected function collectRecipients(
        array $roles = [],
        ?int $assignedUserId = null,
    ): Collection {
        $users = collect();

        if (! empty($roles)) {
            // Filter the requested role list down to the ones that
            // actually exist, so Spatie doesn't throw on the others.
            $existingRoles = \Spatie\Permission\Models\Role::whereIn('name', $roles)
                ->pluck('name')
                ->all();

            if (! empty($existingRoles)) {
                $users = User::role($existingRoles)->get();
            }
        }

        if ($assignedUserId) {
            $assigned = User::find($assignedUserId);
            if ($assigned) {
                $users->push($assigned);
            }
        }

        // Deduplicate by id.
        return $users->unique('id')->values();
    }

    /**
     * Persist one Notification row per recipient. Each recipient gets
     * their own row so read state is tracked individually.
     */
    protected function dispatch(Collection $recipients, array $payload): void
    {
        if ($recipients->isEmpty()) {
            return;
        }

        $now = now();

        $rows = $recipients->map(function (User $user) use ($payload, $now) {
            return [
                'user_id'    => $user->id,
                'type'       => $payload['type'],
                'title'      => $payload['title'],
                'body'       => $payload['body'] ?? null,
                'data'       => isset($payload['data']) ? json_encode($payload['data']) : null,
                'read_at'    => null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        })->all();

        // Bulk insert. Skips Eloquent events but is much faster for
        // events that fan out to many users.
        Notification::insert($rows);
    }

    protected function stageLabel(OrderStage $stage): string
    {
        // Use a human-friendly label if available, fall back to the slug.
        $slug = $stage->stage;
        return ucwords(str_replace('_', ' ', (string) $slug));
    }

    protected function stageContext(OrderStage $stage): array
    {
        $order = Order::find($stage->order_id);
        return [
            'order_id'   => $stage->order_id,
            'po_code'    => $order?->po_code,
            'stage_id'   => $stage->id,
            'stage_slug' => $stage->stage,
            'sequence'   => $stage->sequence,
            'link'       => $order
                ? "/order/{$order->po_code}"
                : null,
        ];
    }

    protected function orderContext(Order $order): array
    {
        return [
            'order_id' => $order->id,
            'po_code'  => $order->po_code,
            'link'     => "/order/{$order->po_code}",
        ];
    }

    // ──────────────────────────────────────────────────────────────────
    // Phase 4 — Stage Inputs / Subcontract notifications
    // ──────────────────────────────────────────────────────────────────

    /**
     * Production user logged waste against a stage.
     * → Notify managers + CSR (so customer-affecting losses are visible).
     */
    public function stageWasteLogged(\App\Models\StageWasteLog $log): void
    {
        $stage = OrderStage::find($log->order_stage_id);
        if (! $stage) {
            return;
        }

        $recipients = $this->collectRecipients(
            roles: array_merge($this->managerRoles, ['csr']),
        );

        $this->dispatch($recipients, [
            'type'  => 'stage.waste_logged',
            'title' => "Waste logged: {$this->stageLabel($stage)}",
            'body'  => "{$log->quantity_pcs} pieces logged as waste"
                . ($log->notes ? " — {$log->notes}" : ''),
            'data'  => array_merge($this->stageContext($stage), [
                'waste_log_id' => $log->id,
                'quantity_pcs' => $log->quantity_pcs,
            ]),
        ]);
    }

    /**
     * QA logged a reject against a stage.
     * → Notify managers + CSR + the user assigned to that stage
     *   (so they know their work was flagged).
     */
    public function stageRejectLogged(\App\Models\StageRejectLog $log): void
    {
        $stage = OrderStage::find($log->order_stage_id);
        if (! $stage) {
            return;
        }

        // Phase 7-B Bundle 4a — disposition-aware fan-out.
        //
        // Per PDF §6 notification rules:
        //   - Reject due to fabric: notify Cutter, CSR, Super Admin
        //   - Other reject:         notify CSR, Super Admin
        //   - Repair only:          notify CSR only (no manager spam)
        $isRepair = $log->disposition === \App\Models\StageRejectLog::DISPOSITION_REPAIR;
        $isFabricReject = ! $isRepair
            && $log->reason
            && (bool) $log->reason->is_fabric;

        $roles = ['csr'];
        if (! $isRepair) {
            // Reject-level events also page managers.
            $roles = array_merge($this->managerRoles, $roles);
        }
        if ($isFabricReject) {
            // Fabric-rooted rejects also page the Cutter so they know
            // upstream fabric quality is suspect.
            $roles[] = 'cutter';
        }

        $recipients = $this->collectRecipients(
            roles: array_unique($roles),
            assignedUserId: $stage->assigned_to,
        );

        $titleKind = $isRepair ? 'Repair' : 'Reject';
        $reasonLabel = $log->reason?->label;

        $this->dispatch($recipients, [
            'type'  => $isRepair ? 'stage.repair_logged' : 'stage.reject_logged',
            'title' => "{$titleKind} logged: {$this->stageLabel($stage)}",
            'body'  => "{$log->quantity_pcs} pcs"
                . ($reasonLabel ? " — {$reasonLabel}" : '')
                . ($log->notes ? " ({$log->notes})" : ''),
            'data'  => array_merge($this->stageContext($stage), [
                'reject_log_id'     => $log->id,
                'disposition'       => $log->disposition,
                'reject_reason_id'  => $log->reject_reason_id,
                'reject_reason'     => $log->reason?->slug,
                'quantity_pcs'      => $log->quantity_pcs,
            ]),
        ]);
    }

    /**
     * Phase 7-B Bundle 4a — A QA or Packing task was submitted.
     *
     * Fan-out (per spec doc §6 + §7-B.8 Q4):
     *   - CSR:         always (order moved forward)
     *   - Logistics:   always (order ready for next handoff)
     *   - Super Admin: only when reject thresholds exceeded
     *
     * Recipient resolution is via collectRecipients(), so it picks up
     * every user with the named role.
     *
     * Returns the fan-out decision so QaPackerSubmitService can include
     * it in the controller response (the frontend uses this to show
     * "Super Admin alerted" badges on the success card).
     *
     * @return array{csr:bool, logistics:bool, super_admin:bool}
     */
    public function qaPackerTaskCompleted(
        OrderStage $stage,
        \App\Models\Order $order,
        array $rejectSummary,
    ): array {
        $alertSuperAdmin = (bool) ($rejectSummary['exceeds_threshold'] ?? false);

        $roles = ['csr', 'logistics'];
        if ($alertSuperAdmin) {
            // Super Admin alerted = managers fan-out gets added.
            $roles = array_merge($roles, $this->managerRoles);
        }

        $recipients = $this->collectRecipients(
            roles: array_unique($roles),
            assignedUserId: $stage->assigned_to,
        );

        $stageLabel = $this->stageLabel($stage);
        $rejectPcs  = (int) ($rejectSummary['total_pcs'] ?? 0);
        $rejectPct  = (float) ($rejectSummary['pct'] ?? 0.0);

        $bodyParts = ["{$stageLabel} completed for {$order->po_code}"];
        if ($rejectPcs > 0) {
            $bodyParts[] = sprintf(
                '%d pcs rejected (%.1f%% of order)',
                $rejectPcs,
                $rejectPct * 100,
            );
        }
        if ($alertSuperAdmin) {
            $bodyParts[] = 'Reject threshold exceeded — please review.';
        }

        $this->dispatch($recipients, [
            'type'  => 'qa_packer.task_completed',
            'title' => "Submitted: {$stageLabel}",
            'body'  => implode(' · ', $bodyParts),
            'data'  => array_merge($this->stageContext($stage), [
                'order_id'          => $order->id,
                'po_code'           => $order->po_code,
                'reject_pcs'        => $rejectPcs,
                'reject_pct'        => $rejectPct,
                'exceeds_threshold' => $alertSuperAdmin,
            ]),
        ]);

        return [
            'csr'         => true,
            'logistics'   => true,
            'super_admin' => $alertSuperAdmin,
        ];
    }

    /**
     * A stage was sent to a subcontractor.
     * → Notify managers + the user assigned to that stage.
     */
    public function subcontractAssigned(\App\Models\StageSubcontractAssignment $assignment): void
    {
        $stage = OrderStage::find($assignment->order_stage_id);
        if (! $stage) {
            return;
        }

        $vendorName = $assignment->subcontractor?->name ?? 'Unspecified vendor';

        $recipients = $this->collectRecipients(
            roles: $this->managerRoles,
            assignedUserId: $stage->assigned_to,
        );

        $this->dispatch($recipients, [
            'type'  => 'subcontract.assigned',
            'title' => "Subcontracted: {$this->stageLabel($stage)}",
            'body'  => "{$assignment->quantity_pcs} pcs sent to {$vendorName}",
            'data'  => array_merge($this->stageContext($stage), [
                'subcontract_assignment_id' => $assignment->id,
                'subcontractor_id'          => $assignment->subcontractor_id,
                'quantity_pcs'              => $assignment->quantity_pcs,
                'status'                    => $assignment->status,
            ]),
        ]);
    }

    /**
     * Subcontractor returned the work; QA can now inspect.
     * → Notify managers + QA + the user assigned to that stage.
     */
    public function subcontractReturned(\App\Models\StageSubcontractAssignment $assignment): void
    {
        $stage = OrderStage::find($assignment->order_stage_id);
        if (! $stage) {
            return;
        }

        $vendorName = $assignment->subcontractor?->name ?? 'Unspecified vendor';

        $recipients = $this->collectRecipients(
            roles: array_merge($this->managerRoles, ['quality_assurance']),
            assignedUserId: $stage->assigned_to,
        );

        $this->dispatch($recipients, [
            'type'  => 'subcontract.returned',
            'title' => "Subcontract returned: {$this->stageLabel($stage)}",
            'body'  => "{$vendorName} returned {$assignment->quantity_pcs} pcs — ready for QA",
            'data'  => array_merge($this->stageContext($stage), [
                'subcontract_assignment_id' => $assignment->id,
                'subcontractor_id'          => $assignment->subcontractor_id,
                'quantity_pcs'              => $assignment->quantity_pcs,
                'status'                    => $assignment->status,
            ]),
        ]);
    }
}