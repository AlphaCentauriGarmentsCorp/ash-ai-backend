<?php

namespace App\Http\Controllers\Storefront\Stock;

use App\Http\Controllers\Controller;
use App\Support\Storefront\Stock\CatalogPresenter;
use App\Support\Storefront\Stock\OrderPipeline;
use App\Support\Storefront\Stock\OrderPresenter;
use App\Support\Storefront\Stock\OrderReport;
use App\Support\Storefront\Stock\OrderStock;
use App\Support\Storefront\Stock\StockActivityLog;
use App\Support\Storefront\Stock\XlsxWriter;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The Orders Queue API — port of the Stock manager's OrdersController.
 *
 *   GET  /api/stocks/orders          the whole queue, newest first
 *   PUT  /api/stocks/orders/{id}     move one order along the pipeline
 *   POST /api/stocks/orders          record an off-site order (gated, see store)
 *   GET  /api/stocks/orders/report   the date-range analytics workbook
 *
 * Same paths below the prefix, same JSON, same {"error": "..."} failures, same
 * status codes. What is NOT the same is what sits underneath: there is no ERP
 * `orders` table here. These endpoints read and write the SHOP's live orders —
 * 15 real ones — through two translations:
 *
 *   App\Support\Storefront\Stock\OrderPipeline   the status vocabulary, both directions
 *   App\Support\Storefront\Stock\OrderStock      what each transition does to stock
 *
 * Read those two before changing anything here. This file is the HTTP surface
 * and the guard rails; the semantics live there.
 *
 * ⚠ EVERY WRITE IN THIS FILE LANDS ON A LIVE SHOP. The three rules it keeps:
 *   * one transaction per order change, with the order row locked first;
 *   * stock moves only by the difference between two statuses, so a repeated
 *     save is arithmetically a no-op rather than a second deduction;
 *   * every quantity that moves leaves an audit row naming who moved it.
 */
class OrdersController extends Controller
{
    /** The audit-log field that stands in for the source's external_order_id column. */
    private const LOG_FIELD_EXTERNAL_ID = 'external_order_id';

    /** The source's error shape, kept exactly: {"error": "..."} with a real status. */
    private function fail(int $status, string $message): never
    {
        throw new HttpResponseException(response()->json(['error' => $message], $status));
    }

    /**
     * Who to attribute a movement to. The module's auth middleware puts the staff
     * session on the request (same attribute name as the source's
     * ErpAuthenticate); order-driven movement with no human behind it stays
     * 'system', exactly as adjustInventory() logged it.
     */
    private function actor(Request $request): string
    {
        $user = $request->attributes->get('authUser');

        if (is_array($user)) {
            $name = trim((string) ($user['username'] ?? ''));

            if ($name !== '') {
                return $name;
            }
        }

        $claimed = trim((string) $request->input('user', ''));

        return $claimed !== '' ? $claimed : 'system';
    }

    // ------------------------------------------------------------------ read

    /** GET /orders */
    public function index()
    {
        return response()->json(OrderPresenter::all());
    }

    // ---------------------------------------------------------------- update

