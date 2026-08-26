<?php

namespace App\Support;

use App\Services\I18n\TranslationLoader;
use Illuminate\Support\Facades\URL;

/**
 * Per-route SEO payload generator. Consumers (Inertia controllers
 * and Blade pages) call `SeoMeta::for($routeKey, $overrides)` and
 * pass the result into the page render. The Inertia root layout
 * (`resources/views/app.blade.php`) reads it back from shared props
 * and emits <meta>, Open Graph, Twitter Card, and a JSON-LD script
 * tag.
 *
 * Keep the helper deterministic + dependency-light: pure functions
 * over the AppBranding singleton, no DB unless the caller passes
 * dynamic content. Hot-path-safe.
 */
class SeoMeta
{
    private const ROUTE_DEFAULTS = [
        'home' => [
            'title' => '{brand} — AI sales assistant for high-intent pages',
            'description' => 'Turn every high-intent page into a sales conversation. The {brand} chat widget answers from your real content, captures leads, and hands off to humans on demand. Self-hostable, multi-tenant, white-labelable.',
            'path' => '/',
        ],
        'pricing' => [
            'title' => 'Pricing — {brand}',
            'description' => 'Simple, conversation-based pricing for the {brand} sales AI widget. Free to start; scale as you grow. Monthly or annual billing with transparent per-conversation overage.',
            'path' => '/pricing',
        ],
        'how-it-works' => [
            'title' => 'How it works — {brand}',
            'description' => 'See how {brand} turns your existing site content into a 24/7 sales rep — index → embed → converse → capture. From setup to first conversation in under 5 minutes.',
            'path' => '/how-it-works',
        ],
        'integrations' => [
            'title' => 'Integrations — {brand}',
            'description' => '{brand} connects to Slack, Notion, Google Docs, your CRM via webhooks, and any LLM provider you bring. One platform, your existing stack.',
            'path' => '/integrations',
        ],
        'privacy' => [
            'title' => 'Privacy policy — {brand}',
            'description' => 'How {brand} collects, stores, and protects your data. Self-hostable so the data never leaves your infrastructure if you choose.',
            'path' => '/privacy',
        ],
        'terms' => [
            'title' => 'Terms of service — {brand}',
            'description' => 'Terms governing use of {brand}.',
            'path' => '/terms',
        ],
        'changelog.show' => [
            'title' => 'Changelog — {brand}',
            'description' => 'Every release of {brand} — what shipped, what changed, and what is fixed. Subscribe to the JSON feed for an always-current view.',
            'path' => '/changelog',
        ],
        'documentation.index' => [
            'title' => 'Documentation — {brand}',
            'description' => '{brand} documentation — quickstart, embed snippets, knowledge sources, workflows, integrations, and the architecture deep-dive.',
            'path' => '/documentation',
        ],
        'documentation.show' => [
            // Title + description vary per page; the controller passes
            // them through as overrides.
            'title' => '{page_title} — {brand} docs',
            'description' => '{page_summary}',
            'path' => '/documentation',
        ],
    ];

    /**
     * Resolve the SEO payload for a route. Returns a flat array
     * shaped for direct use in the Blade meta block.
     *
     * @param  array<string, mixed>  $overrides  Per-page overrides
     *                                           (e.g. canonical path,
     *                                           page_title, page_summary,
     *                                           json_ld blocks).
     * @return array<string, mixed>
     */
    public static function for(string $routeKey, array $overrides = []): array
    {
        $brand = AppBranding::siteTitle();
        $defaults = self::ROUTE_DEFAULTS[$routeKey] ?? [];

        $tokens = [
            'brand' => $brand,
            'page_title' => (string) ($overrides['page_title'] ?? $brand),
            'page_summary' => (string) ($overrides['page_summary'] ?? 'Documentation'),
        ];

        // SEO title/description are translatable. Resolve the TEMPLATE (with
        // the {brand} token still in place) through the override-aware loader
        // at the active locale, THEN interpolate — so an operator's locale
        // override renders, while {brand} stays a placeholder (protected
        // during machine translation). When the locale has no override (e.g.
        // English, or multilingual disabled) get() returns null and the
        // shipped template is used unchanged. Mirrors {@see MarketingTranslator}.
        $locale = app()->getLocale();
        $loader = app(TranslationLoader::class);

        $titleTemplate = (string) ($overrides['title'] ?? $defaults['title'] ?? $brand);
        $title = self::interpolate(
            $loader->get($locale, $titleTemplate) ?? $titleTemplate,
            $tokens,
        );

        $descriptionTemplate = (string) ($overrides['description'] ?? $defaults['description'] ?? '');
        $description = self::interpolate(
            $descriptionTemplate === ''
                ? ''
                : ($loader->get($locale, $descriptionTemplate) ?? $descriptionTemplate),
            $tokens,
        );

        $path = (string) ($overrides['path'] ?? $defaults['path'] ?? '/');
        $canonical = self::canonicalUrl($path);

        $imageUrl = self::resolveImage($overrides['image_url'] ?? null);

        $payload = [
            'title' => $title,
            'description' => $description,
            'canonical' => $canonical,
            'site_name' => $brand,
            'image_url' => $imageUrl,
            'twitter_card' => $overrides['twitter_card'] ?? 'summary_large_image',
            'twitter_handle' => self::twitterHandle(),
            'noindex' => (bool) ($overrides['noindex'] ?? false),
            'json_ld' => self::buildJsonLd($routeKey, $brand, $canonical, $imageUrl, $overrides),
            'route_key' => $routeKey,
        ];

        return $payload;
    }

