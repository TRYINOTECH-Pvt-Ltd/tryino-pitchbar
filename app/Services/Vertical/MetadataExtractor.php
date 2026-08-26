<?php

namespace App\Services\Vertical;

use App\Services\Crawl\HtmlExtractor;

/**
 * Pulls vertical-classification signals out of raw HTML.
 *
 * Distinct from {@see HtmlExtractor}, which strips
 * chrome and yields clean text for embedding. This extractor does the
 * opposite: it preserves and normalizes structured signals (og:type,
 * JSON-LD @type, generator meta, code blocks, URL shape) so the
 * SiteTypeDetector can score them.
 *
 * Pure function — no I/O, no state. HTML is parsed defensively with
 * libxml errors suppressed so a malformed page never throws.
 */
class MetadataExtractor
{
    private const MAX_HTML_BYTES = 524_288; // 500 KB cap on parsed input.

    /**
     * @return array{
     *   doctype: ?string,
     *   generator: ?string,
     *   og: array<string, string>,
     *   twitter: array<string, string>,
     *   json_ld_types: array<int, string>,
     *   has_code_blocks: bool,
     *   has_article_tag: bool,
     *   nav_links: array<int, string>,
     *   url_path: string,
     *   url_host: string,
     * }
     */
    public function extract(string $html, string $url): array
    {
        if (strlen($html) > self::MAX_HTML_BYTES) {
            $html = substr($html, 0, self::MAX_HTML_BYTES);
        }

        $urlPath = (string) (parse_url($url, PHP_URL_PATH) ?: '/');
        $urlHost = strtolower((string) (parse_url($url, PHP_URL_HOST) ?: ''));

        if (trim($html) === '') {
            return $this->emptyResult($urlPath, $urlHost);
        }

        $previous = libxml_use_internal_errors(true);
        $doc = new \DOMDocument;
        // loadHTML wants the HTML wrapped so encoding stays consistent.
        $doc->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $xpath = new \DOMXPath($doc);

        return [
            'doctype' => $this->detectDoctype($html),
            'generator' => $this->metaContent($xpath, 'generator'),
            'og' => $this->metaPropertyMap($xpath, 'og:'),
            'twitter' => $this->metaPropertyMap($xpath, 'twitter:'),
            'json_ld_types' => $this->jsonLdTypes($xpath),
            'has_code_blocks' => $this->countCodeBlocks($xpath) >= 3,
            'has_article_tag' => $xpath->query('//article')?->length > 0,
            'nav_links' => $this->navLinks($xpath),
            'url_path' => $urlPath,
            'url_host' => $urlHost,
        ];
    }

    /**
     * @return array{doctype: ?string, generator: ?string, og: array<string,string>, twitter: array<string,string>, json_ld_types: array<int,string>, has_code_blocks: bool, has_article_tag: bool, nav_links: array<int,string>, url_path: string, url_host: string}
     */
    private function emptyResult(string $urlPath, string $urlHost): array
    {
        return [
            'doctype' => null,
            'generator' => null,
            'og' => [],
            'twitter' => [],
            'json_ld_types' => [],
            'has_code_blocks' => false,
            'has_article_tag' => false,
            'nav_links' => [],
            'url_path' => $urlPath,
            'url_host' => $urlHost,
        ];
    }

    private function detectDoctype(string $html): ?string
    {
        if (preg_match('/<!DOCTYPE\s+([^>]+)>/i', $html, $m)) {
            return strtolower(trim($m[1]));
        }

        return null;
    }

    private function metaContent(\DOMXPath $xpath, string $name): ?string
    {
        $nodes = $xpath->query("//meta[translate(@name,'ABCDEFGHIJKLMNOPQRSTUVWXYZ','abcdefghijklmnopqrstuvwxyz')='".strtolower($name)."']");
        if ($nodes && $nodes->length > 0) {
            $node = $nodes->item(0);
            $val = $node instanceof \DOMElement ? trim($node->getAttribute('content')) : '';

            return $val !== '' ? $val : null;
        }

        return null;
    }

    /**
     * @return array<string, string>
     */
    private function metaPropertyMap(\DOMXPath $xpath, string $prefix): array
    {
        $out = [];
        $nodes = $xpath->query('//meta[@property or @name]');
        if (! $nodes) {
            return $out;
        }
        foreach ($nodes as $node) {
            if (! $node instanceof \DOMElement) {
                continue;
            }
            $key = $node->getAttribute('property') ?: $node->getAttribute('name');
            $key = strtolower($key);
            if ($key === '' || ! str_starts_with($key, $prefix)) {
                continue;
            }
            $value = trim($node->getAttribute('content'));
            if ($value === '') {
                continue;
            }
            // Strip prefix so callers see e.g. 'type' not 'og:type'.
            $out[substr($key, strlen($prefix))] = $value;
        }

        return $out;
    }

    /**
     * Returns every distinct `@type` value declared in JSON-LD on the
     * page. Tolerates both single object and `@graph` arrays, plus
     * arrays of types (Schema.org allows that).
     *
     * @return array<int, string>
     */
    private function jsonLdTypes(\DOMXPath $xpath): array
    {
        $types = [];
        $nodes = $xpath->query("//script[@type='application/ld+json']");
        if (! $nodes) {
            return [];
        }
        foreach ($nodes as $node) {
            $raw = $node->textContent ?? '';
            if (trim($raw) === '') {
                continue;
            }
            $decoded = json_decode($raw, true);
            if (! is_array($decoded)) {
                continue;
            }
            $this->collectTypes($decoded, $types);
        }

        return array_values(array_unique($types));
    }

    /**
     * @param  array<int|string, mixed>  $node
     * @param  array<int, string>  $types
     */
    private function collectTypes(array $node, array &$types): void
    {
        // Single object: pull @type.
        if (isset($node['@type'])) {
            $t = $node['@type'];
            if (is_string($t)) {
                $types[] = $t;
            } elseif (is_array($t)) {
                foreach ($t as $sub) {
                    if (is_string($sub)) {
                        $types[] = $sub;
                    }
                }
            }
        }
        // @graph wraps an array of nodes — recurse.
        if (isset($node['@graph']) && is_array($node['@graph'])) {
            foreach ($node['@graph'] as $entry) {
                if (is_array($entry)) {
                    $this->collectTypes($entry, $types);
                }
            }
        }
        // Some sites just emit a top-level array of typed objects.
        foreach ($node as $key => $value) {
            if ($key === '@type' || $key === '@graph') {
                continue;
            }
            if (is_array($value) && array_is_list($value)) {
                foreach ($value as $entry) {
                    if (is_array($entry)) {
                        $this->collectTypes($entry, $types);
                    }
                }
            } elseif (is_array($value) && isset($value['@type'])) {
                $this->collectTypes($value, $types);
            }
        }
    }

    private function countCodeBlocks(\DOMXPath $xpath): int
    {
        $nodes = $xpath->query('//pre/code');

        return $nodes ? $nodes->length : 0;
    }

    /**
     * @return array<int, string>
     */
    private function navLinks(\DOMXPath $xpath): array
    {
        $nodes = $xpath->query('(//nav | //header)//a');
        if (! $nodes) {
            return [];
        }
        $out = [];
        $count = 0;
        foreach ($nodes as $node) {
            if ($count >= 10) {
                break;
            }
            $text = trim($node->textContent ?? '');
            if ($text === '') {
                continue;
            }
            $out[] = $text;
            $count++;
        }

        return $out;
    }
}