    /**
     * PUT /orders/{orderId} — the one endpoint the Orders screen, the Scan
     * Station and the bulk command bar all drive.
     *
     * Body: { status, courier? }. Only the waybill-print flow sends a courier.
     */
    public function updateStatus(Request $request, string $orderId)
    {
        $newStatus = (string) $request->input('status', '');
        $courier = mb_substr(trim((string) $request->input('courier', '')), 0, 60);
        $actor = $this->actor($request);

        if (! OrderPipeline::isKnown($newStatus)) {
            $this->fail(400, 'Status must be one of: '.implode(', ', OrderPipeline::STATUSES));
        }

        DB::transaction(function () use ($orderId, $newStatus, $courier, $actor) {
            $order = DB::table('storefront_orders')->where('order_number', $orderId)->lockForUpdate()->first();

            if ($order === null) {
                $this->fail(404, 'Order not found: '.$orderId);
            }

            // Lock the return row in the same transaction, so an approval and a
            // customer cancelling the same return cannot interleave.
            $liveReturn = OrderPresenter::liveReturns([(int) $order->id])[(int) $order->id] ?? null;

            if ($liveReturn !== null) {
                $liveReturn = DB::table('storefront_return_requests')->where('id', $liveReturn->id)->lockForUpdate()->first();
            }

            $oldStatus = OrderPipeline::statusFor($order->status ?? null, (int) $order->stage, $liveReturn);

            if ($newStatus === $oldStatus) {
                // Same as the source: a no-op save is not an error, and it must
                // not move stock. Falls through to the re-read below.
                return;
            }

            // ---- Returns: guarded transitions, verbatim from the source ----
            // shipped/completed -> return_requested -> returned; 'returned' can
            // never be set directly, and a returned order can only be walked back
            // to return_requested.
            if ($newStatus === 'return_requested'
                && ! in_array($oldStatus, ['shipped', 'completed', 'returned'], true)) {
                $this->fail(400, 'A return can only be requested for a shipped or completed order.');
            }

            if ($newStatus === 'returned' && $oldStatus !== 'return_requested') {
                $this->fail(400, 'Returns need manual approval: mark the order Return Requested first, then approve it from the order panel.');
            }

            if ($oldStatus === 'returned' && $newStatus !== 'return_requested') {
                $this->fail(400, 'A returned order can only be moved back to Return Requested (to undo the approval).');
            }

            $this->applyOrderSide($order, $newStatus, $courier);
            $this->applyReturnTransition($order, $oldStatus, $newStatus, $liveReturn);
            $this->applyStockMovement($order, $oldStatus, $newStatus, $liveReturn, $actor);

            StockActivityLog::write([
                'sku' => (string) $order->order_number,
                'product_name' => $order->ship_to_name,
                'field' => OrderPipeline::LOG_FIELD_STATUS,
                'old_value' => $oldStatus,
                'new_value' => $newStatus,
                'delta' => '',
                'reason' => 'Order status changed',
                'notes' => 'Order '.$order->order_number.': '.$oldStatus.' → '.$newStatus
                    .($courier !== '' ? ' · courier '.$courier : ''),
                'user' => $actor,
            ]);
        });

        $updated = OrderPresenter::one($orderId);

        if ($updated === null) {
            $this->fail(404, 'Order not found: '.$orderId);
        }

        return response()->json($updated);
    }

    /**
     * The orders-table half of a transition.
     *
     * Pipeline statuses write stage + the label the shop's own advance() writes;
     * cancelling writes 'Canceled' and leaves the stage alone, so a cancelled
     * order still remembers how far it got. return_requested / returned write
     * nothing here — they are a return_requests state, not an order state.
     */
    private function applyOrderSide(object $order, string $newStatus, string $courier): void
    {
        $updates = [];

        // Courier assignment rides along with the waybill-print advance, because
        // the waybill is where a courier is actually chosen. Assigning one mints
        // the tracking number exactly once — in the SHOP's own format ('RFR' + 9),
        // not the source's TTS-PH-#######, so every number this shop has ever
        // issued still looks like one thing. A re-assignment keeps the number.
        if ($courier !== '') {
            $updates['courier'] = $courier;

            if (empty($order->tracking_number)) {
                $updates['tracking_number'] = 'RFR'.Str::upper(Str::random(9));

                if (empty($order->eta)) {
                    $updates['eta'] = now()->addDays(2);
                }
            }
        }

        if ($newStatus === 'cancelled') {
            $updates['status'] = OrderPipeline::SHOP_CANCELLED;
        } elseif (OrderPipeline::isPipeline($newStatus)) {
            $stage = (int) OrderPipeline::stageFor($newStatus);
            $updates['stage'] = $stage;
            $updates['status'] = OrderPipeline::shopStatusForStage($stage);

            // The stamp the returns window reads. Set once — a second pass must
            // not move the arrival date and quietly extend the window. Never
            // cleared on the way back either: an order that was delivered was
            // delivered, whatever the paperwork says afterwards.
            if ($stage === count((array) config('reefer.stages', [])) - 1 && empty($order->delivered_at)) {
                $updates['delivered_at'] = now();
            }
        }

        if ($updates === []) {
            return;
        }

        $updates['updated_at'] = now();

        DB::table('storefront_orders')->where('id', $order->id)->update($updates);
    }

