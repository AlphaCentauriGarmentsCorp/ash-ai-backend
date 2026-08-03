<?php

namespace App\Services\Storefront;

use App\Models\Storefront\Product;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Throwable;

/**
 * INERT IN THIS DEPLOYMENT — kept for reference, nothing calls it.
 *
 * In the standalone storefront, Laravel served the React shell itself: a catch-all
 * web route read index.html off disk and inject() rewrote its <head> before sending
 * it. Here the SPA is built and deployed separately (its own origin), so Laravel
 * never hands out the HTML document and has nothing to inject into — this backend
 * only answers /api/storefront/*. Ported so the capability is not lost and so
 * SitemapController still compiles; wire it back up only if the SPA is ever moved
 * back behind Laravel, which also means restoring the web routes that were dropped.
 *
 * Why it exists at all: link previews are built by crawlers (Facebook, X, Discord,
 * Slack, iMessage) that fetch the HTML and never run the bundle, so anything React
 * sets on document.title or injects at runtime does not exist as far as they are
 * concerned. The tags have to be in the document the server hands over — which is
 * why this was a backend job. With a separately-hosted SPA that responsibility
 * moves to whatever serves the frontend (prerender/SSR at that edge).
 */
class SpaMetaService
{
    private const SITE = 'REEFER MNL';

    /** Short on purpose: long enough to blunt a crawler storm, short enough that a price edit shows up fast. */
    private const CACHE_TTL_MINUTES = 5;

    /** Real pages that still have no business in a search index. */
    private const NOINDEX = [
        '/cart', '/checkout', '/account', '/sign-in', '/forgot-password', '/reset-password',
    ];

    /** Resolved meta per path, so two calls in one request cost one lookup. */
    private array $memo = [];

    /**
     * @return array{title: string, description: string, canonical: string, image: string,
     *               type: string, price: int|null, noindex: bool}
     */
    public function metaFor(string $path): array
    {
        $path = $this->normalize($path);

        return $this->memo[$path] ??= $this->resolve($path);
    }

    /** The tag block, ready to drop into <head>. */
    public function tagsFor(string $path): string
    {
        $meta = $this->metaFor($path);

        $tags = [
            '<title>'.e($meta['title']).'</title>',
            $this->tag('name', 'description', $meta['description']),
            '<link rel="canonical" href="'.e($meta['canonical']).'">',
            $this->tag('property', 'og:type', $meta['type']),
            $this->tag('property', 'og:site_name', self::SITE),
            $this->tag('property', 'og:title', $meta['title']),
            $this->tag('property', 'og:description', $meta['description']),
            $this->tag('property', 'og:url', $meta['canonical']),
            $this->tag('property', 'og:image', $meta['image']),
            $this->tag('property', 'og:image:alt', $meta['title']),
            $this->tag('name', 'twitter:card', 'summary_large_image'),
            $this->tag('name', 'twitter:title', $meta['title']),
            $this->tag('name', 'twitter:description', $meta['description']),
            $this->tag('name', 'twitter:image', $meta['image']),
        ];

        if ($meta['price'] !== null) {
            $tags[] = $this->tag('property', 'product:price:amount', number_format($meta['price'], 2, '.', ''));
            $tags[] = $this->tag('property', 'product:price:currency', (string) config('reefer.currency'));
        }

        // follow, not none: a crawler should still walk out of the cart into the catalog.
        if ($meta['noindex']) {
            $tags[] = $this->tag('name', 'robots', 'noindex, follow');
        }

        return implode("\n    ", $tags);
    }

    /** Swap the shell's placeholder <head> content for this path's real tags. */
    public function inject(string $html, string $path): string
    {
        $tags = $this->tagsFor($path);

        // The shell ships with its own <title>, so replace it instead of adding a
        // second one. preg_replace_callback rather than preg_replace: a product name
        // containing "$1" or a backslash would otherwise be read as a backreference.
        $out = preg_replace_callback('#<title\b[^>]*>.*?</title>#is', fn () => $tags, $html, 1, $count);

        if ($count === 0) {
            $out = preg_replace_callback('#</head>#i', fn () => $tags."\n  </head>", $html, 1, $count);
        }

        return $out !== null && $count > 0 ? $out : $html;
    }

    private function resolve(string $path): array
    {
        $pages = $this->pages();
        $matched = true;
        $meta = null;

        if (str_starts_with($path, '/product/')) {
            $meta = $this->productMeta(substr($path, strlen('/product/')));
        }

        $meta ??= $pages[$path] ?? null;

        // A dead slug or an unknown deep link still gets a preview — the brand one —
        // but is kept out of the index rather than published as a duplicate homepage.
        if ($meta === null) {
            $meta = $pages['/'];
            $matched = false;
        }

        return [
            'title' => $meta['title'],
            'description' => $meta['description'],
            // url('/') stops at the host; the homepage canonical keeps its slash so it
            // cannot be read as a different URL from the one people actually link to.
            'canonical' => $meta['canonical'] ?? ($path === '/' ? url('/').'/' : url($path)),
            'image' => $meta['image'] ?? $this->defaultImage(),
            'type' => $meta['type'] ?? 'website',
            'price' => $meta['price'] ?? null,
            'noindex' => ! $matched || in_array($path, self::NOINDEX, true),
        ];
    }

