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
     * A material/purchase request was created (Phase 3 entry-point).
     * → Notify purchasing + warehouse_manager + managers.
     */
    public function materialRequestCreated(Order $order, array $extra = []): void
    {
        $recipients = $this->collectRecipients(
            roles: array_merge(
                $this->managerRoles,
                ['purchasing', 'warehouse_manager'],
            ),
        );

        $this->dispatch($recipients, [
            'type'  => 'material_request.created',
            'title' => "New material request: {$order->po_code}",
            'body'  => $extra['summary'] ?? 'A new material request was created.',
            'data'  => array_merge($this->orderContext($order), $extra),
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

    // =====================================================================
    // Inbox API used by NotificationController
    // =====================================================================

    /**
     * Returns the user's notifications, newest first, paginated.
     */
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
}
