<?php

namespace App\Services\Parsers;

use App\Services\Parsers\Contracts\FileParser;
use League\Csv\Reader;

class CsvParser implements FileParser
{
    public function supports(string $extension): bool
    {
        return strtolower($extension) === 'csv';
    }

    public function parse(string $bytes, string $filename): array
    {
        $csv = Reader::fromString($bytes);
        $csv->setHeaderOffset(0);

        $segments = [];
        $header = $csv->getHeader();
        foreach ($csv->getRecords() as $row) {
            $parts = [];
            foreach ($header as $col) {
                $val = $row[$col] ?? '';
                if ($val !== '' && $val !== null) {
                    $parts[] = "{$col}: {$val}";
                }
            }
            if ($parts !== []) {
                $segments[] = implode(' | ', $parts);
            }
        }

        return $segments;
    }
}
