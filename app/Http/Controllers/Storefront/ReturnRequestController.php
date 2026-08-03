<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Http\Requests\Storefront\StoreReturnRequest;
use App\Http\Resources\Storefront\ReturnRequestResource;
use App\Mail\Storefront\ReturnRequestedMail;
use App\Models\Storefront\Order;
use App\Models\Storefront\OrderItem;
use App\Models\Storefront\ReturnRequest;
use App\Models\Storefront\ReturnRequestItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * The customer half of returns.
 *
 * The FAQ promises "unworn, unwashed pieces with tags still on can be returned within
 * 7 days of delivery". Condition is a human judgement made when the parcel arrives at
 * the shop, so this endpoint enforces the half a server can know: the order is yours,
 * it was delivered, it is inside the window, and you are not asking for more of a line
 * than you bought. Everything after 'requested' is the shop's side of the conversation.
 */
class ReturnRequestController extends Controller
{
    /** GET /returns — the caller's own, newest first. */
    public function index(): AnonymousResourceCollection
    {
        $returns = ReturnRequest::query()
            ->where('user_id', auth()->id())
            ->latest('requested_at')
            ->latest('id')
            ->with(['order', 'items.orderItem'])
            ->paginate(15);

        return ReturnRequestResource::collection($returns);
    }

    /** GET /returns/{reference} */
    public function show(ReturnRequest $returnRequest): ReturnRequestResource
    {
        $this->assertOwned($returnRequest);

        return new ReturnRequestResource($returnRequest->load(['order', 'items.orderItem']));
    }

    /** POST /orders/{order_number}/returns */
    public function store(StoreReturnRequest $request, string $order): JsonResponse
    {
        $data = $request->validated();

        // Resolved here rather than by route binding: the SPA holds order_number, not
        // the id. Ownership is part of the same query, so someone else's order is
        // indistinguishable from one that does not exist — a 403 would confirm it.
        $order = Order::query()
            ->where('order_number', $order)
            ->where('user_id', auth()->id())
            ->first();

        abort_if($order === null, 404);

        $this->assertReturnable($order);

        $returnRequest = DB::transaction(function () use ($order, $data) {
            // Reads the order lines and what is already claimed against them under a
            // lock, so two requests fired at once cannot both be told the same unit
            // is free.
            $lines = $this->resolveLines($order, $data['items']);

            $returnRequest = new ReturnRequest([
                'status' => 'requested',
                'reason' => $data['reason'],
                'note' => $data['note'] ?? null,
                'requested_at' => now(),
            ]);
            $returnRequest->order_id = $order->id;
            $returnRequest->user_id = $order->user_id;
            // The column is unique and the real reference needs an id that does not
            // exist yet, so the placeholder has to be unique too — a literal would
            // collide between two concurrent inserts.
            $returnRequest->reference = 'pending-'.Str::random(16);
            $returnRequest->save();

            // Derived from the autoincrement id rather than a COUNT(*), so it stays
            // unique under concurrency and after a return is deleted.
            $returnRequest->forceFill([
                'reference' => $this->formatReference($returnRequest->id),
            ])->save();

            $returnRequest->items()->createMany($lines);

            return $returnRequest;
        });

        $returnRequest->setRelation('order', $order)->load('items.orderItem');
        $this->sendAcknowledgement($returnRequest);

        return response()->json([
            'message' => 'Return request received.',
            'data' => new ReturnRequestResource($returnRequest),
        ], 201);
    }

    /** POST /returns/{reference}/cancel — the customer taking it back. */
    public function cancel(ReturnRequest $returnRequest): JsonResponse
    {
        $this->assertOwned($returnRequest);

        // Once the shop has acted on it, cancelling is a conversation, not a button.
        if ($returnRequest->status !== 'requested') {
            throw ValidationException::withMessages([
                'status' => "This return is already {$returnRequest->status}. Only a pending return can be cancelled.",
            ]);
        }

        $returnRequest->forceFill([
            'status' => 'cancelled',
            'resolved_at' => now(),
        ])->save();

        // Cancelling hands the quantity back, so those lines can be returned again.
        $returnRequest->load(['order', 'items.orderItem']);

        return response()->json([
            'message' => 'Return request cancelled.',
            'data' => new ReturnRequestResource($returnRequest),
        ]);
    }

    // ------------------------------------------------------------------

    /** Someone else's return is a 404, exactly like someone else's order. */
    private function assertOwned(ReturnRequest $returnRequest): void
    {
        abort_unless($returnRequest->user_id === auth()->id(), 404);
    }

    /**
     * The two order-level gates, in the order a customer would hit them.
     */
    private function assertReturnable(Order $order): void
    {
        $stages = config('reefer.stages');
        $delivered = array_key_last($stages);

        if (config('reefer.returns.require_delivered') && (int) $order->stage !== (int) $delivered) {
            throw ValidationException::withMessages([
                'order' => 'Only delivered orders can be returned. This one is still at "'.$order->stage_label.'".',
            ]);
        }

        $window = (int) config('reefer.returns.window_days');
        [$from, $anchor] = $this->windowAnchor($order);
        $closes = $anchor->addDays($window)->endOfDay();

        if (now()->greaterThan($closes)) {
            throw ValidationException::withMessages([
                'order' => "Returns close {$window} days after {$from}. The window on this order closed on ".$closes->format('M j, Y').'.',
            ]);
        }
    }

