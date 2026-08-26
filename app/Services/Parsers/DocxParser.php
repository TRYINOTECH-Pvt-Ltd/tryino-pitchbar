<?php

namespace App\Services\Parsers;

use App\Services\Parsers\Contracts\FileParser;
use PhpOffice\PhpWord\IOFactory;

class DocxParser implements FileParser
{
    public function supports(string $extension): bool
    {
        return in_array(strtolower($extension), ['docx', 'doc'], true);
    }

    public function parse(string $bytes, string $filename): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'pb_doc_');
        file_put_contents($tmp, $bytes);

        try {
            $reader = IOFactory::createReader('Word2007');
            $doc = $reader->load($tmp);

            $segments = [];
            foreach ($doc->getSections() as $section) {
                $buf = '';
                foreach ($section->getElements() as $el) {
                    $buf .= self::extractText($el)."\n";
                }
                $buf = trim($buf);
                if ($buf !== '') {
                    $segments[] = $buf;
                }
            }

            return $segments;
        } finally {
            @unlink($tmp);
        }
    }

    private static function extractText(mixed $element): string
    {
        if (method_exists($element, 'getText') && is_string($element->getText())) {
            return $element->getText();
        }
        if (method_exists($element, 'getElements')) {
            $out = '';
            foreach ($element->getElements() as $child) {
                $out .= self::extractText($child).' ';
            }

            return $out;
        }

        return '';
    }
}
