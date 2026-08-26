<?php

namespace App\Services\TryNow;

use App\Services\Crawl\HtmlExtractor;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Anonymous "try now" demo for the marketing site hero. Visitor pastes
 * any URL, we fetch it synchronously, extract the readable text, and
 * stash the chunks under a short-lived token. The hero chat then talks
 * to that cached context only — no agent, no workspace, no vector
 * index, no DB writes. Lives entirely in the cache so it can't pollute
 * tenant data.
 *
 * Hot-path note: ingest is synchronous (8s HTTP timeout). Stream uses
 * the in-memory chunks directly, so there's no Vectorize / retrieval
 * step. We never store the visitor's URL beyond the cache TTL.
 */
class TryNowSession
{
    private const CACHE_TTL_SECONDS = 3600;

    private const MAX_HTML_BYTES = 1_500_000;

    private const MAX_CHUNKS = 12;

    private const CHUNK_CHARS = 900;

    private const HTTP_TIMEOUT_SECONDS = 8;

    public function __construct(
        private HtmlExtractor $extractor,
    ) {}

    /**
     * Fetch the URL, extract its readable text, chunk it, and cache
     * under a fresh token. Returns ['token', 'title', 'summary',
     * 'page_url'] on success, throws on fetch / parse failure.
     *
     * @return array{token: string, title: string, summary: string, page_url: string, chunks_count: int}
     */
    public function start(string $url): array
    {
        $normalized = $this->normalizeUrl($url);

        $response = Http::timeout(self::HTTP_TIMEOUT_SECONDS)
            ->withHeaders([
                'User-Agent' => 'Mozilla/5.0 (compatible; PitchbarTryBot/1.0; +https://pitchbar.app)',
                'Accept' => 'text/html,application/xhtml+xml',
            ])
            ->get($normalized);

        if (! $response->successful()) {
            throw new TryNowFetchException(
                "Could not fetch the page (HTTP {$response->status()}).",
            );
        }

        $html = mb_substr((string) $response->body(), 0, self::MAX_HTML_BYTES);

        $extracted = $this->extractor->extract($html);
        $title = $extracted['title'] ?: $this->fallbackTitle($normalized);
        $text = trim((string) $extracted['text']);

        // SPA fallback: when the HTML body has no rendered text
        // (Inertia / Next.js / React app that hydrates client-side),
        // pull readable text from meta tags, Inertia <script data-page>
        // JSON, and JSON-LD blocks so the demo still has something to
        // talk about without spinning up a headless browser.
        if (mb_strlen($text) < 200) {
            $meta = $this->extractMetaText($html);
            $structured = $this->extractStructuredText($html);
            $augmented = trim(implode("\n\n", array_filter([$text, $meta, $structured])));
            $text = $augmented;
        }

        if (mb_strlen(trim($text)) < 60) {
            throw new TryNowFetchException(
                'That page does not have enough readable text to demo.',
            );
        }

        $chunks = $this->chunkText($text);
        $summary = $this->summary($text);

        $token = (string) Str::ulid();

        Cache::put(
            $this->cacheKey($token),
            [
                'page_url' => $normalized,
                'title' => $title,
                'summary' => $summary,
                'chunks' => $chunks,
                'created_at' => now()->toIso8601String(),
            ],
            self::CACHE_TTL_SECONDS,
        );

        return [
            'token' => $token,
            'title' => $title,
            'summary' => $summary,
            'page_url' => $normalized,
            'chunks_count' => count($chunks),
        ];
    }

    /**
     * Fetch the cached session, or null if expired / missing.
     *
     * @return array{page_url: string, title: string, summary: string, chunks: array<int, string>, created_at: string}|null
     */
    public function get(string $token): ?array
    {
        return Cache::get($this->cacheKey($token));
    }

    private function cacheKey(string $token): string
    {
        return "try-now:{$token}";
    }

    private function normalizeUrl(string $url): string
    {
        $trimmed = trim($url);

        if (! preg_match('#^https?://#i', $trimmed)) {
            $trimmed = 'https://'.$trimmed;
        }

        $parts = parse_url($trimmed);

        if ($parts === false || empty($parts['host'])) {
            throw new TryNowFetchException('That does not look like a valid URL.');
        }

        return $trimmed;
    }