    /**
     * Backwards-compatible entry point for surfaces that don't
     * have a route key registered — returns the install's defaults
     * with whatever overrides the caller passes.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function defaults(array $overrides = []): array
    {
        return self::for('home', $overrides);
    }

    /**
     * Every translatable SEO meta string — the per-route title and
     * description templates. Surfaced in the Translation Manager so
     * operators can localise them; {@see for()} resolves each against the
     * locale's overrides at render time. Templates that are nothing but a
     * placeholder (e.g. "{page_summary}") carry no copy and are skipped.
     *
     * @return array<int, string>
     */
    public static function translatableStrings(): array
    {
        $strings = [];

        foreach (self::ROUTE_DEFAULTS as $route) {
            foreach (['title', 'description'] as $field) {
                $value = trim((string) ($route[$field] ?? ''));
                if ($value === '') {
                    continue;
                }

                // Skip values with no copy once {placeholders} are removed.
                $stripped = trim((string) preg_replace('/\{[a-z_]+\}/i', '', $value));
                if (preg_match('/\p{L}/u', $stripped) !== 1) {
                    continue;
                }

                $strings[] = $value;
            }
        }

        return array_values(array_unique($strings));
    }

    private static function canonicalUrl(string $path): string
    {
        $base = rtrim((string) URL::to('/'), '/');
        $clean = '/'.ltrim($path, '/');

        return $base.($clean === '/' ? '' : $clean);
    }

    private static function resolveImage(?string $override): ?string
    {
        if ($override !== null && $override !== '') {
            return $override;
        }

        // Buyer can drop a custom og-image.png in public/ to override
        // the shipped default. URL::to() keeps the host correct under
        // every environment.
        $defaultPath = '/og-image.png';
        $publicPath = public_path(ltrim($defaultPath, '/'));

        if (is_file($publicPath)) {
            return URL::to($defaultPath);
        }

        return null;
    }

    private static function twitterHandle(): ?string
    {
        $raw = (string) config('seo.twitter_handle', '');

        return $raw === '' ? null : (str_starts_with($raw, '@') ? $raw : '@'.$raw);
    }

    /**
     * @param  array<string, string>  $tokens
     */
    private static function interpolate(string $template, array $tokens): string
    {
        $out = $template;
        foreach ($tokens as $key => $value) {
            $out = str_replace('{'.$key.'}', $value, $out);
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<int, array<string, mixed>>
     */
    private static function buildJsonLd(
        string $routeKey,
        string $brand,
        string $canonical,
        ?string $imageUrl,
        array $overrides,
    ): array {
        $organization = [
            '@context' => 'https://schema.org',
            '@type' => 'Organization',
            'name' => $brand,
            'url' => self::canonicalUrl('/'),
        ];

        if ($imageUrl !== null) {
            $organization['logo'] = $imageUrl;
        }

        $blocks = [$organization];

        if ($routeKey === 'home') {
            $software = [
                '@context' => 'https://schema.org',
                '@type' => 'SoftwareApplication',
                'name' => $brand,
                'applicationCategory' => 'BusinessApplication',
                'operatingSystem' => 'Web',
                'description' => (string) ($overrides['description'] ?? ''),
                'offers' => [
                    '@type' => 'Offer',
                    'price' => '0',
                    'priceCurrency' => 'USD',
                    'availability' => 'https://schema.org/InStock',
                ],
                'url' => $canonical,
            ];

            if ($imageUrl !== null) {
                $software['image'] = $imageUrl;
            }

            $blocks[] = $software;

            // FAQPage block — surfaces the marketing FAQ to Google's
            // rich-result picker. Sourced from MarketingHomeContent
            // so the buyer's edits flow through automatically.
            $faqItems = $overrides['faq_items'] ?? [];

            if (is_array($faqItems) && count($faqItems) > 0) {
                $blocks[] = [
                    '@context' => 'https://schema.org',
                    '@type' => 'FAQPage',
                    'mainEntity' => array_map(static function ($item) {
                        return [
                            '@type' => 'Question',
                            'name' => (string) ($item['question'] ?? ''),
                            'acceptedAnswer' => [
                                '@type' => 'Answer',
                                'text' => (string) ($item['answer'] ?? ''),
                            ],
                        ];
                    }, $faqItems),
                ];
            }
        }

        $breadcrumb = $overrides['breadcrumb'] ?? null;

        if (is_array($breadcrumb) && count($breadcrumb) > 0) {
            $blocks[] = [
                '@context' => 'https://schema.org',
                '@type' => 'BreadcrumbList',
                'itemListElement' => array_values(array_map(
                    static function (array $crumb, int $i) {
                        return [
                            '@type' => 'ListItem',
                            'position' => $i + 1,
                            'name' => (string) ($crumb['name'] ?? ''),
                            'item' => (string) ($crumb['url'] ?? ''),
                        ];
                    },
                    $breadcrumb,
                    array_keys($breadcrumb),
                )),
            ];
        }

        return $blocks;
    }
}
