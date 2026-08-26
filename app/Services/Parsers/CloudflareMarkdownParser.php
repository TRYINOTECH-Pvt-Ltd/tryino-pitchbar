<?php

namespace App\Services\Parsers;

use App\Services\Cloudflare\ToMarkdownClient;
use App\Services\Parsers\Contracts\FileParser;

/**
 * File parser backed by Cloudflare Workers AI's `toMarkdown` endpoint.
 * Sits in front of the in-process PHP parsers (Smalot for PDF,
 * PhpWord for DOCX, …) whenever Cloudflare creds are configured so
 * we get structured-markdown output instead of best-effort plain
 * text. Free of cost — the document formats we route here consume 0
 * Workers AI Neurons.
 *
 * Network failures bubble up as parser exceptions; UploadController
 * already records those per-file in `source.error` and the in-process
 * fallback is wired by ParserRegistry constructor (CF parser isn't
 * registered at all when no token is present).
 *
 * .csv stays on League\Csv (row-by-row output reads better in chat
 * context than CF's table-cell markdown), .md / .txt stay on
 * TextParser (no point round-tripping plain text).
 */
class CloudflareMarkdownParser implements FileParser
{
    /**
     * Extensions we know toMarkdown handles well AND for which our
     * in-process fallback is fragile. Keep this list narrow: every
     * extension here is a network round-trip that the local fallback
     * could have handled.
     */
    private const EXTENSIONS = ['pdf', 'docx', 'doc', 'xlsx', 'xls', 'odt', 'ods'];

    public function __construct(private readonly ToMarkdownClient $client) {}

    public function supports(string $extension): bool
    {
        return in_array(strtolower($extension), self::EXTENSIONS, true);
    }

    public function parse(string $bytes, string $filename): array
    {
        $markdown = trim($this->client->convert($bytes, $filename));
        if ($markdown === '') {
            return [];
        }

        // Split the converted document on H1/H2 headings so each section
        // becomes its own segment downstream. Mirrors TextParser so the
        // chunking and retrieval behaviour is consistent regardless of
        // which parser produced the markdown.
        $parts = preg_split('/\n(?=#{1,2}\s)/m', $markdown);
        if ($parts === false) {
            return [$markdown];
        }

        $segments = [];
        foreach ($parts as $part) {
            $trimmed = trim($part);
            if ($trimmed !== '') {
                $segments[] = $trimmed;
            }
        }

        return $segments;
    }
}
