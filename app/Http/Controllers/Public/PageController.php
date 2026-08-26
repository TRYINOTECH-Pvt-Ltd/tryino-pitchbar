<?php

namespace App\Http\Controllers\Public;

use App\Models\Page;
use App\Support\AppBranding;
use App\Support\MarketingShellContent;
use Inertia\Inertia;
use Inertia\Response;
use League\CommonMark\GithubFlavoredMarkdownConverter;

/**
 * Public renderer for admin-created custom pages. Unpublished pages
 * return 404 — operators can author drafts without exposing them.
 * Markdown is converted server-side with the same GFM renderer the
 * /documentation pages use; raw HTML in the source is escaped so a
 * compromised admin account can't inject script via an authored
 * page.
 *
 * Renders through Inertia + `MarketingShell` so the page chrome
 * (header nav, footer, locale picker) is byte-for-byte identical to
 * the landing pages. Pre-fix this used a separate Blade layout which
 * shipped a different nav + footer — client report 2026-05-22.
 */
class PageController
{
    public function show(string $slug): Response
    {
        $page = Page::query()
            ->where('slug', $slug)
            ->where('is_published', true)
            ->first();

        if ($page === null) {
            abort(404);
        }

        $converter = new GithubFlavoredMarkdownConverter([
            'html_input' => 'escape',
            'allow_unsafe_links' => false,
        ]);
        $html = (string) $converter->convert($page->content_markdown ?? '');

        return Inertia::render('marketing/page', [
            'shell' => MarketingShellContent::resolve(),
            'brand' => AppBranding::siteTitle(),
            'page' => [
                'title' => $page->title,
                'slug' => $page->slug,
                'html' => $html,
                'updated_at' => $page->updated_at?->toIso8601String(),
            ],
        ]);
    }
}
