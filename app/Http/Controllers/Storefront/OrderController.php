<?php

namespace App\Http\Controllers\Storefront;

use App\Contracts\Storefront\PaymentGateway;
use App\Http\Controllers\Controller;
use App\Http\Requests\Storefront\StoreOrderRequest;
use App\Http\Resources\Storefront\OrderResource;
use App\Mail\Storefront\OrderPlacedMail;
use App\Models\Storefront\Order;
use App\Models\Storefront\Product;
use App\Models\Storefront\ProductVariant;
use App\Services\Storefront\DiscountService;
use App\Services\Storefront\PricingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class OrderController extends Controller
{
    public function __construct(
        private readonly PricingService $pricingService,
        private readonly PaymentGateway $paymentGateway,
        private readonly DiscountService $discounts,
    ) {
    }

    public function index(): AnonymousResourceCollection
    {
        $orders = Order::query()
            ->where('user_id', auth()->id())
            ->latest('placed_at')
            ->latest('id')
            ->with('items')
            ->paginate(15);

        return OrderResource::collection($orders);
    }

    /**
     * POST /orders/{order}/advance {stage} — walk a simulated order down the tracker.
     *
     * ONE-WAY, enforced here rather than in the UI: a stage may only ever move
     * forward. Letting an order go backwards would let someone re-open a returns
     * window they had already used, or un-deliver a parcel they had received.
     *
     * ⚠ DEMO ONLY, gated on reefer.orders.allow_manual_advance. In a real shop the
     * stage belongs to the warehouse, not the buyer — see the note on that config key.
     */
    public function advance(Request $request, string $order): JsonResponse
    {
        if (! config('reefer.orders.allow_manual_advance')) {
            return response()->json([
                'message' => 'Order status is managed by fulfilment, not from here.',
            ], 403);
        }

        $stages = (array) config('reefer.stages');
        $last = count($stages) - 1;

        $data = $request->validate([
            'stage' => ['required', 'integer', 'min:0', 'max:'.$last],
        ]);

        // Scoped to the caller: someone else's order number is a 404, never a 403, so
        // an order number stays unconfirmable.
        $model = Order::where('order_number', $order)
            ->where('user_id', auth()->id())
            ->first();

        if (! $model) {
            return response()->json(['message' => "No order {$order}."], 404);
        }

        $target = (int) $data['stage'];
        $current = (int) $model->stage;

        if ($target <= $current) {
            return response()->json([
                'message' => $target === $current
                    ? 'That order is already at "'.$stages[$target].'".'
                    : 'An order cannot go back a stage.',
            ], 422);
        }

        $attributes = [
            'stage' => $target,
            'status' => $stages[$target],
        ];

        // Shipping is where a courier and a tracking number would come from a real
        // carrier integration. Filled once, then left alone on later stages.
        if ($target >= 2 && ! $model->tracking_number) {
            $attributes['courier'] = config('reefer.orders.demo_courier');
            $attributes['tracking_number'] = 'RFR'.strtoupper(Str::random(9));
            $attributes['eta'] = now()->addDays(2);
        }

        // The stamp the returns window reads. Set once — a second pass must not move
        // the arrival date and quietly extend the window.
        if ($target === $last && ! $model->delivered_at) {
            $attributes['delivered_at'] = now();
        }

        $model->forceFill($attributes)->save();

        return response()->json([
            'message' => 'Order is now "'.$stages[$target].'".',
            'data' => new OrderResource($model->fresh('items')),
        ]);
    }

    public function store(StoreOrderRequest $request): JsonResponse
    {
        $data = $request->validated();

        $order = DB::transaction(function () use ($data) {
            [$subtotal, $itemsPayload] = $this->priceCart($data['items']);

            // Re-resolved from the database and locked here, before any money moves:
            // the shopper agreed to a discounted total, so the code has to still be
            // good for it. An unusable one throws a 422 naming the reason rather
            // than being silently dropped into a bigger charge. The lock is held to
            // commit — worth revisiting once a real gateway's round trip sits inside
            // this transaction.
            $discount = $this->discounts->lockForCheckout(
                $data['discount_code'] ?? null,
                auth()->user(),
                $subtotal,
            );

            $quote = $this->pricingService->quote(
                $subtotal,
                $data['shipping_method'],
                $discount?->discountFor($subtotal) ?? 0,
            );

            // The gateway decides this, not the client. A declined charge aborts the
            // transaction, so no order and no stock movement survive it.
            $payment = $this->paymentGateway->charge(
                $data['payment_method'],
                $quote['total'],
                $data['simulate'] ?? null,
            );

            if ($payment->declined()) {
                return null;
            }

            $this->reserveStock($itemsPayload);

            // Kept before variant_id is stripped — these are the exact lines to take
            // out of the cart once the order exists.
            $orderedVariantIds = array_column($itemsPayload, 'variant_id');

            // variant_id only existed to locate the stock row; it is not a column
            // on storefront_order_items.
            $itemsPayload = array_map(
                fn (array $line) => collect($line)->except('variant_id')->all(),
                $itemsPayload,
            );

            $order = Order::create([
                'order_number' => 'pending',
                'user_id' => auth()->id(),
                'email' => $data['email'],
                'ship_to_name' => $data['ship_to_name'],
                'phone' => $data['phone'],
                'street' => $data['street'],
                'barangay' => $data['barangay'] ?? null,
                'city' => $data['city'],
                'province' => $data['province'],
                'region' => $data['region'] ?? null,
                'postal' => $data['postal'] ?? null,
                'shipping_method' => $data['shipping_method'],
                'subtotal' => $subtotal,
                // The canonical code, not the casing they typed.
                'discount_code' => $discount?->code,
                'discount_amount' => $quote['discount'],
                'shipping_fee' => $quote['shipping_fee'],
                'total' => $quote['total'],
                'payment_method' => $data['payment_method'],
                'payment_status' => $payment->status,
                'payment_ref' => $payment->reference,
                'status' => 'Processing',
                'stage' => 0,
                'placed_at' => now(),
            ]);

            // Derived from the autoincrement id rather than a COUNT(*), so it stays
            // unique under concurrency and after an order is deleted.
            $order->forceFill([
                'order_number' => $this->formatOrderNumber($order->id),
            ])->save();

            $order->items()->createMany($itemsPayload);

            // Spent only now that the order exists. The decline path above returns
            // instead of throwing, so the transaction commits — an increment taken
            // any earlier would burn a use on an order nobody placed.
            $this->discounts->markRedeemed($discount);

            // Take ONLY what was ordered out of the cart. The rest of the cart is a
            // save-for-later list: buying two things must not silently bin the other
            // four the shopper left unticked. Inside the transaction, so a failure
            // cannot leave them with an order AND the lines that produced it.
            auth()->user()?->cart?->items()
                ->whereIn('product_variant_id', $orderedVariantIds)
                ->delete();

            return $order;
        });

        if (! $order) {
            return response()->json([
                'message' => 'Payment was declined. No charge was made and no order was placed.',
            ], 402);
        }

        $order->load('items');
        $this->sendConfirmation($order);

        return response()->json([
            'message' => 'Order created.',
            'data' => new OrderResource($order),
        ], 201);
    }

    /**
     * The receipt. Sent after the transaction has committed, never inside it: the
     * order is paid and real by now, and rolling it back over a mail server would
     * be absurd. Best-effort for the same reason — a send that throws is logged and
     * swallowed rather than turning a placed order into a 500.
     */
    private function sendConfirmation(Order $order): void
    {
        try {
            Mail::to($order->email)->send(new OrderPlacedMail($order));
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * Re-price every line from the catalog. The client sends slug/size/qty and
     * nothing else that touches money.
     *
     * @return array{0: int, 1: array<int, array<string, mixed>>}
     */
    private function priceCart(array $lines): array
    {
        $slugs = array_column($lines, 'slug');

        // One query for the whole cart instead of two per line.
        $products = Product::query()
            ->whereIn('slug', $slugs)
            ->where('is_active', true)
            ->with('variants')
            ->get()
            ->keyBy('slug');

        $subtotal = 0;
        $itemsPayload = [];

        foreach ($lines as $i => $line) {
            $product = $products->get($line['slug']);

            // exists:storefront_products,slug passed validation, so reaching here means
            // the product is deactivated — hidden from the catalog but still guessable.
            if (! $product) {
                throw ValidationException::withMessages([
                    "items.$i.slug" => 'This product is no longer available.',
                ]);
            }

            $variant = $product->variants->firstWhere('size', $line['size']);

            if (! $variant) {
                throw ValidationException::withMessages([
                    "items.$i.size" => "Size {$line['size']} is not available for {$product->name}.",
                ]);
            }

            $lineTotal = $product->price * $line['qty'];
            $subtotal += $lineTotal;

            $itemsPayload[] = [
                'variant_id' => $variant->id,
                'product_id' => $product->id,
                'product_slug' => $product->slug,
                'name' => $product->name,
                'size' => $variant->size,
                'unit_price' => $product->price,
                'qty' => $line['qty'],
                'line_total' => $lineTotal,
            ];
        }

        return [$subtotal, $itemsPayload];
    }

    /**
     * Reserve stock with the quantity check inside the UPDATE itself, so two
     * concurrent checkouts cannot both pass a read-then-write gap and oversell.
     * A zero-row result means someone else took the last one first.
     */
    private function reserveStock(array $itemsPayload): void
    {
        foreach ($itemsPayload as $i => $line) {
            // Raise allocated rather than cut on_hand. on_hand is what is physically
            // in the warehouse and belongs to the ERP; an order does not move a
            // garment, it spoken-for one. The ERP clears the allocation when the
            // parcel actually ships, and until then this is the number its "Order
            // Allocated" column reads.
            //
            // The WHERE is the reservation: it only claims when enough is still
            // unallocated, so two shoppers racing for the last unit cannot both win.
            //
            // Written as `on_hand >= allocated + ?`, NOT `on_hand - allocated >= ?`.
            // Arithmetically identical for non-negative inputs, but both columns are
            // INT UNSIGNED and MySQL/MariaDB evaluates unsigned-minus-unsigned as
            // unsigned: without NO_UNSIGNED_SUBTRACTION in sql_mode (this server does
            // not set it) the subtraction form raises error 1690 / SQLSTATE 22003 the
            // moment allocated exceeds on_hand, turning the intended 422 into a 500.
            // Over-allocation is a real state, not a hypothetical — the ERP can cut
            // on_hand after orders were already taken against it, which is exactly why
            // ProductVariant::getAvailableAttribute() floors at zero. Keeping the
            // subtraction on the right-hand side never produces a negative intermediate,
            // and matches the form scopeSellable() already uses. (SQLite has no unsigned
            // type, so the test suite could never have caught this.)
            $claimed = ProductVariant::query()
                ->where('id', $line['variant_id'])
                ->where('is_active', true)
                ->whereRaw('on_hand >= allocated + ?', [$line['qty']])
                ->increment('allocated', $line['qty']);

            if ($claimed === 0) {
                throw ValidationException::withMessages([
                    "items.$i.qty" => "Not enough stock left for {$line['name']} ({$line['size']}).",
                ]);
            }
        }
    }

    private function formatOrderNumber(int $id): string
    {
        $seq = ((int) config('reefer.order_seq_start')) + $id - 1;

        return config('reefer.order_prefix').str_pad((string) $seq, 7, '0', STR_PAD_LEFT);
    }
}
