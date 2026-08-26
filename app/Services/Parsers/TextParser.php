<?php

namespace App\Services\Parsers;

use App\Services\Parsers\Contracts\FileParser;

class TextParser implements FileParser
{
    public function supports(string $extension): bool
    {
        return in_array(strtolower($extension), ['txt', 'md', 'markdown'], true);
    }

    public function parse(string $bytes, string $filename): array
    {
        $text = trim($bytes);
        if ($text === '') {
            return [];
        }

        // Split markdown by H1/H2 headings; otherwise return as single segment.
        $parts = preg_split('/\n(?=#{1,2}\s)/m', $text) ?: [$text];

        return array_values(array_filter(array_map('trim', $parts)));
    }
}
