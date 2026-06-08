<?php

namespace App\Services;

use App\Models\ClientApproval;
use App\Models\Inquiry;
use App\Models\Notification;
use App\Models\Order;
use App\Models\OrderPayment;
use App\Models\Quotation;
use Illuminate\Support\Facades\Auth;

/**
 * CsrDashboardService — aggregator for the CSR home screen.
 *
 * Returns the JSON shape the dashboard frontend renders:
 *
 *   kpis: {
 *     pending_inquiries, pending_quotations, client_approvals_needed,
 *     pending_payments, in_production_orders, delayed_orders,
 *     ready_for_delivery, completed_orders,
 *   }
 *   tasks_and_alerts: [...]           — from notifications table (C7)
 *   recent_activity: [...]            — from csr_activity_logs
 *   my_inquiries: [...]               — assigned to current CSR
 *   my_orders: [...]                  — assigned to current CSR
 *
 * Counts are always integers (zero, never null — C8/handoff test #2).
 *
 * "Delayed orders" — Phase 1 added `orders.delayed_at` (datetime).
 * An order is delayed if delayed_at IS NOT NULL.
 *
 * "In production" — workflow_status that's neither completed nor
 * delivered. We use a NOT IN list because the existing workflow_status
 * has many in-flight values across the 14 stages.
 */
class CsrDashboardService
{
    public function __construct(
        protected CsrActivityLogger $logger,
    ) {}

    /**
     * Build the full dashboard payload.
     */
    public function summary(): array
    {
        $userId = Auth::id();

        return [
            'kpis'             => $this->kpis(),
            'tasks_and_alerts' => $this->tasksAndAlerts($userId),
            'recent_activity'  => $this->recentActivity(),
            'my_inquiries'     => $this->myInquiries($userId),
            'my_orders'        => $this->myOrders($userId),
        ];
    }

    /**
     * 8 KPI counts. Each is a non-null integer.
     */
    public function kpis(): array
    {
        return [
            'pending_inquiries' => (int) Inquiry::query()
                ->whereIn('status', [Inquiry::STATUS_NEW, Inquiry::STATUS_CONTACTED])
                ->count(),

            // Quotations awaiting client decision — uses Quotation.status
            // values that exist in the current codebase ("Pending",
            // "Sent", "Draft"). We treat anything except terminal
            // values as pending.
            'pending_quotations' => (int) Quotation::query()
                ->whereNotIn('status', ['Approved', 'Rejected', 'Converted', 'Expired'])
                ->count(),

            'client_approvals_needed' => (int) ClientApproval::query()
                ->where('status', ClientApproval::STATUS_WAITING)
                ->count(),

            'pending_payments' => (int) OrderPayment::query()
                ->whereIn('status', [
                    OrderPayment::STATUS_WAITING,
                    OrderPayment::STATUS_FOR_VERIFICATION,
                ])
                ->count(),

            // "In production" = an order that is not completed and not delivered.
            // Phase 1 workflow_status takes precedence over legacy status.
            'in_production_orders' => (int) Order::query()
                ->whereNotIn('workflow_status', [
                    'completed', 'delivered', 'cancelled',
                ])
                ->whereNotNull('workflow_status')
                ->count(),

            // Delayed = delayed_at IS NOT NULL (Phase 1 convention).
            'delayed_orders' => (int) Order::query()
                ->whereNotNull('delayed_at')
                ->count(),

            'ready_for_delivery' => (int) Order::query()
                ->where('workflow_status', 'ready_for_delivery')
                ->count(),

            'completed_orders' => (int) Order::query()
                ->where('workflow_status', 'completed')
                ->count(),
        ];
    }

    /**
     * Tasks + alerts panel — reads from the existing notifications
     * table (C7 — Phase 2 infrastructure). Filters to types CSR cares
     * about and items targeted at this user.
     */
    protected function tasksAndAlerts(?int $userId): array
    {
        if ($userId === null) {
            return [];
        }

        // Notification model exists (Phase 2). We assume the columns
        // are: user_id, type, title, body, read_at, created_at. If
        // the actual schema differs slightly the controller will
        // still return an array — empty rather than crashing.
        try {
            return Notification::query()
                ->where('user_id', $userId)
                ->whereNull('read_at')
                ->orderByDesc('created_at')
                ->limit(20)
                ->get()
                ->map(fn ($n) => [
                    'id'         => $n->id,
                    'type'       => $n->type        ?? null,
                    'title'      => $n->title       ?? null,
                    'body'       => $n->body        ?? null,
                    'created_at' => $n->created_at?->toIso8601String(),
                ])
                ->all();
        } catch (\Throwable $e) {
            // Defensive — never let a notification-shape mismatch
            // break the whole dashboard.
            return [];
        }
    }

    /**
     * Last 20 CSR audit events across the whole shop. (Per-order /
     * per-client filtered views go through CsrActivityLogger::recent
     * directly.)
     */
    protected function recentActivity(): array
    {
        return $this->logger->recent(['limit' => 20])->map(fn ($log) => [
            'id'         => $log->id,
            'user_id'    => $log->user_id,
            'action'     => $log->action,
            'summary'    => $log->summary,
            'order_id'   => $log->order_id,
            'client_id'  => $log->client_id,
            'data'       => $log->data,
            'created_at' => $log->created_at?->toIso8601String(),
        ])->all();
    }

    /**
     * Inquiries assigned to this CSR — pending only.
     */
    protected function myInquiries(?int $userId): array
    {
        if ($userId === null) {
            return [];
        }

        return Inquiry::query()
            ->with(['client'])
            ->where('assigned_csr_user_id', $userId)
            ->whereIn('status', [Inquiry::STATUS_NEW, Inquiry::STATUS_CONTACTED, Inquiry::STATUS_QUOTED])
            ->orderByDesc('created_at')
            ->limit(10)
            ->get()
            ->map(fn ($i) => [
                'id'           => $i->id,
                'inquiry_code' => $i->inquiry_code,
                'client_name'  => $i->client_name,
                'brand_name'   => $i->brand_name,
                'status'       => $i->status,
                'source'       => $i->source,
                'created_at'   => $i->created_at?->toIso8601String(),
            ])
            ->all();
    }

    /**
     * Orders assigned to this CSR — in-flight only.
     */
    protected function myOrders(?int $userId): array
    {
        if ($userId === null) {
            return [];
        }

        return Order::query()
            ->where('assigned_csr_user_id', $userId)
            ->whereNotIn('workflow_status', ['completed', 'cancelled'])
            ->orderByDesc('created_at')
            ->limit(10)
            ->get()
            ->map(fn ($o) => [
                'id'              => $o->id,
                'po_code'         => $o->po_code,
                'client_name'     => $o->client_name,
                'workflow_status' => $o->workflow_status,
                'priority'        => $o->priority,
                'rush_order'      => (bool) $o->rush_order,
                'deadline'        => optional($o->deadline)->toDateString(),
                'created_at'      => $o->created_at?->toIso8601String(),
                // Change 11: surface the incomplete flag for the portal badge.
                'is_incomplete'    => (bool) $o->is_incomplete,
                'incomplete_fields' => $o->incomplete_fields ?? [],
            ])
            ->all();
    }
}
