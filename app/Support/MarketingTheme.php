<?php

namespace App\Support;

use App\Models\AppSetting;

/**
 * Pluggable marketing-site theme registry.
 *
 * The marketing site (home / pricing / how-it-works / integrations /
 * privacy / terms / changelog) is composed of an entire bundle of
 * Inertia page components plus a shell layout. Themes let operators
 * swap that bundle wholesale by editing one row on `app_settings.
 * marketing_theme` — no controller / route edits needed.
 *
 * The shipped `harvest` theme keeps the legacy file locations (the
 * original `welcome.tsx` + `pages/marketing/*` files) so existing
 * installs render identically. New themes live in self-contained dirs
 * at `resources/js/pages/marketing-themes/{slug}/` and must contain a
 * `theme.json` manifest plus the eight page components below.
 *
 * To add a new theme `meadow`:
 *   1. Create `resources/js/pages/marketing-themes/meadow/theme.json`
 *      with at minimum `{ "name": "Meadow" }`.
 *   2. Add the eight component files: `home.tsx`, `pricing.tsx`,
 *      `how-it-works.tsx`, `integrations.tsx`, `privacy.tsx`,
 *      `terms.tsx`, `changelog.tsx`, plus a `shell.tsx` if you want
 *      to override the layout (otherwise import the harvest shell).
 *   3. Flip `marketing_theme` to `meadow` via /settings/system?section=marketing.
 *
 * Unknown / deleted theme slugs fall back to `harvest` automatically
 * so a broken setting can't blank the site.
 */
final class MarketingTheme
{
    public const DEFAULT_SLUG = 'harvest';

    /**
     * Page keys the resolver knows about. Maps to the Inertia component
     * path the controller renders. Keep in sync with the file naming
     * convention documented at the top of this class.
     */
    private const PAGES = ['home', 'pricing', 'how-it-works', 'integrations', 'privacy', 'terms', 'changelog'];

    /**
     * Legacy mapping for the default `harvest` theme. The current
     * marketing pages live at their original locations (`welcome.tsx`,
     * `pages/marketing/*`), not under `marketing-themes/harvest/`,
     * because that's where existing installs already expect them. To
     * move them later, change this table.
     *
     * @var array<string, string>
     */
    private const HARVEST_COMPONENTS = [
        'home' => 'welcome',
        'pricing' => 'marketing/pricing',
        'how-it-works' => 'marketing/how-it-works',
        'integrations' => 'marketing/integrations',
        'privacy' => 'marketing/privacy',
        'terms' => 'marketing/terms',
        'changelog' => 'marketing/changelog',
    ];

    /**
     * Slug of the currently active theme. Falls back to `harvest` when
     * the configured slug is missing or unknown.
     */
    public static function active(): string
    {
        $slug = (string) (AppSetting::singleton()->marketing_theme ?? '');

        return self::isAvailable($slug) ? $slug : self::DEFAULT_SLUG;
    }

    /**
     * Resolve a page key to its Inertia component path for the active
     * theme. Unknown page keys throw — they are a developer error, not
     * an operator-controllable state.
     */
    public static function component(string $page): string
    {
        if (! in_array($page, self::PAGES, true)) {
            throw new \InvalidArgumentException("Unknown marketing page key: {$page}");
        }

        $slug = self::active();

        if ($slug === self::DEFAULT_SLUG) {
            return self::HARVEST_COMPONENTS[$page];
        }

        if (! self::themeProvidesPage($slug, $page)) {
            return self::HARVEST_COMPONENTS[$page];
        }

        return "marketing-themes/{$slug}/{$page}";
    }

    /**
     * Resolve a page key for an arbitrary theme slug. Used by the
     * settings UI to preview where a not-yet-active theme would render.
     * Falls back to harvest for pages a theme has not declared.
     */
    public static function componentFor(string $slug, string $page): string
    {
        if (! in_array($page, self::PAGES, true)) {
            throw new \InvalidArgumentException("Unknown marketing page key: {$page}");
        }

        if ($slug === self::DEFAULT_SLUG) {
            return self::HARVEST_COMPONENTS[$page];
        }

        if (! self::themeProvidesPage($slug, $page)) {
            return self::HARVEST_COMPONENTS[$page];
        }

        return "marketing-themes/{$slug}/{$page}";
    }

    /**
     * Whether a given theme declares (and ships) a specific page. A
     * theme's `theme.json` may include a `pages` array enumerating
     * which page keys it provides; everything else falls back to
     * harvest. When the manifest omits `pages` the theme is treated
     * as providing all pages (back-compat with single-file themes
     * that declared every page).
     */
    public static function themeProvidesPage(string $slug, string $page): bool
    {
        if ($slug === self::DEFAULT_SLUG) {
            return true;
        }

        $manifests = self::discoverThemes();
        if (! isset($manifests[$slug])) {
            return false;
        }

        $declared = $manifests[$slug]['pages'] ?? null;
        if (is_array($declared)) {
            return in_array($page, $declared, true);
        }

        return true;
    }

    /**
     * Theme slugs that exist on disk but should NOT appear in the
     * admin picker. Hidden themes are still discoverable by file
     * lookup but `available()` / `availableSlugs()` filter them out,
     * which also means the validation rule rejects them and any
     * workspace still configured for a hidden theme falls back to
     * Harvest via `active()`'s `isAvailable()` guard.
     *
     * @var list<string>
     */
    private const HIDDEN_SLUGS = ['prism'];

    /**
     * @return list<array{slug: string, name: string, description: string|null}>
     */
    public static function available(): array
    {
        $themes = [[
            'slug' => self::DEFAULT_SLUG,
            'name' => 'Harvest',
            'description' => 'The original Pitchbar marketing site — warm tones, generous whitespace, conversion-focused.',
        ]];

        foreach (self::discoverThemes() as $slug => $manifest) {
            if ($slug === self::DEFAULT_SLUG) {
                continue;
            }
            if (in_array($slug, self::HIDDEN_SLUGS, true)) {
                continue;
            }
            $themes[] = [
                'slug' => $slug,
                'name' => (string) ($manifest['name'] ?? ucfirst($slug)),
                'description' => isset($manifest['description']) ? (string) $manifest['description'] : null,
            ];
        }

        return $themes;
    }

    /**
     * Slug-only list for validation. Always includes the built-in
     * `harvest` theme.
     *
     * @return list<string>
     */
    public static function availableSlugs(): array
    {
        return array_map(fn (array $row) => $row['slug'], self::available());
    }

    public static function isAvailable(string $slug): bool
    {
        return in_array($slug, self::availableSlugs(), true);
    }

    /**
     * Page keys the system knows about. Useful for tests and tooling.
     *
     * @return list<string>
     */
    public static function pages(): array
    {
        return self::PAGES;
    }

    /**
     * Scan `resources/js/pages/marketing-themes/` for theme manifests.
     * Cached per-request via a static property.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function discoverThemes(): array
    {
        $base = resource_path('js/pages/marketing-themes');
        if (! is_dir($base)) {
            return [];
        }

        $manifests = [];
        foreach ((array) glob($base.'/*', GLOB_ONLYDIR) as $dir) {
            if (! is_string($dir)) {
                continue;
            }
            $slug = basename($dir);
            $manifestPath = $dir.'/theme.json';
            if (! is_file($manifestPath)) {
                continue;
            }
            $raw = @file_get_contents($manifestPath);
            if ($raw === false) {
                continue;
            }
            $decoded = json_decode($raw, true);
            if (! is_array($decoded)) {
                continue;
            }
            $manifests[$slug] = $decoded;
        }

        return $manifests;
    }
}
