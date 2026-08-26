<?php

namespace App\Services\Parsers\Contracts;

interface FileParser
{
    /**
     * Parse a file's bytes into one or more text segments.
     * Each segment will become a Document or chunk seed downstream.
     *
     * @return array<int, string>
     */
    public function parse(string $bytes, string $filename): array;

    public function supports(string $extension): bool;
}
