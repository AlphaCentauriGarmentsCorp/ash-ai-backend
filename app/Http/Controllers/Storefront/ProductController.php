<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Http\Resources\Storefront\ProductResource;
use App\Models\Storefront\Product;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class ProductController extends Controller
{
    /** Columns a free-text query is matched against, in no particular order. */
    // type and tag are in here because the client-side filter this replaced matched
    // them, and the search box still invites the words they hold ("TEES, HOODIES,
    // BAGS…"). Dropping them would have made a shopper typing the placeholder's own
    // suggestion get fewer results than the build before it.
    private const SEARCHABLE = ['name', 'blurb', 'slug', 'type', 'tag'];

    /**
     * GET /api/storefront/v1/products
     * Filters mirror the All Products page: audience, type, size, tag, sort, search.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        // Every filter is a scalar. Without this, ?tag[]=NEW reaches the query
        // builder as an array binding and 500s, and ?audience[]=men casts to
        // the string "Array".
        $filters = $request->validate([
            'audience' => ['nullable', 'string', 'max:120'],
            'type' => ['nullable', 'string', 'max:120'],
            'tag' => ['nullable', 'string', 'max:40'],
            'size' => ['nullable', 'string', 'max:60'],
            'search' => ['nullable', 'string', 'max:80'],
            'sort' => ['nullable', Rule::in(['price_asc', 'price_desc', 'newest', 'featured'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:60'],
        ]);

        // withCount/withAvg roll the ratings up in the same query rather than one per
        // card. ProductResource gates the rating fields on these being present.
        $query = Product::query()
            ->with('variants')
            ->withCount('reviews')
            ->withAvg('reviews', 'rating')
            ->where('is_active', true);

        // audience=men,women  (also treat 'unisex' as always-included when a gender is picked)
        if (! empty($filters['audience'])) {
            $aud = array_filter(explode(',', $filters['audience']));
            $query->whereIn('audience', array_merge($aud, ['unisex']));
        }

        // type=tee,hoodie
        if (! empty($filters['type'])) {
            $query->whereIn('type', array_filter(explode(',', $filters['type'])));
        }

        // tag=NEW
        if (! empty($filters['tag'])) {
            $query->where('tag', $filters['tag']);
        }

        // size=M,L  -> products that offer at least one of these sizes
        if (! empty($filters['size'])) {
            $sizes = array_filter(explode(',', $filters['size']));
            $query->whereHas('variants', fn ($q) => $q->whereIn('size', $sizes));
        }

        // search=wave tee -> narrows whatever the filters above already selected.
        // Terms AND together (all of them must appear somewhere) while the columns
        // OR within a term, so "wave tee" finds "OG Wave Tee" and not every tee.
        foreach (preg_split('/\s+/', trim($filters['search'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) as $term) {
            $pattern = '%'.$this->escapeLike(mb_strtolower($term)).'%';

            $query->where(function ($q) use ($pattern) {
                foreach (self::SEARCHABLE as $column) {
                    // LOWER() on both sides rather than trusting the column collation
                    // to be case-insensitive. LIKE can use no index here anyway.
                    $q->orWhereRaw("LOWER(storefront_products.{$column}) LIKE ? ESCAPE '!'", [$pattern]);
                }
            });
        }

        // sort=price_asc | price_desc | newest | featured (default)
        match ($filters['sort'] ?? 'featured') {
            'price_asc' => $query->orderBy('price'),
            'price_desc' => $query->orderByDesc('price'),
            'newest' => $query->orderByDesc('created_at')->orderByDesc('id'),
            default => $query->orderBy('sort')->orderBy('id'),
        };

        return ProductResource::collection(
            $query->paginate($filters['per_page'] ?? 24)->withQueryString()
        );
    }

    /**
     * Neutralise the LIKE wildcards a shopper can type, so a query of "100%" is a
     * search for "100%" and not a match-everything pattern. '!' is the escape
     * character instead of SQL's default backslash because the meaning of a
     * backslash inside a string literal flips with NO_BACKSLASH_ESCAPES.
     */
    private function escapeLike(string $value): string
    {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value);
    }

    /**
     * GET /api/storefront/v1/products/{product}   (bound by slug)
     */
    public function show(Product $product): ProductResource
    {
        abort_unless($product->is_active, 404);

        $product->load('variants')->loadCount('reviews')->loadAvg('reviews', 'rating');

        return new ProductResource($product);
    }
}
