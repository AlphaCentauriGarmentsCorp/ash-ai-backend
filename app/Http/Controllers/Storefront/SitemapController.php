<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Models\Storefront\Product;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * /sitemap.xml — the crawl list for the storefront.
 *
 * React Router owns the URLs, so nothing on disk tells a crawler that
 * /product/og-wave exists. This does.
 */
class SitemapController extends Controller
{
    private const CACHE_TTL_MINUTES = 10;

    /**
     * Indexable pages, in the order a crawler should meet them. Cart, checkout,
     * account and sign-in are deliberately absent — they are per-visitor pages with
     * nothing to rank, and robots.txt disallows them too.
     *
     * @var array<string, string> path => priority
     */
    private const STATIC_PATHS = [
        '/' => '1.0',
        '/shop' => '0.9',
        '/products' => '0.9',
        '/lookbook' => '0.7',
        '/about' => '0.6',
        '/faq' => '0.5',
        '/sizing-guide' => '0.5',
    ];

    public function __invoke(): Response
    {
        $urls = [];

        foreach (self::STATIC_PATHS as $path => $priority) {
            $urls[] = [
                // Trailing slash on the root so the loc matches the canonical the shell
                // emits — the two disagreeing is how a homepage ends up indexed twice.
                'loc' => $path === '/' ? url('/').'/' : url($path),
                'changefreq' => $path === '/' ? 'daily' : 'weekly',
                'priority' => $priority,
            ];
        }

        foreach ($this->products() as $product) {
            $urls[] = [
                'loc' => url('/product/'.$product['slug']),
                'lastmod' => $product['lastmod'],
                'changefreq' => 'weekly',
                'priority' => '0.8',
            ];
        }

        return response($this->render($urls), 200, [
            'Content-Type' => 'application/xml; charset=UTF-8',
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }

    /**
     * @return list<array{slug: string, lastmod: string|null}>
     */
    private function products(): array
    {
        try {
            return Cache::remember(
                'seo:sitemap:products',
                now()->addMinutes(self::CACHE_TTL_MINUTES),
                fn () => Product::query()
                    ->where('is_active', true)
                    ->orderBy('sort')
                    ->orderBy('id')
                    ->get(['slug', 'updated_at'])
                    ->map(fn ($product) => [
                        'slug' => $product->slug,
                        'lastmod' => $product->updated_at?->toAtomString(),
                    ])
                    ->all(),
            );
        } catch (Throwable $e) {
            // A sitemap listing only the static pages beats a 500 in Search Console.
            report($e);

            return [];
        }
    }

    /**
     * @param  list<array{loc: string, lastmod?: string|null, changefreq: string, priority: string}>  $urls
     */
    private function render(array $urls): string
    {
        $lines = [
            '<?xml version="1.0" encoding="UTF-8"?>',
            '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">',
        ];

        foreach ($urls as $url) {
            $lines[] = '  <url>';
            $lines[] = '    <loc>'.$this->escape($url['loc']).'</loc>';

            if (! empty($url['lastmod'])) {
                $lines[] = '    <lastmod>'.$this->escape($url['lastmod']).'</lastmod>';
            }

            $lines[] = '    <changefreq>'.$url['changefreq'].'</changefreq>';
            $lines[] = '    <priority>'.$url['priority'].'</priority>';
            $lines[] = '  </url>';
        }

        $lines[] = '</urlset>';

        return implode("\n", $lines)."\n";
    }

    /** Slugs are tame, but an & in a URL still has to be an entity or the XML will not parse. */
    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
