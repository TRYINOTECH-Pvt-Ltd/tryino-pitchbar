<?php

namespace App\Services\Crawl;

use fivefilters\Readability\Configuration;
use fivefilters\Readability\Readability;

/**
 * Mozilla-Readability-style content extractor (PHP port).
 *
 * Why this exists: our older HtmlExtractor strips chrome via regex and
 * Material-icon ligatures, which works but misses on real-world layouts —
 * sidebar widgets, comment sections, related-posts grids, etc. Readability
 * scores DOM nodes by content density and link/text ratio and returns the
 * "main article" subtree. On news/blog/product pages it's dramatically
 * better than regex stripping.
 *
 * We use it as the PRIMARY extractor and fall back to HtmlExtractor when
 * Readability bails out (it raises ParseException on pages without a clear
 * article — soft-404s, login walls, single-paragraph SPAs) — those are
 * exactly the cases the legacy regex extractor handles well anyway.
 */
class ReadabilityExtractor
{
    public function __construct(private readonly HtmlExtractor $fallback) {}

    /**
     * @return array{title: ?string, text: string}
     */
    public function extract(string $html): array
    {
        if ($html === '') {
            return ['title' => null, 'text' => ''];
        }

        // XXE defense: libxml2 ≥ 2.9.0 (bundled with every supported PHP
        // version, and required by our composer.json) disables external
        // entity loading by default, so untrusted HTML cannot pull
        // file://, http://, or php:// payloads through DOCTYPE. We
        // keep error-buffering on so Readability's internal warnings
        // don't bleed into the response.
        $previousInternalErrors = libxml_use_internal_errors(true);

        try {
            $config = new Configuration([
                'OriginalURL' => '',
                'FixRelativeURLs' => false,
                'NormalizeEntities' => true,
                'SubstituteEntities' => true,
                'SummonCthulhu' => false,
            ]);
            $readability = new Readability($config);

            if (! $readability->parse($html)) {
                return $this->fallback->extract($html);
            }

            $title = $readability->getTitle() ?: null;
            $contentHtml = (string) $readability->getContent();

            // Convert the cleaned-up HTML to plain text. Readability has
            // already kept structural markers (h1, p, li, etc.) so a simple
            // strip_tags + whitespace-collapse gives clean output.
            $text = trim(html_entity_decode(strip_tags($contentHtml), ENT_QUOTES | ENT_HTML5));
            $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

            // If Readability extracted very little (often happens on pages
            // that aren't article-shaped), fall back. The legacy extractor
            // is better at e-commerce / product pages where there's no
            // single "main article" but lots of useful spec text.
            if (mb_strlen($text) < 200) {
                return $this->fallback->extract($html);
            }

            return ['title' => $title, 'text' => $text];
        } catch (\Throwable) {
            // Readability throws on malformed HTML / pages without a clear
            // article body. Fall through to our regex extractor.
            return $this->fallback->extract($html);
        } finally {
            libxml_use_internal_errors($previousInternalErrors);
        }
    }
}