    /**
     * The return_requests half.
     *
     * The ERP kept returns in the order's own status column; this shop keeps them
     * in a table the CUSTOMER writes to from their account. So the two return
     * statuses are a view over that row, and moving an order in or out of them
     * means moving the return:
     *
     *   -> return_requested   an existing return is re-opened ('requested').
     *                         There must BE one: a return is the customer's to
     *                         open, and inventing one would put words in their
     *                         mouth — and cannot be done at all for the guest
     *                         orders in this table, whose return_requests row
     *                         would have no user to belong to.
     *   -> returned           the goods are back: 'received', resolved now.
     *                         (This is the Approve Return button.)
     *   return_requested -> a pipeline status   the shop said no: 'rejected'.
     *                         (This is the Reject Request button.)
     *   -> cancelled          the order is void, so the return is moot:
     *                         'cancelled', which is one of the shop's own
     *                         claim-releasing statuses.
     */
    private function applyReturnTransition(object $order, string $oldStatus, string $newStatus, ?object $liveReturn): void
    {
        $wasReturn = in_array($oldStatus, ['return_requested', 'returned'], true);
        $isReturn = in_array($newStatus, ['return_requested', 'returned'], true);

        if (! $wasReturn && ! $isReturn) {
            return;
        }

        if ($isReturn && $liveReturn === null) {
            $this->fail(400, 'Order '.$order->order_number.' has no open return request. A return is opened by the customer from their account — approve or reject it here once it exists.');
        }

        if ($newStatus === 'return_requested') {
            DB::table('storefront_return_requests')->where('id', $liveReturn->id)->update([
                'status' => 'requested',
                'resolved_at' => null,
                'updated_at' => now(),
            ]);

            return;
        }

        if ($newStatus === 'returned') {
            DB::table('storefront_return_requests')->where('id', $liveReturn->id)->update([
                'status' => 'received',
                'resolved_at' => now(),
                'updated_at' => now(),
            ]);

            return;
        }

        // Leaving the returns flow. Without this the derived status would snap
        // straight back to return_requested on the next read and the Reject
        // button would look like it did nothing.
        if ($liveReturn !== null) {
            DB::table('storefront_return_requests')->where('id', $liveReturn->id)->update([
                'status' => $newStatus === 'cancelled' ? 'cancelled' : 'rejected',
                'resolved_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * The stock half. One subtraction between two positions — see OrderStock.
     *
     * Quantities come from the order's own lines, EXCEPT across the approve/undo
     * pair, where they come from the return: this shop's returns are per-line and
     * partial, so approving a return for one of three items must put one item back
     * on the shelf, not three.
     */
    private function applyStockMovement(object $order, string $oldStatus, string $newStatus, ?object $liveReturn, string $actor): void
    {
        if (! config('stock.orders.move_stock', true)) {
            return;
        }

        $delta = OrderStock::delta($oldStatus, $newStatus);

        if (OrderStock::isNoMovement($delta)) {
            return;
        }

        $lines = OrderStock::linesByOrder([(int) $order->id], lock: true)[(int) $order->id] ?? [];

        if ($lines === []) {
            return;
        }

        $isApprovalPair = in_array($oldStatus, ['return_requested', 'returned'], true)
            && in_array($newStatus, ['return_requested', 'returned'], true);

        if ($isApprovalPair && $liveReturn !== null) {
            $returnLines = OrderStock::returnLines((int) $liveReturn->id, $lines);

            if ($returnLines !== []) {
                $lines = $returnLines;
            }
        }

        [$reason, $notes] = $this->movementWording($order, $oldStatus, $newStatus);

        OrderStock::apply($lines, $delta, $reason, $notes, $actor);
    }

    /**
     * The source's own log wording, extended to the transitions this schema can
     * express that its flat status column could not.
     *
     * @return array{0: string, 1: string}
     */
    private function movementWording(object $order, string $oldStatus, string $newStatus): array
    {
        $id = (string) $order->order_number;

        return match (true) {
            $newStatus === 'cancelled' => ['Order cancelled', 'Stock released — order '.$id.' marked cancelled'],
            $oldStatus === 'cancelled' => ['Order reactivated', 'Re-reserved — order '.$id.' moved back to '.$newStatus],
            $newStatus === 'returned' => ['Return approved', 'Stock restored — order '.$id.' return approved'],
            $oldStatus === 'returned' => ['Return approval undone', 'Stock re-removed — order '.$id.' moved back to '.$newStatus],
            OrderPipeline::isAllocated($oldStatus) && ! OrderPipeline::isAllocated($newStatus) => [
                'Order fulfilled', 'Shipped out — order '.$id.' marked '.$newStatus,
            ],
            ! OrderPipeline::isAllocated($oldStatus) && OrderPipeline::isAllocated($newStatus) => [
                'Order reopened', 'Re-reserved — order '.$id.' moved back to '.$newStatus,
            ],
            default => ['Order status changed', 'Order '.$id.': '.$oldStatus.' → '.$newStatus],
        };
    }

    // ----------------------------------------------------------------- intake

    /**
     * POST /orders — record an order that was placed somewhere this backend does
     * not own the checkout for (the marketplace channel: TikTok and friends).
     *
     * ⚠ OFF BY DEFAULT. Set stock.orders.allow_intake to true in config/stock.php
     * to arm it. Three reasons it ships disarmed:
     *
     *   * nothing in the Stock manager UI calls it — the frontend only ever GETs
     *     /orders and PUTs /orders/{id};
     *   * in the source it existed because the storefront lived in a DIFFERENT
     *     database and had to forward its checkouts across. This shop's own
     *     checkout writes these very tables, so that reason is gone;
     *   * it inserts into a live orders table and reserves live stock.
     *
     * The source's second mode — the random single-item SIMULATION used for demos
     * — is deliberately not ported at all. Inventing customers and orders in a
     * shop with real ones is not a thing a warehouse tool should be able to do.
     *
     * Body: { items:[{sku, qty, price?}], customer_name, email, phone, street|address,
     *         city, province, barangay?, region?, postal?, shipping_method?,
     *         shipping_fee?, payment_method?, payment_status?, external_order_id? }
     */
    public function store(Request $request)
    {
        if (! config('stock.orders.allow_intake', false)) {
            $this->fail(403, 'Order intake is switched off. Orders are placed through the shop checkout; set stock.orders.allow_intake to record marketplace orders here.');
        }

        $items = $request->input('items');

        if (! is_array($items) || count($items) === 0) {
            $this->fail(400, 'An order needs at least one item.');
        }

        $actor = $this->actor($request);
        $externalId = mb_substr(trim((string) $request->input('external_order_id', '')), 0, 60);

        // Idempotency. The source keyed this on its own external_order_id column;
        // this schema has none and may not gain one, so the marker lives in the
        // audit log instead. Without that table there is no dedupe — a retrying
        // forwarder would place the order twice, which is why intake and the log
        // belong switched on together.
        if ($externalId !== '') {
            $existing = $this->findByExternalId($externalId);

            if ($existing !== null) {
                return response()->json(['orders' => [$existing], 'order' => $existing,
                    'customer_name' => $existing['customer_name'], 'courier' => $existing['courier']]);
            }
        }

        $customerName = trim((string) $request->input('customer_name', ''));
        $email = trim((string) $request->input('email', ''));
        $phone = trim((string) $request->input('phone', ''));
        $street = trim((string) $request->input('street', '')) ?: trim((string) $request->input('address', ''));
        $city = trim((string) $request->input('city', ''));
        $province = trim((string) $request->input('province', ''));

        // The shop's orders table declares these NOT NULL, and an order with no
        // one to ship to is not an order. Refused up front rather than stored blank.
        foreach ([
            'customer_name' => $customerName, 'email' => $email, 'phone' => $phone,
            'street (or address)' => $street, 'city' => $city, 'province' => $province,
        ] as $label => $value) {
            if ($value === '') {
                $this->fail(400, 'Missing '.$label.' — the shop records a full shipping snapshot on every order.');
            }
        }

        $shippingMethod = (string) $request->input('shipping_method', 'golocal');

        if (! array_key_exists($shippingMethod, (array) config('reefer.shipping_methods', []))) {
            $this->fail(400, 'Shipping method must be one of: '.implode(', ', array_keys((array) config('reefer.shipping_methods', []))));
        }

        $paymentMethod = (string) $request->input('payment_method', 'cod');

        if (! in_array($paymentMethod, (array) config('reefer.payment_methods', []), true)) {
            $this->fail(400, 'Payment method must be one of: '.implode(', ', (array) config('reefer.payment_methods', [])));
        }

        $order = DB::transaction(function () use ($request, $items, $customerName, $email, $phone, $street, $city, $province, $shippingMethod, $paymentMethod, $externalId, $actor) {
            // Validate the whole cart before writing anything — never partially
            // commit an order. Every variant involved is locked, so two intakes
            // racing for the last unit cannot both pass.
            $skus = [];

            foreach ($items as $item) {
                $sku = is_array($item) ? trim((string) ($item['sku'] ?? '')) : '';
                $qty = is_array($item) ? (int) ($item['qty'] ?? 0) : 0;

                if ($sku === '' || $qty < 1) {
                    $this->fail(400, 'Each item needs a valid SKU and a quantity of at least 1.');
                }

                $skus[$sku] = ($skus[$sku] ?? 0) + $qty;
            }

            $variants = DB::table('storefront_product_variants')
                ->whereIn('sku', array_keys($skus))
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('sku');

            $productIds = $variants->pluck('product_id')->unique()->values()->all();
            $products = DB::table('storefront_products')->whereIn('id', $productIds)->get()->keyBy('id');

            $lines = [];
            $subtotal = 0;
            $totalQty = 0;

            foreach ($items as $item) {
                $sku = trim((string) $item['sku']);
                $variant = $variants->get($sku);

                if ($variant === null) {
                    $this->fail(400, 'Unknown SKU: '.$sku);
                }

                $product = $products->get($variant->product_id);

                if ($product === null) {
                    $this->fail(400, 'SKU '.$sku.' has no product record.');
                }

                $available = max(0, (int) $variant->on_hand - (int) $variant->allocated);

                if ($skus[$sku] > $available) {
                    $this->fail(400, 'Not enough stock for '.$product->name.' ('.$variant->size.'). Only '.$available.' available.');
                }

                $qty = (int) $item['qty'];

                // Honour a price the sender supplies — what was charged is history,
                // not a number to recompute. Whole pesos: the column is an unsigned
                // integer, same as every other money column in this schema.
                $unitPrice = (isset($item['price']) && is_numeric($item['price']) && (float) $item['price'] >= 0)
                    ? (int) round((float) $item['price'])
                    : (int) $product->price;

                $lines[] = [
                    'variant_id' => (int) $variant->id,
                    'product_id' => (int) $product->id,
                    'product_slug' => $product->slug,
                    'name' => $product->name,
                    'size' => $variant->size,
                    'unit_price' => $unitPrice,
                    'qty' => $qty,
                    'line_total' => $unitPrice * $qty,
                    'sku' => $variant->sku,
                ];

                $subtotal += $unitPrice * $qty;
                $totalQty += $qty;
            }

            $shippingFee = max(0, (int) round((float) $request->input('shipping_fee', 0)));
            $paymentStatus = trim((string) $request->input('payment_status', ''))
                ?: ($paymentMethod === 'cod' ? 'cod' : 'pending');

            $now = now();

            // The unique placeholder is random rather than a literal 'pending':
            // two intakes in the same instant must not collide on it.
            $orderId = DB::table('storefront_orders')->insertGetId([
                'order_number' => 'pending-'.Str::random(16),
                'user_id' => null,
                'email' => mb_substr($email, 0, 255),
                'ship_to_name' => mb_substr($customerName, 0, 255),
                'phone' => mb_substr($phone, 0, 255),
                'street' => mb_substr($street, 0, 255),
                'barangay' => mb_substr(trim((string) $request->input('barangay', '')), 0, 255) ?: null,
                'city' => mb_substr($city, 0, 255),
                'province' => mb_substr($province, 0, 255),
                'region' => mb_substr(trim((string) $request->input('region', '')), 0, 255) ?: null,
                'postal' => mb_substr(trim((string) $request->input('postal', '')), 0, 10) ?: null,
                'shipping_method' => $shippingMethod,
                'subtotal' => $subtotal,
                'discount_code' => null,
                'discount_amount' => 0,
                'shipping_fee' => $shippingFee,
                'total' => $subtotal + $shippingFee,
                'payment_method' => $paymentMethod,
                'payment_status' => $paymentStatus,
                'payment_ref' => $externalId !== '' ? $externalId : null,
                'status' => OrderPipeline::shopStatusForStage(0),
                'stage' => 0,
                'placed_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            // The shop's own numbering formula, so an ERP-recorded order is
            // indistinguishable from a checkout one in the ledger.
            $orderNumber = config('reefer.order_prefix')
                .str_pad((string) (((int) config('reefer.order_seq_start')) + $orderId - 1), 7, '0', STR_PAD_LEFT);

            DB::table('storefront_orders')->where('id', $orderId)->update(['order_number' => $orderNumber]);

            DB::table('storefront_order_items')->insert(array_map(fn (array $line) => [
                'order_id' => $orderId,
                'product_id' => $line['product_id'],
                'product_slug' => $line['product_slug'],
                'name' => $line['name'],
                'size' => $line['size'],
                'unit_price' => $line['unit_price'],
                'qty' => $line['qty'],
                'line_total' => $line['line_total'],
                'created_at' => $now,
                'updated_at' => $now,
            ], $lines));

            // Reserve: the same movement the shop's own checkout performs, written
            // as the none -> new transition so it goes through one engine.
            if (config('stock.orders.move_stock', true)) {
                OrderStock::apply(
                    $lines,
                    OrderStock::delta('none', 'new'),
                    'Order placed',
                    'Reserved for order '.$orderNumber,
                    $actor,
                );
            }

            StockActivityLog::write([
                'sku' => $orderNumber,
                'product_name' => $customerName,
                'field' => OrderPipeline::LOG_FIELD_STATUS,
                'old_value' => '—',
                'new_value' => 'new',
                'delta' => (string) $totalQty,
                'reason' => 'Order placed',
                'notes' => 'Recorded through the Stock manager'
                    .($externalId !== '' ? ' · external '.$externalId : ''),
                'user' => $actor,
            ]);

            if ($externalId !== '') {
                StockActivityLog::write([
                    'sku' => $orderNumber,
                    'product_name' => $customerName,
                    'field' => self::LOG_FIELD_EXTERNAL_ID,
                    'old_value' => '—',
                    'new_value' => $externalId,
                    'delta' => '',
                    'reason' => 'Order intake',
                    'notes' => 'Idempotency marker for external order '.$externalId,
                    'user' => $actor,
                ]);
            }

            return $orderNumber;
        });

        $presented = OrderPresenter::one($order);

        return response()->json([
            'orders' => [$presented], 'order' => $presented,
            'customer_name' => $presented['customer_name'] ?? null,
            'courier' => $presented['courier'] ?? null,
        ]);
    }

    /**
     * Has this marketplace order already been recorded?
     *
     * Two places to look, because the source's dedicated external_order_id column
     * does not exist here and may not be added. orders.payment_ref carries it (it
     * is the charge reference for an order this backend did not charge, which is
     * exactly what a marketplace id is), and the intake also drops a marker row in
     * the audit log. Either one answering is enough.
     */
    private function findByExternalId(string $externalId): ?array
    {
        $orderNumber = DB::table('storefront_orders')
            ->where('payment_ref', $externalId)
            ->orderByDesc('id')
            ->value('order_number');

        if ($orderNumber === null) {
            $orderNumber = DB::table(StockActivityLog::TABLE)
                ->where('field', self::LOG_FIELD_EXTERNAL_ID)
                ->where('new_value', $externalId)
                ->orderByDesc('id')
                ->value('sku');
        }

        return $orderNumber !== null ? OrderPresenter::one((string) $orderNumber) : null;
    }

    // ----------------------------------------------------------------- report

    /**
     * GET /orders/report?start=&end=&user= — the analytics workbook.
     *
     * Every figure is computed by OrderReport exactly as the source computed it.
     * The WORKBOOK is not the source's: PhpSpreadsheet is not installed here and
     * this module may not run composer, so the sheets are written by
     * App\Support\Storefront\Stock\XlsxWriter — same sheets, same columns, same numbers, no
     * styling. See that class for how to restore the styled original.
     *
     * ?format=json returns the computed dataset instead, which is also the escape
     * hatch if the zip extension is missing.
     */
    public function report(Request $request)
    {
        $start = (string) $request->query('start', '0000-00-00');
        $end = (string) $request->query('end', '9999-99-99');
        $generatedBy = trim((string) $request->query('user', '')) !== ''
            ? trim((string) $request->query('user'))
            : 'Unknown';

        $data = OrderReport::build(
            OrderPresenter::all(),
            CatalogPresenter::all(),
            $start,
            $end,
            $generatedBy,
        );

        if ((string) $request->query('format', '') === 'json') {
            return response()->json($data);
        }

        // The upgrade path, made a drop-in: install phpoffice/phpspreadsheet and
        // put the source's styled builder at App\Support\Storefront\Stock\ReportBuilder, and
        // the export starts coming out styled with no edit here. OrderReport
        // returns exactly the payload that class takes.
        if (class_exists(\App\Support\Storefront\Stock\ReportBuilder::class)
            && class_exists(\PhpOffice\PhpSpreadsheet\Spreadsheet::class)) {
            return response(\App\Support\Storefront\Stock\ReportBuilder::build($data), 200, [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'Content-Disposition' => 'attachment; filename="stock-report-'.$start.'_to_'.$end.'.xlsx"',
            ]);
        }

        if (! XlsxWriter::available()) {
            $this->fail(503, 'This server cannot build .xlsx files (the zip extension is missing). Add ?format=json to get the same report as data.');
        }

        $bytes = XlsxWriter::build(OrderReport::sheets($data));

        return response($bytes, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="stock-report-'.$start.'_to_'.$end.'.xlsx"',
        ]);
    }
}
