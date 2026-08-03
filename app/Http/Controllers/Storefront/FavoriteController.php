<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Http\Resources\Storefront\ProductResource;
use App\Models\Storefront\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * The wishlist. Every route is behind auth: a favorite belongs to an account, so
 * there is no such thing as a signed-out favorite. Ownership is free — every query
 * is scoped through auth()->user()->favoriteProducts(), so one account can never
 * see or touch another's.
 */
class FavoriteController extends Controller
{
    /** GET /favorites — the account's wishlist, newest first. */
    public function index(): JsonResponse
    {
        return response()->json($this->payload());
    }

    /**
     * POST /favorites/toggle {slug} — the heart button. Adds it if absent, removes
     * it if present, and reports which way it went.
     */
    public function toggle(Request $request): JsonResponse
    {
        $product = $this->resolveActiveProduct($request);

        $result = auth()->user()->favoriteProducts()->toggle($product->id);
        $favorited = count($result['attached']) > 0;

        return response()->json($this->payload() + ['favorited' => $favorited]);
    }

    /** DELETE /favorites/{product} — explicit remove, e.g. from the wishlist tab. */
    public function destroy(Product $product): JsonResponse
    {
        auth()->user()->favoriteProducts()->detach($product->id);

        return response()->json($this->payload() + ['favorited' => false]);
    }

    // ------------------------------------------------------------------

    /**
     * One shape for every response so the client can adopt whatever comes back.
     * Ships the full product list for the wishlist tab AND the bare slug set, which
     * is all a heart button needs to know its state.
     */
    private function payload(): array
    {
        $products = auth()->user()->favoriteProducts()
            // A product pulled from the catalog should drop out of the wishlist
            // rather than show a dead card.
            ->where('is_active', true)
            ->with('variants')
            ->withCount('reviews')
            ->withAvg('reviews', 'rating')
            // Newest first. created_at alone ties when several are favorited in the
            // same second, and MySQL vs MariaDB break that tie differently — so the
            // favorite's own id (monotonic = insertion order) is the deterministic
            // tie-breaker that makes the order stable across engines.
            ->orderByPivot('created_at', 'desc')
            ->orderByPivot('id', 'desc')
            ->get();

        return [
            'data' => ProductResource::collection($products),
            // The frontend caches this to light up hearts across the catalog without
            // asking per product.
            'slugs' => $products->pluck('slug')->values(),
            'count' => $products->count(),
        ];
    }

    private function resolveActiveProduct(Request $request): Product
    {
        $data = $request->validate([
            'slug' => ['required', 'string', 'exists:storefront_products,slug'],
        ]);

        $product = Product::where('slug', $data['slug'])->where('is_active', true)->first();

        // exists:storefront_products,slug passed, so a miss here means the product is
        // deactivated — hidden from the catalog but still guessable.
        if (! $product) {
            throw ValidationException::withMessages([
                'slug' => 'This product is no longer available.',
            ]);
        }

        return $product;
    }
}
