<?php

namespace App\Services\Parsers;

use App\Services\Parsers\Contracts\FileParser;
use Smalot\PdfParser\Document;
use Smalot\PdfParser\Parser as Smalot;

/**
 * PDF → plain-text extractor backed by Smalot. Buyer report: uploaded
 * a PDF, every Document landed at 0 chunks. Root cause for that class
 * of failure is Smalot's per-page extraction returning empty strings
 * for PDFs whose text lives in a single content stream that doesn't
 * map cleanly onto page objects (common for export-from-Word PDFs).
 *
 * Strategy now:
 *   1. Try per-page extraction (preserves natural document chunking).
 *   2. If every page came back empty, fall back to the full-document
 *      text Smalot exposes via $pdf->getText() and emit it as one
 *      segment — better than 0 chunks.
 *   3. Sanitize every segment to drop binary control bytes and PDF
 *      stream artifacts ("/Type /Page", null bytes, NUL-padded
 *      sequences) that some PDFs leak into getText() output. If the
 *      cleaned segment is still mostly garbage characters, drop it.
 *   4. Return empty list when neither strategy produced usable text —
 *      the UploadController catches that and flags the file with a
 *      "no extractable text" warning instead of pretending to index.
 */
class PdfParser implements FileParser
{
    /**
     * Below this ratio of printable-text-to-total-chars, treat the
     * segment as binary noise (scanned PDF with embedded image-only
     * pages whose getText() returns Adobe stream gibberish).
     */
    private const MIN_PRINTABLE_RATIO = 0.75;

    public function supports(string $extension): bool
    {
        return strtolower($extension) === 'pdf';
    }

    public function parse(string $bytes, string $filename): array
    {
        $parser = new Smalot;
        $pdf = $parser->parseContent($bytes);

        $segments = [];
        foreach ($pdf->getPages() as $page) {
            $raw = (string) $page->getText();
            $clean = $this->sanitize($raw);
            if ($clean !== '') {
                $segments[] = $clean;
            }
        }

        if ($segments !== []) {
            return $segments;
        }

        // Per-page got us nothing — try the full-document text. Some
        // export-from-Word PDFs put everything in a single content
        // stream and Smalot's per-page split eats the body.
        $whole = $this->sanitize((string) $pdf->getText());
        if ($whole !== '') {
            return [$whole];
        }

        // Detect scanned (image-only) PDFs so the caller can stamp a
        // specific error message instead of the generic "no chunks".
        // Heuristic: per-page text was empty AND $pdf->getDetails()
        // shows no fonts → almost certainly a flatbed scan or a PDF
        // produced from images (Word "Save as PDF" of a photo). The
        // caller surfaces the OCR-not-supported hint + paste-text CTA.
        if ($this->looksScanned($pdf, $bytes)) {
            throw new \RuntimeException(
                'PDF appears to be scanned (image-only, no text layer). OCR is not yet supported — paste the content as text via /sources instead.'
            );
        }

        return [];
    }

    /**
     * @param  Document  $pdf
     */
    private function looksScanned($pdf, string $bytes): bool
    {
        try {
            $fonts = $pdf->getFonts();
        } catch (\Throwable) {
            $fonts = [];
        }

        if (! empty($fonts)) {
            return false;
        }

        // Cheap secondary signal: presence of XObject Image streams in
        // the raw bytes. Real text-bearing PDFs may include images but
        // also embed fonts; scanned PDFs have images and no fonts.
        return str_contains($bytes, '/Subtype /Image') || str_contains($bytes, '/Subtype/Image');
    }

    /**
     * Smalot is honest about what's in the PDF — when it can't decode
     * a font / encoding it returns the raw stream bytes. Strip those,
     * then bail out if what's left is mostly non-printable noise.
     */
    private function sanitize(string $text): string
    {
        // Drop NUL bytes + other C0 control codes EXCEPT the whitespace
        // ones we want to keep (tab, LF, CR).
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $text) ?? $text;

        // PDF stream tokens that some encoders leak — anything between
        // a PDF operator marker like "/Type /Page" or "BT … ET" is
        // metadata, not content. Strip the most common offenders.
        $text = preg_replace('/\/[A-Za-z]+\s+\/[A-Za-z]+/u', '', $text) ?? $text;
        $text = preg_replace('/\bBT\b.*?\bET\b/su', '', $text) ?? $text;

        // Collapse runs of whitespace introduced by the substitutions.
        $text = preg_replace('/[ \t]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/\n{3,}/u', "\n\n", $text) ?? $text;

        $text = trim($text);
        if ($text === '') {
            return '';
        }

        // Reject mostly-binary noise. Count printable chars (letters,
        // digits, punctuation, whitespace) and require at least 75%.
        $total = mb_strlen($text);
        if ($total < 20) {
            // Tiny extractions are almost always page numbers / single
            // header words — drop them. The caller treats no segments
            // as "no extractable text" and tells the operator to OCR.
            return '';
        }

        $printable = preg_match_all('/[\p{L}\p{N}\p{P}\p{Z}\s]/u', $text) ?: 0;
        if ($printable / $total < self::MIN_PRINTABLE_RATIO) {
            return '';
        }

        return $text;
    }
}