    /**
     * Where the return window starts counting, per reefer.returns.window_from.
     *
     *   purchase — placed_at. Simple and predictable: the clock starts when they buy.
     *   delivery — delivered_at, stamped when the order reached the final stage.
     *              Falls back to placed_at when a parcel has no arrival recorded; the
     *              fallback can only be EARLIER than the real delivery, so it shortens
     *              the window rather than extending it, which is the safe direction to
     *              be wrong in.
     *
     * @return array{0: string, 1: Carbon}
     */
    private function windowAnchor(Order $order): array
    {
        $placed = $order->placed_at ?? $order->created_at;

        if (config('reefer.returns.window_from') === 'delivery') {
            return ['delivery', ($order->delivered_at ?? $placed)->copy()->startOfDay()];
        }

        return ['purchase', $placed->copy()->startOfDay()];
    }

    /**
     * Turn "two of the black tee in M" into rows against real order lines.
     *
     * Every number here is read from the database inside the caller's transaction: the
     * rule is about what is STILL returnable, which is the quantity ordered minus
     * whatever a live return already holds. The request only says which line and how
     * many; it is never the source of either limit.
     *
     * @return array<int, array<string, int>>
     */
    private function resolveLines(Order $order, array $requested): array
    {
        // The same line named twice in one payload is one ask for the sum. Otherwise
        // 2 + 2 walks straight past a limit of 3.
        $wanted = [];
        foreach ($requested as $line) {
            $key = $line['slug'].'|'.$line['size'];
            $wanted[$key] = ($wanted[$key] ?? 0) + (int) $line['qty'];
        }

        // Locked, not just read: on MySQL this serialises two concurrent returns
        // against the same order, which is the only way the count below stays true
        // between reading it and writing the rows.
        $items = OrderItem::query()
            ->where('order_id', $order->id)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        $claimed = $this->claimedQuantities($order);

        $rows = [];

        foreach ($wanted as $key => $qty) {
            [$slug, $size] = explode('|', $key, 2);

            $matches = $items
                ->filter(fn (OrderItem $item) => $item->product_slug === $slug && $item->size === $size)
                ->values();

            if ($matches->isEmpty()) {
                throw ValidationException::withMessages([
                    'items' => "Order {$order->order_number} has no {$slug} in size {$size}.",
                ]);
            }

            $available = (int) $matches->sum(
                fn (OrderItem $item) => max(0, $item->qty - ($claimed[$item->id] ?? 0)),
            );

            if ($qty > $available) {
                $name = $matches->first()->name;

                throw ValidationException::withMessages([
                    'items' => $available === 0
                        ? "{$name} ({$size}) is already on an open return."
                        : "You can only return {$available} of {$name} ({$size}).",
                ]);
            }

            // Spread across the matching lines, for the odd order that bought the same
            // size on two separate lines. Usually there is exactly one match.
            $left = $qty;
            foreach ($matches as $item) {
                if ($left <= 0) {
                    break;
                }

                $take = min($left, max(0, $item->qty - ($claimed[$item->id] ?? 0)));

                if ($take <= 0) {
                    continue;
                }

                $rows[] = ['order_item_id' => $item->id, 'qty' => $take];
                $left -= $take;
            }
        }

        return $rows;
    }

    /**
     * How much of each order line a live return already holds, keyed by order_item id.
     * Cancelled and rejected returns are excluded — they gave their units back.
     *
     * @return array<int, int>
     */
    private function claimedQuantities(Order $order): array
    {
        return ReturnRequestItem::query()
            ->join('storefront_return_requests', 'storefront_return_requests.id', '=', 'storefront_return_request_items.return_request_id')
            ->where('storefront_return_requests.order_id', $order->id)
            ->whereNotIn('storefront_return_requests.status', ReturnRequest::DEAD_STATUSES)
            ->groupBy('storefront_return_request_items.order_item_id')
            ->selectRaw('storefront_return_request_items.order_item_id as order_item_id, sum(storefront_return_request_items.qty) as claimed')
            ->pluck('claimed', 'order_item_id')
            ->map(fn ($claimed) => (int) $claimed)
            ->all();
    }

    /**
     * The acknowledgement. Sent after the transaction has committed and swallowed if
     * it throws: the request is valid and recorded by now, and losing it over a mail
     * server would be absurd.
     */
    private function sendAcknowledgement(ReturnRequest $returnRequest): void
    {
        try {
            Mail::to($returnRequest->order->email)->send(new ReturnRequestedMail($returnRequest));
        } catch (Throwable $e) {
            report($e);
        }
    }

    private function formatReference(int $id): string
    {
        return 'RET-'.str_pad((string) $id, 6, '0', STR_PAD_LEFT);
    }
}
