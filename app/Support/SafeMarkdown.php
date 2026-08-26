<?php

namespace App\Support;

use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\GithubFlavoredMarkdownExtension;
use League\CommonMark\MarkdownConverter;

/**
 * Hardened markdown → HTML rendering for operator-supplied content
 * (knowledge-base articles, curated answers, anywhere a workspace
 * admin authors markdown that ends up in front of public visitors).
 *
 * `Illuminate\Support\Str::markdown` defaults the league/commonmark
 * environment to `html_input = 'allow'` AND `allow_unsafe_links = true`.
 * That means a stored markdown body containing `<img onerror=…>` or
 * `[x](javascript:…)` renders into live HTML / a live JS-scheme link
 * on a public KB page — stored XSS in any browser that opens the
 * article. Verified live before this helper landed.
 *
 * This class swaps in a strict converter:
 *
 *   - html_input        = 'strip'  → raw HTML tags removed entirely,
 *                                    no event-handler attributes survive
 *   - allow_unsafe_links = false   → href/src with javascript:, vbscript:,
 *                                    data: schemes get stripped/blanked
 *
 * The converter is memoised so the per-request render stays cheap
 * (commonmark's Environment build is the slow part). Octane-safe:
 * stateless, idempotent.
 */
class SafeMarkdown
{
    private static ?MarkdownConverter $converter = null;

    public static function render(string $markdown): string
    {
        return (string) self::converter()->convert($markdown);
    }

    /**
     * For tests + service providers that want to reset cached state
     * between scenarios. Production code never calls this.
     */
    public static function flush(): void
    {
        self::$converter = null;
    }

    private static function converter(): MarkdownConverter
    {
        if (self::$converter !== null) {
            return self::$converter;
        }

        $environment = new Environment([
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
        ]);
        $environment->addExtension(new CommonMarkCoreExtension);
        $environment->addExtension(new GithubFlavoredMarkdownExtension);

        return self::$converter = new MarkdownConverter($environment);
    }
}
