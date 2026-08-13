<?php

namespace App\Support\Storefront\Stock;

/**
 * THE STATUS MAP. The one piece of this module that has to be read before any
 * other, because everything else is downstream of it.
 *
 * The Stock manager kept its own `orders` table with a single flat `status`
 * column running new → in_process → to_pickup → shipped → completed, plus
 * cancelled and a two-step returns flow. This shop already has orders — 15 real
 * ones — and models the same lifecycle across THREE places:
 *
 *   orders.stage    0..4, indexing config('reefer.stages')
 *                   ['Ordered','Packed','Shipped','Out for Delivery','Delivered']
 *   orders.status   the human label: 'Processing' at stage 0, then the stage's
 *                   own name, or 'Canceled'
 *   return_requests the returns conversation, per config('reefer.returns.statuses')
 *
 * So the ERP's status is not a column here — it is a VIEW over those three. This
 * class is the only place that translation happens, in both directions.
 *
 * ---------------------------------------------------------------------------
 * READ  (shop → ERP status), first rule that matches wins:
 *
 *   orders.status is 'Canceled'/'Cancelled'         -> cancelled
 *   a live return_request (not rejected/cancelled):
 *       its status is requested | approved          -> return_requested
 *       its status is received  | refunded          -> returned
 *   otherwise by orders.stage:
 *       0 Ordered                                   -> new
 *       1 Packed                                    -> in_process
 *       2 Shipped                                   -> to_pickup
 *       3 Out for Delivery                          -> shipped
 *       4 Delivered                                 -> completed
 *
 * The pipeline halves line up BY POSITION, not by name. Both vocabularies have
 * exactly five pipeline slots in the same order, so an index map is lossless and
 * round-trips; matching on the words instead would have to collapse in_process
 * and to_pickup onto 'Packed' and throw away which of the two an order was in.
 * The two name clashes it produces (ERP 'to_pickup' sits on the shop's 'Shipped',
 * ERP 'shipped' on 'Out for Delivery') are cosmetic: both vocabularies mean
 * "packed and waiting for the courier" at index 2 and "with the courier" at 3.
 *
 * A cancelled order keeps its stage, so it still remembers how far it got —
 * which is exactly what the Orders screen's stageDate() fallback expects.
 *
 * ---------------------------------------------------------------------------
 * WRITE (ERP status → shop):
 *
 *   new..completed  stage := the index above; status := 'Processing' at stage 0
 *                   (the shop's own initial value — nothing there ever writes
 *                   'Ordered'), else config('reefer.stages')[stage], which is
 *                   exactly what OrderController::advance() writes.
 *                   delivered_at is stamped once, on first arrival at stage 4.
 *   cancelled       status := 'Canceled'; stage untouched.
 *   return_requested / returned  never touch orders at all — they move the
 *                   return_requests row (see OrdersController::applyReturnTransition).
 *
 * ---------------------------------------------------------------------------
 * ALLOCATED_STATUSES is preserved verbatim from the source. There it decided the
 * grid's derived "Order Allocated" column; here the same set decides which orders
 * are counted in the STORED product_variants.allocated. Same meaning, same
 * membership — 'completed' still deliberately absent, because that stock has
 * shipped and left the building.
 */
class OrderPipeline
{
    /** The ERP's pipeline, in order. Index == orders.stage. */
    public const PIPELINE = ['new', 'in_process', 'to_pickup', 'shipped', 'completed'];

    /** Every ERP status the UI knows about. */
    public const STATUSES = ['new', 'in_process', 'to_pickup', 'shipped', 'completed', 'cancelled', 'return_requested', 'returned'];

    /** Verbatim from App\Support\InventoryData. 'completed' is deliberately absent. */
    public const ALLOCATED_STATUSES = ['new', 'in_process', 'to_pickup', 'shipped'];

    public const CANCELLED_STATUSES = ['cancelled'];

    /** What orders.status holds for a cancelled order (see the orders migration). */
    public const SHOP_CANCELLED = 'Canceled';

    /** Read side: both spellings are accepted, since only the write side is ours. */
    public const SHOP_CANCELLED_ALIASES = ['canceled', 'cancelled'];

    /** return_requests.status values that mean "the customer is waiting on us". */
    public const RETURN_OPEN = ['requested', 'approved'];

    /** return_requests.status values that mean "the goods are back with us". */
    public const RETURN_SETTLED = ['received', 'refunded'];

    /** return_requests.status values that release their claim (App\Models\ReturnRequest::DEAD_STATUSES). */
    public const RETURN_DEAD = ['cancelled', 'rejected'];

