<?php

namespace App\Services\Rag;

class Chunker
{
    /**
     * Split text into semantic chunks targeting ~$targetTokens each, with
     * $overlapTokens of trailing context shared between adjacent chunks.
     *
     * Strategy (LangChain-style recursive splitter):
     *   1. Pre-split on heading markers (\n#, \n##) so a chunk doesn't span sections.
     *   2. Pre-split on blank lines so paragraphs stay together.
     *   3. Pack paragraphs greedily until adding the next one would exceed targetChars.
     *   4. If a single paragraph is itself too long, fall back to sentence-level
     *      splitting (regex on .!? boundaries) and pack sentences the same way.
     *   5. If a single sentence is still too long, hard-split at character
     *      boundaries (last resort — pathological inputs only).
     *   6. Apply trailing-overlap: prepend the last $overlapChars of chunk N
     *      to the start of chunk N+1 so cross-chunk facts (a price spanning
     *      two paragraphs) survive retrieval.
     *
     * Token approximation: 1 token ≈ 4 chars (OpenAI rule of thumb, close
     * enough for bge-base too).
     *
     * @return array<int, string>
     */
    public function chunk(string $text, int $targetTokens = 500, int $overlapTokens = 50): array
    {
        $text = $this->normalize($text);
        if ($text === '') {
            return [];
        }

        $targetChars = max(200, $targetTokens * 4);
        $overlapChars = max(0, $overlapTokens * 4);

        $sections = $this->splitSections($text);

        $chunks = [];
        foreach ($sections as $section) {
            $sectionChunks = $this->packParagraphs(
                $this->splitParagraphs($section),
                $targetChars
            );
            foreach ($sectionChunks as $c) {
                $chunks[] = $c;
            }
        }

        return $this->applyOverlap($chunks, $overlapChars);
    }

    /**
     * Light-touch normalization. Unlike the old chunker we DO NOT collapse
     * \n into spaces — paragraph breaks are load-bearing for chunking.
     */
    private function normalize(string $text): string
    {
        // Normalise CRLF / CR to LF.
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        // Trim trailing whitespace per line, collapse repeats of >2 newlines.
        $text = preg_replace('/[ \t]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/\n{3,}/u', "\n\n", $text) ?? $text;

        return trim($text);
    }

    /** @return array<int, string> */
    private function splitSections(string $text): array
    {
        // Markdown-style headings: lines starting with # / ## / ###.
        $parts = preg_split('/(?:^|\n)#{1,6}\s+[^\n]*\n/u', $text, -1, PREG_SPLIT_NO_EMPTY);

        return $parts === false || $parts === [] ? [$text] : array_map('trim', $parts);
    }

    /** @return array<int, string> */
    private function splitParagraphs(string $text): array
    {
        $parts = preg_split('/\n{2,}/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [$text];

        return array_values(array_filter(array_map('trim', $parts), fn ($p) => $p !== ''));
    }

    /**
     * @param  array<int, string>  $paragraphs
     * @return array<int, string>
     */
    private function packParagraphs(array $paragraphs, int $targetChars): array
    {
        $chunks = [];
        $buffer = '';

        foreach ($paragraphs as $p) {
            if (mb_strlen($p) > $targetChars) {
                if ($buffer !== '') {
                    $chunks[] = $buffer;
                    $buffer = '';
                }
                foreach ($this->splitLongParagraph($p, $targetChars) as $piece) {
                    $chunks[] = $piece;
                }

                continue;
            }

            if ($buffer === '') {
                $buffer = $p;
            } elseif (mb_strlen($buffer) + 2 + mb_strlen($p) <= $targetChars) {
                $buffer .= "\n\n".$p;
            } else {
                $chunks[] = $buffer;
                $buffer = $p;
            }
        }

        if ($buffer !== '') {
            $chunks[] = $buffer;
        }

        return $chunks;
    }

    /** @return array<int, string> */
    private function splitLongParagraph(string $paragraph, int $targetChars): array
    {
        // Sentence boundaries: . ? ! followed by whitespace + uppercase /
        // start-of-line / digit. Keep terminator with the preceding sentence.
        $sentences = preg_split(
            '/(?<=[.!?])\s+(?=[A-Z০-৯0-9“"\'])/u',
            $paragraph,
            -1,
            PREG_SPLIT_NO_EMPTY
        );
        if ($sentences === false || count($sentences) <= 1) {
            // Single non-splittable run — fall back to char window.
            return $this->charWindow($paragraph, $targetChars);
        }

        $chunks = [];
        $buffer = '';
        foreach ($sentences as $s) {
            $s = trim($s);
            if ($s === '') {
                continue;
            }
            if (mb_strlen($s) > $targetChars) {
                if ($buffer !== '') {
                    $chunks[] = $buffer;
                    $buffer = '';
                }
                foreach ($this->charWindow($s, $targetChars) as $piece) {
                    $chunks[] = $piece;
                }

                continue;
            }
            if ($buffer === '') {
                $buffer = $s;
            } elseif (mb_strlen($buffer) + 1 + mb_strlen($s) <= $targetChars) {
                $buffer .= ' '.$s;
            } else {
                $chunks[] = $buffer;
                $buffer = $s;
            }
        }
        if ($buffer !== '') {
            $chunks[] = $buffer;
        }

        return $chunks;
    }

    /** @return array<int, string> */
    private function charWindow(string $text, int $size): array
    {
        $out = [];
        $len = mb_strlen($text);
        for ($i = 0; $i < $len; $i += $size) {
            $piece = trim(mb_substr($text, $i, $size));
            if ($piece !== '') {
                $out[] = $piece;
            }
        }

        return $out;
    }

    /**
     * @param  array<int, string>  $chunks
     * @return array<int, string>
     */
    private function applyOverlap(array $chunks, int $overlapChars): array
    {
        if ($overlapChars <= 0 || count($chunks) <= 1) {
            return array_values($chunks);
        }

        $out = [$chunks[0]];
        for ($i = 1; $i < count($chunks); $i++) {
            $prev = $chunks[$i - 1];
            $tail = mb_substr($prev, max(0, mb_strlen($prev) - $overlapChars));
            // Don't duplicate if overlap is the entire prev chunk.
            if (mb_strlen($tail) >= mb_strlen($chunks[$i])) {
                $out[] = $chunks[$i];

                continue;
            }
            $out[] = trim($tail).' '.$chunks[$i];
        }

        return $out;
    }
}
