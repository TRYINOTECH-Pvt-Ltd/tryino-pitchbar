<?php

namespace App\Services\Widget;

/**
 * Parses RFC 7231 Accept-Language headers like:
 *   "en-US,en;q=0.9,fr;q=0.8,de;q=0.7"
 *
 * Returns the 2-letter primary subtag of the highest-quality language, or
 * null if the header is missing or unparseable. The widget calls this on
 * /v1/widget/init to set the conversation's language so the LLM responds in
 * the visitor's preferred tongue, regardless of what the source content is in.
 */
class AcceptLanguage
{
    /** Supported languages — kept in sync with the agent-form picker. */
    public const SUPPORTED = ['en', 'es', 'fr', 'de', 'pt', 'ja', 'ar', 'zh'];

    public static function detect(?string $header, ?string $fallback = null): ?string
    {
        if (! is_string($header) || $header === '') {
            return $fallback;
        }

        $best = null;
        $bestQ = -1.0;

        foreach (explode(',', $header) as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }

            // "fr;q=0.8" → ['fr', 'q=0.8']
            $segments = explode(';', $part);
            $tag = strtolower(trim($segments[0]));
            $primary = explode('-', $tag)[0]; // 'en-US' → 'en'

            if (! in_array($primary, self::SUPPORTED, true)) {
                continue;
            }

            $q = 1.0;
            foreach (array_slice($segments, 1) as $param) {
                if (preg_match('/^\s*q\s*=\s*([\d.]+)\s*$/', $param, $m) === 1) {
                    $q = (float) $m[1];
                    break;
                }
            }

            if ($q > $bestQ) {
                $best = $primary;
                $bestQ = $q;
            }
        }

        return $best ?? $fallback;
    }
}
