<?php

namespace App\Services;

use App\Models\CsrActivityLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * CsrActivityLogger — append-only writer for csr_activity_logs.
 *
 * Use this service (NOT CsrActivityLog::create directly) so we have a
 * consistent invariant set: every row has a created_at, every row
 * gets the current Auth::id() unless explicitly overridden.
 *
 * Typical usage from other CSR services:
 *
 *   $this->logger->log(
 *       'inquiry.converted_to_quotation',
 *       summary: "INQ-2026-000012 → QUO-2026-000045",
 *       subject: $inquiry,
 *       clientId: $inquiry->client_id,
 *   );
 *
 * Polymorphic subject is set automatically when a Model is passed.
 * Denormalized `order_id` and `client_id` are explicit parameters
 * because not every subject maps cleanly to one (an Inquiry has no
 * order yet, an OrderPayment has no client without joining Order).
 */
class CsrActivityLogger
{
    /**
     * Write a CSR audit log row.
     *
     * @param string      $action    Event name like "inquiry.created".
     *                               Convention: '{subject}.{verb_past}'.
     * @param string|null $summary   Human-readable one-liner (≤255 chars).
     * @param Model|null  $subject   Polymorphic subject — sets subject_type+id.
     * @param int|null    $orderId   Denormalized order pointer.
     * @param int|null    $clientId  Denormalized client pointer.
     * @param array|null  $data      Optional structured context.
     * @param int|null    $userId    Override Auth::id() (rarely needed).
     */
    public function log(
        string  $action,
        ?string $summary  = null,
        ?Model  $subject  = null,
        ?int    $orderId  = null,
        ?int    $clientId = null,
        ?array  $data     = null,
        ?int    $userId   = null,
    ): CsrActivityLog {
        return CsrActivityLog::create([
            'user_id'      => $userId ?? Auth::id(),
            'action'       => $action,
            'subject_type' => $subject ? get_class($subject) : null,
            'subject_id'   => $subject?->getKey(),
            'order_id'     => $orderId,
            'client_id'    => $clientId,
            'summary'      => $summary !== null ? mb_substr($summary, 0, 255) : null,
            'data'         => $data,
            'created_at'   => now(),
        ]);
    }

    /**
     * Recent log entries — used by CsrDashboardService for the
     * "Recent Activity" panel and by per-order/per-client history
     * views.
     *
     * @param array{order_id?: int, client_id?: int, user_id?: int, limit?: int} $filters
     */
    public function recent(array $filters = []): \Illuminate\Database\Eloquent\Collection
    {
        $q = CsrActivityLog::query();

        if (!empty($filters['order_id'])) {
            $q->where('order_id', $filters['order_id']);
        }
        if (!empty($filters['client_id'])) {
            $q->where('client_id', $filters['client_id']);
        }
        if (!empty($filters['user_id'])) {
            $q->where('user_id', $filters['user_id']);
        }

        $limit = max(1, min(200, (int) ($filters['limit'] ?? 50)));

        return $q->orderByDesc('created_at')->limit($limit)->get();
    }
}
