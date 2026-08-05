<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Http\Requests\Storefront\StoreProductReviewRequest;
use App\Http\Resources\Storefront\ProductReviewResource;
use App\Models\Storefront\Product;
use Illuminate\Http\JsonResponse;

/**
 * Product ratings.
 *
 *   read  — public. Anyone sees them, signed in or not.
 *   write — signed in AND has bought this product. Both checked here, on the server;
 *           the page hiding the form is a courtesy, not the rule.
 */
class ProductReviewController extends Controller
{
    /**
     * GET /products/{product}/reviews — public.
     *
     * Behind ResolveApiUser, not AuthenticateApiToken: a signed-out visitor must get
     * the reviews, and a signed-in one additionally gets told whether they may write.
     */
    public function index(Product $product): JsonResponse
    {
        abort_unless($product->is_active, 404);

        return response()->json($this->payload($product));
    }

    /**
     * POST /products/{product}/reviews — signed in + purchased.
     *
     * Doubles as an update: rating again replaces your existing review rather than
     * adding a second one, which the unique index would refuse anyway.
     */
    public function store(StoreProductReviewRequest $request, Product $product): JsonResponse
    {
        abort_unless($product->is_active, 404);

        $user = $request->user();

        // The 403 that enforces the actual rule (not a 404: the product is real and
        // public, this account simply has not bought it). Computed once and handed to
        // payload() so the purchase isn't re-queried two more times per request.
        $purchased = $product->wasPurchasedBy($user);
        abort_unless($purchased, 403, 'You can only rate products you have bought.');

        $review = $product->reviews()->where('user_id', $user->id)->first();
        $isNew = $review === null;

        if ($isNew) {
            // Not updateOrCreate(): its keys go through fill(), and product_id /
            // user_id are deliberately not fillable, so they were being stripped and
            // the insert arrived without a product. Set them explicitly instead —
            // client input still only ever reaches rating and body.
            $review = $product->reviews()->make();
            $review->user_id = $user->id;
        }

        $review->fill($request->validated())->save();

        return response()->json(
            $this->payload($product, $purchased) + ['message' => $isNew ? 'Thanks for the rating.' : 'Your rating was updated.'],
            $isNew ? 201 : 200,
        );
    }

    /** DELETE /products/{product}/reviews/mine — remove your own rating. */
    public function destroy(Product $product): JsonResponse
    {
        $product->reviews()->where('user_id', auth()->id())->delete();

        return response()->json($this->payload($product));
    }

    // ------------------------------------------------------------------

    /**
     * One shape for every response, so the page can just adopt whatever comes back
     * instead of patching its own state after a write.
     *
     * $purchased is threaded in from store() (which already had to compute it) so the
     * purchase EXISTS query runs at most once per request instead of two or three
     * times. Null means "work it out" — the read paths don't know it yet.
     */
    private function payload(Product $product, ?bool $purchased = null): array
    {
        $user = auth()->user();

        // Only the latest 20 are serialised for the visible list — the UI has no
        // "load more", so shipping the entire history on every public page view was
        // pure waste. The summary below still reflects ALL reviews.
        // Newest first, with id as a deterministic tie-breaker: two reviews saved in
        // the same second tie on created_at, and MySQL vs MariaDB order that tie
        // differently — id desc (monotonic) keeps "latest" stable across engines.
        $reviews = $product->reviews()->with('user')->latest()->latest('id')->limit(20)->get();

        // Count + average come straight from the DB over the whole set, not from the
        // capped collection, so a product with 100 ratings still reports 100.
        $product->loadCount('reviews')->loadAvg('reviews', 'rating');
        $count = (int) $product->reviews_count;
        $average = $count ? round((float) $product->reviews_avg_rating, 1) : null;

        // One grouped query for the 1–5 distribution bars, then padded so every key
        // is present and the client never has to defend against a missing one.
        $counts = $product->reviews()
            ->selectRaw('rating, count(*) as c')
            ->groupBy('rating')
            ->pluck('c', 'rating');
        $breakdown = collect(range(5, 1))
            ->mapWithKeys(fn (int $star) => [$star => (int) ($counts[$star] ?? 0)])
            ->all();

        // The viewer's own review may be older than the latest 20, so fetch it
        // directly rather than hunting for it in the capped list.
        $mine = $user
            ? $product->reviews()->with('user')->where('user_id', $user->id)->first()
            : null;

        $purchased ??= $product->wasPurchasedBy($user);

        return [
            'summary' => [
                'count' => $count,
                'average' => $average,
                'breakdown' => $breakdown,
            ],

            'reviews' => ProductReviewResource::collection($reviews),

            // The two conditions, answered by the server. The page shows its form
            // only when can_review is true — but the POST re-checks regardless.
            'viewer' => [
                'signed_in' => $user !== null,
                'purchased' => $purchased,
                'can_review' => $user !== null && $purchased,
                'my_review' => $mine ? new ProductReviewResource($mine) : null,
            ],
        ];
    }
}