    /**
     * Where an order's units physically sit, per ERP status. The whole
     * stock-movement engine is derived from this table and nothing else — see
     * OrderStock::delta().
     *
     *   held        still ours, spoken for by an open order  (allocated +1)
     *   shipped_out gone from the warehouse                  (on_hand -1)
     *   released    never left; the reservation was undone   (cancelled_qty +1)
     *   restocked   came back and is sellable again          (no net effect)
     *
     * return_requested sits at shipped_out on purpose: the customer has the
     * goods while they wait for a decision, whether the order was 'shipped' or
     * 'completed' when they asked.
     */
    public const POSITION = [
        'new' => 'held',
        'in_process' => 'held',
        'to_pickup' => 'held',
        'shipped' => 'held',
        'completed' => 'shipped_out',
        'return_requested' => 'shipped_out',
        'cancelled' => 'released',
        'returned' => 'restocked',
    ];

    /** Per-unit (on_hand, allocated, cancelled_qty) effect of each position. */
    public const POSITION_VECTOR = [
        'held' => ['on_hand' => 0, 'allocated' => 1, 'cancelled_qty' => 0],
        'shipped_out' => ['on_hand' => -1, 'allocated' => 0, 'cancelled_qty' => 0],
        'released' => ['on_hand' => 0, 'allocated' => 0, 'cancelled_qty' => 1],
        'restocked' => ['on_hand' => 0, 'allocated' => 0, 'cancelled_qty' => 0],
        // The state before an order exists at all, for the intake path.
        'none' => ['on_hand' => 0, 'allocated' => 0, 'cancelled_qty' => 0],
    ];

    /**
     * The audit-log `field` value under which this module records an order's
     * status transitions. Those rows are how OrderPresenter recovers the three
     * stage dates this schema has no column for — see its class note.
     */
    public const LOG_FIELD_STATUS = 'order_status';

    /** Which column records the date an order ENTERED a given stage (source constant). */
    public const STAGE_DATE_FIELD = [
        'in_process' => 'in_process_date',
        'to_pickup' => 'to_pickup_date',
        'shipped' => 'shipped_date',
        'completed' => 'completed_date',
        'return_requested' => 'return_requested_date',
        'returned' => 'returned_date',
    ];

    public static function isPipeline(string $status): bool
    {
        return in_array($status, self::PIPELINE, true);
    }

    public static function isKnown(string $status): bool
    {
        return in_array($status, self::STATUSES, true);
    }

    /** ERP pipeline status -> orders.stage, or null for the non-pipeline ones. */
    public static function stageFor(string $status): ?int
    {
        $index = array_search($status, self::PIPELINE, true);

        return $index === false ? null : (int) $index;
    }

    /** orders.stage -> ERP pipeline status. Out-of-range stages clamp. */
    public static function statusForStage(int $stage): string
    {
        $last = count(self::PIPELINE) - 1;

        return self::PIPELINE[max(0, min($last, $stage))];
    }

    /**
     * What orders.status should read for a pipeline stage.
     *
     * Stage 0 keeps 'Processing' rather than becoming 'Ordered': that is the
     * value the shop's own checkout writes, nothing in the shop ever writes
     * 'Ordered', and the storefront's Account page lowercases and compares this
     * string. Stages 1..4 use the stage label, byte for byte what
     * OrderController::advance() writes.
     */
    public static function shopStatusForStage(int $stage): string
    {
        if ($stage <= 0) {
            return 'Processing';
        }

        $stages = (array) config('reefer.stages', []);

        return (string) ($stages[$stage] ?? 'Processing');
    }

    public static function isShopCancelled(?string $shopStatus): bool
    {
        return in_array(strtolower(trim((string) $shopStatus)), self::SHOP_CANCELLED_ALIASES, true);
    }

    /**
     * The read-side translation.
     *
     * @param  object|null  $liveReturn  the order's newest non-dead return_requests row
     */
    public static function statusFor(?string $shopStatus, int $stage, ?object $liveReturn = null): string
    {
        if (self::isShopCancelled($shopStatus)) {
            return 'cancelled';
        }

        if ($liveReturn !== null) {
            $returnStatus = strtolower(trim((string) $liveReturn->status));

            if (in_array($returnStatus, self::RETURN_SETTLED, true)) {
                return 'returned';
            }

            if (in_array($returnStatus, self::RETURN_OPEN, true)) {
                return 'return_requested';
            }
        }

        return self::statusForStage($stage);
    }

    public static function isAllocated(string $status): bool
    {
        return in_array($status, self::ALLOCATED_STATUSES, true);
    }
}
