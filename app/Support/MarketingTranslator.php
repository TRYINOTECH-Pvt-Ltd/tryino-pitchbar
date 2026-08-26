<?php

namespace App\Support;

use App\Services\I18n\TranslationLoader;

/**
 * Localises the operator-editable marketing content payloads
 * (MarketingHomeContent / MarketingShellContent / pricing copy / FAQ
 * items) at render time.
 *
 * The payloads are DATA, not i18n keys — operators customise them in
 * the admin content editor — so the rule is: every string leaf is looked
 * up in the locale dictionary; when the string exists as a key in
 * lang/{locale}.json (true for all shipped English defaults) OR has an
 * admin override, it renders translated; any operator-customised string
 * has no dictionary entry and passes through verbatim. URLs, icon names,
 * prices, and numbers fall through the same way.
 *
 * Resolution goes through {@see TranslationLoader} (file + admin
 * overrides, override-aware and version-busted) rather than the bare
 * __() helper, so an admin edit reflects on the next request instead of
 * waiting for an Octane worker recycle.
 *
 * Applied at the controller render points only — the admin content
 * editor keeps showing the canonical English source.
 */
final class MarketingTranslator
{
    /**
     * @param  array<array-key, mixed>  $content
     * @return array<array-key, mixed>
     */
    public static function translate(array $content): array
    {
        $locale = app()->getLocale();

        // English is the source language of the defaults — walking the
        // tree would be a no-op, skip the work.
        if (str_starts_with($locale, 'en')) {
            return $content;
        }

        return self::walk($content, $locale, app(TranslationLoader::class));
    }

    /**
     * @param  array<array-key, mixed>  $node
     * @return array<array-key, mixed>
     */
    private static function walk(array $node, string $locale, TranslationLoader $loader): array
    {
        foreach ($node as $key => $value) {
            if (is_array($value)) {
                $node[$key] = self::walk($value, $locale, $loader);

                continue;
            }

            if (is_string($value) && $value !== '') {
                $node[$key] = $loader->get($locale, $value) ?? $value;
            }
        }

        return $node;
    }
}
