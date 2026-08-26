<?php

namespace App\Services\Crawl;

use App\Services\Crawl\Contracts\Crawler;

/**
 * Last-resort crawler tier. Used only when every HTML-based tier
 * (plain HTTP, CF /content, CF /markdown) produced text under the
 * empty-body threshold — most often pages that render content via
 * canvas, all-image landing layouts, embedded PDF viewers, slide
 * decks, or extremely image-heavy SPAs.
 *
 * Two-stage pipeline:
 *   1. Take a full-page screenshot via Cloudflare Browser Rendering.
 *   2. Send the PNG to a Workers AI vision model (Llama 3.2 11B Vision)
 *      with an OCR prompt — returns the extracted text.
 *
 * We wrap the extracted text in a minimal HTML envelope so the rest of
 * the crawl pipeline (ReadabilityExtractor → chunker → embedder)
 * stays unchanged. Each line of the OCR output becomes its own `<p>`
 * so paragraph-level chunking holds.
 */
class CloudflareVisionCrawler implements Crawler
{
    public function __construct(
        private readonly CloudflareBrowserScreenshotClient $screenshots,
        private readonly CloudflareVisionClient $vision,
    ) {}

    public function content(string $url, array $opts = []): string
    {
        $shot = $this->screenshots->screenshot($url, $opts);
        $text = $this->vision->extractText($shot['bytes'], $shot['mime']);

        if (trim($text) === '') {
            throw new \RuntimeException("Cloudflare vision tier extracted no text from {$url}.");
        }

        return $this->wrapTextAsHtml($text);
    }

    /**
     * Pack extracted text into a minimal HTML document so downstream
     * extractors handle it like any other crawl response. Each line of
     * OCR output becomes its own `<p>` so the chunker doesn't smush
     * unrelated sentences together when the screenshot captures a
     * layout with discrete blocks.
     */
    private function wrapTextAsHtml(string $text): string
    {
        $lines = preg_split('/\r?\n\r?\n+/u', trim($text)) ?: [];
        $paragraphs = '';
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $paragraphs .= '<p>'.htmlspecialchars($line, ENT_QUOTES | ENT_HTML5, 'UTF-8').'</p>';
        }

        if ($paragraphs === '') {
            // Fall back to raw text in a single paragraph rather than
            // returning empty HTML — keeps the chain's text-length
            // check meaningful even on single-paragraph captures.
            $paragraphs = '<p>'.htmlspecialchars($text, ENT_QUOTES | ENT_HTML5, 'UTF-8').'</p>';
        }

        return "<!DOCTYPE html><html><head><meta charset=\"utf-8\"></head><body><article data-vision-ocr=\"true\">{$paragraphs}</article></body></html>";
    }
}
