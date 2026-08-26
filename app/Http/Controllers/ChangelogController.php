<?php

namespace App\Http\Controllers;

use App\Models\ChangelogEntry;
use App\Support\AppBranding;
use App\Support\MarketingShellContent;
use App\Support\MarketingTheme;
use App\Support\SeoMeta;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Public-facing /changelog page + /changelog.json feed. Reads only
 * the `published` entries from the platform-wide table — drafts +
 * archived stay hidden.
 *
 * No auth, no workspace scope. Anyone with the URL can read it. This
 * is a sales surface (a "we ship constantly" trust signal) and a
 * support surface (link buyers to specific entries when answering
 * "is X fixed?").
 */
class ChangelogController
{
    public function show(): Response
    {
        $brand = AppBranding::siteTitle();
        $entries = ChangelogEntry::published()
            ->map(fn (ChangelogEntry $e) => [
                'version' => $e->version,
                'released_at' => $e->released_at?->toIso8601String(),
                'released_at_human' => $e->released_at?->toFormattedDateString(),
                'title' => self::rebrand($e->title, $brand),
                'body' => self::rebrand($e->body, $brand),
            ]);

        $latest = $entries->first();

        $seoOverrides = ['path' => '/changelog'];
        if ($latest !== null) {
            $seoOverrides['title'] = "Changelog — what's new in {$brand}";
            $seoOverrides['description'] = "Every release of {$brand} — latest: {$latest['version']} on {$latest['released_at_human']}. Subscribe to /changelog.json for an always-current view.";
        }

        return Inertia::render(MarketingTheme::component('changelog'), [
            'canRegister' => Route::has('register'),
            'shell' => MarketingShellContent::resolve(),
            'brand' => $brand,
            'entries' => $entries,
            'seo' => SeoMeta::for('changelog.show', $seoOverrides),
        ]);
    }

    /**
     * JSON feed for buyers who want to scrape / subscribe / embed.
     * Same data shape as the page's `entries` prop.
     */
    public function feed(): JsonResponse
    {
        $brand = AppBranding::siteTitle();
        $rows = ChangelogEntry::published()
            ->take(100)
            ->map(fn (ChangelogEntry $e) => [
                'version' => $e->version,
                'released_at' => $e->released_at?->toIso8601String(),
                'title' => self::rebrand($e->title, $brand),
                'body' => self::rebrand($e->body, $brand),
            ]);

        return response()->json([
            'brand' => $brand,
            'entries' => $rows,
        ]);
    }

    /**
     * Swap the literal "Pitchbar" the entries were authored under for the
     * current install's brand. White-label buyers configure their own
     * site title via System Settings → Branding; the persisted markdown
     * stays as-shipped so re-running `changelog:bootstrap-entries`
     * remains deterministic. No-op when the install still uses the
     * default name.
     */
    private static function rebrand(?string $text, string $brand): ?string
    {
        if ($text === null || $text === '' || $brand === 'Pitchbar') {
            return $text;
        }

        return str_replace('Pitchbar', $brand, $text);
    }
}