    /**
     * Per-page copy. A method rather than a const so the numbers stay in one place:
     * the free-shipping threshold and the returns window are read from config.
     */
    private function pages(): array
    {
        $freeShip = '₱'.number_format((int) config('reefer.free_ship_threshold'));
        $returnDays = (int) config('reefer.returns.window_days');

        return [
            '/' => [
                'title' => 'REEFER MNL — Ride the wave',
                'description' => "Small-batch streetwear out of Quezon City. Heavyweight tees, hoodies and caps printed in short runs — no restocks, no committees. Free shipping over {$freeShip}.",
            ],
            '/shop' => [
                'title' => 'Shop the drop — REEFER MNL',
                'description' => "The current REEFER MNL drop: tees, hoodies, shorts and caps in limited runs. Once a size sells out it does not come back. Free shipping over {$freeShip}.",
            ],
            '/products' => [
                'title' => 'All products — REEFER MNL',
                'description' => 'Every REEFER MNL piece in one place. Filter by fit, audience, type and price, and see what is still in stock before you cop.',
            ],
            '/lookbook' => [
                'title' => 'Lookbook — REEFER MNL',
                'description' => 'Tidal SZN 03, shot on the streets of Metro Manila. See how the drop actually wears before you pick a size.',
            ],
            '/about' => [
                'title' => 'About — REEFER MNL',
                'description' => 'Reefer was never a plan, it was a dare. A Quezon City studio printing small-batch streetwear to order, shipping nationwide, restocking nothing.',
            ],
            '/faq' => [
                'title' => 'FAQ — REEFER MNL',
                'description' => "Shipping times, {$returnDays}-day returns, payment options (GCash, Maya, card, COD) and sizing — answered before you have to ask.",
            ],
            '/sizing-guide' => [
                'title' => 'Sizing guide — REEFER MNL',
                'description' => 'Flat measurements for every REEFER MNL fit, S through 2XL, plus how each cut actually falls. Still unsure? DM @reefer.mnl with your height and weight.',
            ],
            '/cart' => [
                'title' => 'Your cart — REEFER MNL',
                'description' => 'Check your bag, apply a code and head to checkout. Small batch, no restocks — do not sit on it too long.',
            ],
            '/sign-in' => [
                'title' => 'Sign in — REEFER MNL',
                'description' => 'Sign in to track orders, save favourites and check out faster. New here? Making an account takes a minute.',
            ],
        ];
    }

    /** The case that matters: a shared product link has to preview THAT product. */
    private function productMeta(string $slug): ?array
    {
        $product = $this->lookupProduct($slug);

        if (! $product) {
            return null;
        }

        $price = '₱'.number_format($product['price']);
        $blurb = trim($product['blurb']);

        return [
            'title' => $product['name'].' — '.$price.' | '.self::SITE,
            'description' => $blurb !== ''
                ? Str::limit($blurb, 150).' '.$price.' at REEFER MNL — small batch, no restocks.'
                : $product['name'].' — '.$price.' at REEFER MNL. Small batch, no restocks.',
            'canonical' => url('/product/'.$product['slug']),
            'image' => $product['image_path']
                ? asset('storage/'.$product['image_path'])
                : $this->defaultImage(),
            'type' => 'product',
            'price' => $product['price'],
        ];
    }

    /**
     * @return array{slug: string, name: string, blurb: string, price: int, image_path: string|null}|null
     */
    private function lookupProduct(string $slug): ?array
    {
        // The key is built from user input, so anything that is not a plausible slug is
        // turned away before it can mint a cache entry (or a query) of its own — a
        // thousand junk URLs must not become a thousand cache rows.
        if (! preg_match('/^[a-z0-9]([a-z0-9-]{0,118}[a-z0-9])?$/', $slug)) {
            return null;
        }

        try {
            $product = Cache::remember(
                'seo:product:'.$slug,
                now()->addMinutes(self::CACHE_TTL_MINUTES),
                function () use ($slug) {
                    $product = Product::query()
                        ->where('slug', $slug)
                        ->where('is_active', true)
                        ->first(['slug', 'name', 'blurb', 'price', 'image_path']);

                    // A plain array, not the model: this is cached, and only these five
                    // columns are ever read. The empty array is a cached miss — a dead
                    // link doing the rounds on social must not turn every crawler hit
                    // into a query, which a cached null would (null reads as a miss).
                    return $product ? [
                        'slug' => $product->slug,
                        'name' => $product->name,
                        'blurb' => (string) $product->blurb,
                        'price' => (int) $product->price,
                        'image_path' => $product->image_path,
                    ] : [];
                },
            );
        } catch (Throwable $e) {
            // The shell has to render even with the database down, and the visitor must
            // never see the query. Logged, then fall through to the brand default.
            report($e);

            return null;
        }

        return $product ?: null;
    }

    /** Crawlers reject a relative og:image, so this is always absolute. */
    private function defaultImage(): string
    {
        return asset('reefer-logo.jpg');
    }

    private function tag(string $keyAttr, string $key, string $value): string
    {
        return '<meta '.$keyAttr.'="'.e($key).'" content="'.e($value).'">';
    }

    private function normalize(string $path): string
    {
        // Request::path() hands over 'product/og-wave' (and '/' for the root). Lower-cased
        // so /Shop and /shop cannot preview as two different pages.
        return '/'.trim(strtolower($path), '/');
    }
}
