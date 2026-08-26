<?php

namespace App\Http\Controllers;

use App\Models\AppSetting;
use App\Models\ChangelogEntry;
use App\Support\DocumentationNav;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\URL;

/**
 * /sitemap.xml + /robots.txt — the boring-but-necessary search-engine
 * surfaces. Both are cached for an hour because the underlying data
 * (marketing routes, doc slugs, published changelog entries) changes
 * rarely; recomputation cost on a stale read isn't worth saving.
 */
class SeoController
{
    private const SITEMAP_CACHE_KEY = 'seo:sitemap.xml';

    private const SITEMAP_CACHE_TTL_SECONDS = 3600;

    public function sitemap(): Response
    {
        // Private install (marketing site disabled) → empty sitemap.
        // Same shape so crawlers don't 404; just no routes to crawl.
        if (! $this->marketingEnabled()) {
            $xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n"
                .'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"></urlset>'."\n";

            return response($xml, 200, [
                'Content-Type' => 'application/xml; charset=utf-8',
            ]);
        }

        $xml = Cache::remember(
            self::SITEMAP_CACHE_KEY,
            self::SITEMAP_CACHE_TTL_SECONDS,
            fn () => $this->buildSitemap(),
        );

        return response($xml, 200, [
            'Content-Type' => 'application/xml; charset=utf-8',
        ]);
    }

    public function robots(): Response
    {
        // Private install → tell every crawler to stay out. Search
        // engines should never index a private deployment.
        if (! $this->marketingEnabled()) {
            $body = "User-agent: *\nDisallow: /\n";

            return response($body, 200, [
                'Content-Type' => 'text/plain; charset=utf-8',
            ]);
        }

        $base = rtrim(URL::to('/'), '/');

        $body = implode("\n", [
            'User-agent: *',
            'Allow: /',
            'Disallow: /admin',
            'Disallow: /admin/',
            'Disallow: /app',
            'Disallow: /app/',
            'Disallow: /api/',
            'Disallow: /settings',
            'Disallow: /settings/',
            'Disallow: /login',
            'Disallow: /register',
            'Disallow: /forgot-password',
            'Disallow: /reset-password',
            'Disallow: /verify-email',
            '',
            "Sitemap: {$base}/sitemap.xml",
            '',
        ]);

        return response($body, 200, [
            'Content-Type' => 'text/plain; charset=utf-8',
        ]);
    }

    private function marketingEnabled(): bool
    {
        return (bool) (AppSetting::singleton()->marketing_site_enabled ?? true);
    }

    private function buildSitemap(): string
    {
        $base = rtrim(URL::to('/'), '/');
        $now = now()->toAtomString();

        $urls = [];

        // Static marketing routes — every page that anyone unauthenticated
        // can land on. Priority + changefreq are best-effort hints; modern
        // crawlers ignore them but we keep them well-formed.
        $marketing = [
            ['loc' => '/', 'priority' => '1.0', 'changefreq' => 'weekly'],
            ['loc' => '/pricing', 'priority' => '0.9', 'changefreq' => 'weekly'],
            ['loc' => '/how-it-works', 'priority' => '0.8', 'changefreq' => 'monthly'],
            ['loc' => '/integrations', 'priority' => '0.7', 'changefreq' => 'monthly'],
            ['loc' => '/changelog', 'priority' => '0.7', 'changefreq' => 'weekly'],
            ['loc' => '/privacy', 'priority' => '0.4', 'changefreq' => 'yearly'],
            ['loc' => '/terms', 'priority' => '0.4', 'changefreq' => 'yearly'],
            ['loc' => '/documentation', 'priority' => '0.8', 'changefreq' => 'weekly'],
        ];

        foreach ($marketing as $entry) {
            $urls[] = [
                'loc' => $base.$entry['loc'],
                'lastmod' => $now,
                'changefreq' => $entry['changefreq'],
                'priority' => $entry['priority'],
            ];
        }

        // Documentation pages — every slug the nav advertises.
        // DocumentationNav::flat() returns a slug-keyed map.
        foreach (array_keys(DocumentationNav::flat()) as $slug) {
            if ($slug === '') {
                continue;
            }

            $urls[] = [
                'loc' => "{$base}/documentation/{$slug}",
                'lastmod' => $now,
                'changefreq' => 'monthly',
                'priority' => '0.6',
            ];
        }

        // Published changelog entries — each version anchors permalinkable
        // (the page is one /changelog with #v1.1.0 fragments).
        foreach (ChangelogEntry::published()->take(200) as $entry) {
            $urls[] = [
                'loc' => "{$base}/changelog#{$entry->version}",
                'lastmod' => $entry->released_at?->toAtomString() ?? $now,
                'changefreq' => 'never',
                'priority' => '0.5',
            ];
        }

        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n";
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n";

        foreach ($urls as $u) {
            $xml .= "  <url>\n";
            $xml .= '    <loc>'.htmlspecialchars($u['loc'], ENT_XML1)."</loc>\n";
            $xml .= "    <lastmod>{$u['lastmod']}</lastmod>\n";
            $xml .= "    <changefreq>{$u['changefreq']}</changefreq>\n";
            $xml .= "    <priority>{$u['priority']}</priority>\n";
            $xml .= "  </url>\n";
        }

        $xml .= '</urlset>'."\n";

        return $xml;
    }
}
