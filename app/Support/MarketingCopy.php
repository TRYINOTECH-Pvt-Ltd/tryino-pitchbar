<?php

namespace App\Support;

/**
 * Collects every translatable COPY string in the operator-editable marketing
 * content (home + pricing), so the Translation Manager + DeepL can localise
 * them. {@see MarketingTranslator} resolves each marketing string leaf
 * against the locale dictionary at render time, but the strings themselves
 * aren't shipped lang keys — the shipped defaults are inconsistent (some are
 * en.json keys, some aren't) and operator-customised copy never is. Without
 * surfacing them here they can't be translated and fall back to English
 * (the "employee voor support" mixed-language hero the buyer reported).
 *
 * Walks the RESOLVED content (defaults merged with the operator's edits) and
 * keeps only human copy — URLs, hrefs, icon names, colors, prices, interval
 * glyphs, and sentinels are skipped so they're never sent to DeepL.
 */
final class MarketingCopy
{
    /**
     * Leaf key segments that are never copy (matched case-insensitively;
     * substring for the URL/asset family, exact for the rest).
     */
    private const SKIP_KEY_SUBSTRINGS = ['href', 'url', 'icon', 'image', 'color', 'src'];

    private const SKIP_KEY_EXACT = ['slug', 'currency', 'interval', 'price', 'period', 'id', 'target', 'rel', 'theme', 'value'];

    /**
     * Every unique translatable marketing copy string, across home + pricing.
     *
     * @return array<int, string>
     */
    public static function strings(): array
    {
        $strings = [];

        self::walk(MarketingHomeContent::resolve(), $strings);

        // Pricing-page copy is a separate editor on installs that ship it
        // (guarded so this works wherever the class is absent).
        if (class_exists(MarketingPricingContent::class)) {
            self::walk(MarketingPricingContent::resolve(), $strings);
        }

        return array_values(array_unique($strings));
    }

    /**
     * @param  array<array-key, mixed>  $node
     * @param  array<int, string>  $out
     */
    private static function walk(array $node, array &$out): void
    {
        foreach ($node as $key => $value) {
            if (is_array($value)) {
                self::walk($value, $out);

                continue;
            }

            if (is_string($value) && self::isTranslatableCopy((string) $key, $value)) {
                $out[] = $value;
            }
        }
    }

    private static function isTranslatableCopy(string $key, string $value): bool
    {
        $value = trim($value);
        if ($value === '') {
            return false;
        }

        $lowerKey = strtolower($key);
        foreach (self::SKIP_KEY_SUBSTRINGS as $needle) {
            if (str_contains($lowerKey, $needle)) {
                return false;
            }
        }
        if (in_array($lowerKey, self::SKIP_KEY_EXACT, true)) {
            return false;
        }

        // URL / route / hex color / internal sentinel ("__primary__").
        if (preg_match('#^(https?://|/|\#|__)#', $value) === 1) {
            return false;
        }

        // Pure glyph/number values ("53%", "<1s", "24/7", "$199") — no letter
        // means nothing to translate.
        if (preg_match('/\p{L}/u', $value) !== 1) {
            return false;
        }

        return true;
    }
}
