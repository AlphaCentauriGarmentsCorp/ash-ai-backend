<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Http\Requests\Storefront\MergeCartRequest;
use App\Http\Requests\Storefront\StoreCartItemRequest;
use App\Http\Resources\Storefront\CartResource;
use App\Models\Storefront\Cart;
use App\Models\Storefront\CartItem;
use App\Models\Storefront\Product;
use App\Models\Storefront\ProductVariant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CartController extends Controller
{
    /** GET /cart */
    public function show(): JsonResponse
    {
        return $this->respond($this->cart());
    }

    /**
     * POST /cart/items — add a variant, or bump it if it is already in the cart.
     */
    public function store(StoreCartItemRequest $request): JsonResponse
    {
        $data = $request->validated();
        $qty = $data['qty'] ?? 1;

        $cart = DB::transaction(function () use ($data, $qty) {
            $variant = $this->resolveVariant($data['slug'], $data['size']);
            $cart = $this->cart();

            $item = $cart->items()->firstOrNew(['product_variant_id' => $variant->id]);
            $newQty = ($item->qty ?? 0) + $qty;

            // Adding 2 to an existing 3 must be checked as 5, not as 2.
            $this->assertStock($variant, $newQty, 'qty');

            $item->qty = min($newQty, 99);
            $item->save();

            return $cart;
        });

        return $this->respond($cart, 201);
    }

    /**
     * PATCH /cart/items/{item} — change a line's quantity, its checkout selection,
     * or both. qty 0 removes the line, which is what a "−" on the last unit means.
     */
    public function update(Request $request, string $item): JsonResponse
    {
        $data = $request->validate([
            'qty' => ['sometimes', 'integer', 'min:0', 'max:99'],
            'selected' => ['sometimes', 'boolean'],
        ]);

        if (! array_key_exists('qty', $data) && ! array_key_exists('selected', $data)) {
            throw ValidationException::withMessages([
                'qty' => 'Send a qty, a selected flag, or both.',
            ]);
        }

        $cart = $this->cart();
        $cartItem = $this->findOwnedItem($cart, $item);

        if (($data['qty'] ?? null) === 0) {
            $cartItem->delete();

            return $this->respond($cart->fresh());
        }

        $variant = $cartItem->variant;

        // The variant vanished from the catalog while the cart sat there; the line
        // is meaningless now.
        if (! $variant) {
            $cartItem->delete();

            return $this->respond($cart->fresh());
        }

        if (isset($data['qty'])) {
            $this->assertStock($variant, $data['qty'], 'qty');
            $cartItem->qty = $data['qty'];
        }

        if (array_key_exists('selected', $data)) {
            $cartItem->selected = $data['selected'];
        }

        $cartItem->save();

        return $this->respond($cart->fresh());
    }

    /**
     * POST /cart/select — tick or untick every line at once.
     *
     * A whole-cart operation rather than a loop of per-item PATCHes: one request,
     * and no half-applied state if the connection drops midway.
     */
    public function select(Request $request): JsonResponse
    {
        $data = $request->validate([
            'selected' => ['required', 'boolean'],
        ]);

        $cart = $this->cart();
        $cart->items()->update(['selected' => $data['selected']]);

        return $this->respond($cart->fresh());
    }

    /** DELETE /cart/items/{item} */
    public function destroy(string $item): JsonResponse
    {
        $cart = $this->cart();
        $this->findOwnedItem($cart, $item)->delete();

        return $this->respond($cart->fresh());
    }

    /** DELETE /cart — empty it. */
    public function clear(): JsonResponse
    {
        $cart = $this->cart();
        $cart->items()->delete();

        return $this->respond($cart->fresh());
    }

    /**
     * POST /cart/merge — fold a browser's leftover localStorage cart into the
     * account's cart on sign-in. Quantities are combined rather than overwritten,
     * so neither side's items are lost. Safe to call more than once only in the
     * sense that it is additive — the client sends it once, then clears its local
     * copy.
     */
    public function merge(MergeCartRequest $request): JsonResponse
    {
        $items = $request->validated()['items'];

        $cart = DB::transaction(function () use ($items) {
            $cart = $this->cart();

            foreach ($items as $i => $line) {
                $variant = $this->resolveVariant($line['slug'], $line['size'], "items.$i");
                $item = $cart->items()->firstOrNew(['product_variant_id' => $variant->id]);

                // Clamp rather than reject: a merge is a convenience, and failing
                // the whole sign-in flow because one line went out of stock while
                // the browser sat idle would be worse than quietly capping it.
                $item->qty = min(($item->qty ?? 0) + $line['qty'], max($variant->available, 0), 99);

                if ($item->qty > 0) {
                    $item->save();
                }
            }

            return $cart;
        });

        return $this->respond($cart);
    }

    // ------------------------------------------------------------------

    private function cart(): Cart
    {
        return auth()->user()->currentCart();
    }

    private function respond(Cart $cart, int $status = 200): JsonResponse
    {
        return response()->json([
            'data' => new CartResource($cart->loadLines()),
        ], $status);
    }

    /**
     * Deliberately NOT route-model binding. Two reasons:
     *
     * 1. Ownership. Looking the id up *within this user's cart* makes reaching
     *    someone else's line impossible by construction, rather than relying on a
     *    guard that a future method might forget to call.
     * 2. Binding resolves before this route's auth middleware, so an unauthenticated
     *    request to a real id returned 401 while a made-up id returned 404 — enough
     *    to enumerate which cart item ids exist.
     *
     * A wrong id and a stranger's id are now indistinguishable: both 404.
     */
    private function findOwnedItem(Cart $cart, string $id): CartItem
    {
        $item = $cart->items()->whereKey($id)->first();

        abort_unless($item !== null, 404);

        return $item;
    }

    private function resolveVariant(string $slug, string $size, string $field = ''): ProductVariant
    {
        $product = Product::where('slug', $slug)->where('is_active', true)->first();

        // exists:storefront_products,slug passed, so reaching here means the product
        // is deactivated — hidden from the catalog but still guessable.
        if (! $product) {
            throw ValidationException::withMessages([
                ($field ? $field.'.slug' : 'slug') => 'This product is no longer available.',
            ]);
        }

        $variant = $product->variants()->where('size', $size)->first();

        if (! $variant) {
            throw ValidationException::withMessages([
                ($field ? $field.'.size' : 'size') => "Size {$size} is not available for {$product->name}.",
            ]);
        }

        return $variant;
    }

    private function assertStock(ProductVariant $variant, int $qty, string $field): void
    {
        // Nothing is reserved here — stock is only held at checkout. This just
        // stops the obvious case of putting 50 in a cart with 3 left.
        if ($qty > $variant->available) {
            throw ValidationException::withMessages([
                $field => $variant->available > 0
                    ? "Only {$variant->available} left in that size."
                    : 'That size is sold out.',
            ]);
        }
    }
}