    private function fallbackTitle(string $url): string
    {
        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) && $host !== '' ? $host : $url;
    }

    /**
     * Chunk text into roughly even chunks of CHUNK_CHARS characters,
     * preferring paragraph boundaries. Keeps the first chunks since
     * marketing/landing pages front-load the value proposition.
     *
     * @return array<int, string>
     */
    private function chunkText(string $text): array
    {
        $paragraphs = preg_split('/(?<=[\.\!\?])\s+(?=[A-Z])/u', $text) ?: [$text];

        $chunks = [];
        $buffer = '';

        foreach ($paragraphs as $paragraph) {
            $candidate = $buffer === '' ? $paragraph : ($buffer.' '.$paragraph);

            if (mb_strlen($candidate) > self::CHUNK_CHARS) {
                if ($buffer !== '') {
                    $chunks[] = $buffer;
                }

                if (mb_strlen($paragraph) > self::CHUNK_CHARS) {
                    foreach (mb_str_split($paragraph, self::CHUNK_CHARS) as $piece) {
                        $chunks[] = $piece;
                    }
                    $buffer = '';
                } else {
                    $buffer = $paragraph;
                }
            } else {
                $buffer = $candidate;
            }

            if (count($chunks) >= self::MAX_CHUNKS) {
                break;
            }
        }

        if ($buffer !== '' && count($chunks) < self::MAX_CHUNKS) {
            $chunks[] = $buffer;
        }

        return array_slice($chunks, 0, self::MAX_CHUNKS);
    }

    private function summary(string $text): string
    {
        $clipped = mb_substr($text, 0, 240);

        if (mb_strlen($text) > 240) {
            $clipped .= '…';
        }

        return $clipped;
    }

    /**
     * Pull readable text out of meta tags: og:title, og:description,
     * twitter:title, twitter:description, meta name=description. Dedup
     * to avoid the same line three times when the site sets all of
     * description/og:description/twitter:description identically.
     */
    private function extractMetaText(string $html): string
    {
        $patterns = [
            '/<meta\s+name=["\']description["\']\s+content=["\']([^"\']+)["\']/i',
            '/<meta\s+property=["\']og:title["\']\s+content=["\']([^"\']+)["\']/i',
            '/<meta\s+property=["\']og:description["\']\s+content=["\']([^"\']+)["\']/i',
            '/<meta\s+name=["\']twitter:title["\']\s+content=["\']([^"\']+)["\']/i',
            '/<meta\s+name=["\']twitter:description["\']\s+content=["\']([^"\']+)["\']/i',
        ];

        $hits = [];
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $html, $m)) {
                $hits[] = html_entity_decode(trim($m[1]), ENT_QUOTES | ENT_HTML5);
            }
        }

        $unique = array_values(array_unique(array_filter($hits)));

        return implode("\n", $unique);
    }

    /**
     * Pull readable text out of inline JSON blocks shipped by SPA
     * frameworks: Inertia ships a `<script data-page="app"
     * type="application/json">` payload that contains the entire page
     * props graph; sites with structured data ship `<script
     * type="application/ld+json">` blocks. Walk those JSON trees and
     * collect every string value long enough to be prose (≥ 24 chars).
     */
    private function extractStructuredText(string $html): string
    {
        $scripts = [];

        if (preg_match_all('/<script[^>]*type=["\']application\/json["\'][^>]*>(.*?)<\/script>/is', $html, $m)) {
            $scripts = array_merge($scripts, $m[1]);
        }

        if (preg_match_all('/<script[^>]*type=["\']application\/ld\+json["\'][^>]*>(.*?)<\/script>/is', $html, $m)) {
            $scripts = array_merge($scripts, $m[1]);
        }

        $strings = [];

        foreach ($scripts as $raw) {
            $json = html_entity_decode((string) $raw, ENT_QUOTES | ENT_HTML5);
            $decoded = json_decode($json, true);

            if (! is_array($decoded)) {
                continue;
            }

            $this->collectStrings($decoded, $strings);
        }

        $unique = array_values(array_unique(array_filter($strings, fn ($s) => mb_strlen($s) >= 24)));

        return implode("\n", array_slice($unique, 0, 120));
    }

    /**
     * Walk a decoded JSON tree depth-first; append any prose-shaped
     * string leaves to $out. Skips URLs, locale codes, hex colors, and
     * pure number strings since those add noise without informing the
     * demo answer.
     *
     * @param  mixed  $node
     * @param  array<int, string>  $out
     */
    private function collectStrings($node, array &$out): void
    {
        if (is_string($node)) {
            $trimmed = trim($node);

            if ($trimmed === '') {
                return;
            }
            if (preg_match('~^(https?://|/|\#|\d+$|[a-z]{2}(-[a-z]{2})?$|0x[0-9a-f]+$)~i', $trimmed)) {
                return;
            }
            if (mb_strlen($trimmed) > 400) {
                $trimmed = mb_substr($trimmed, 0, 400);
            }
            $out[] = $trimmed;

            return;
        }

        if (is_array($node)) {
            foreach ($node as $value) {
                $this->collectStrings($value, $out);
            }
        }
    }
}
