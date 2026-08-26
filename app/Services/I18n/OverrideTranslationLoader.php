<?php

namespace App\Services\I18n;

use Illuminate\Translation\FileLoader;

/**
 * A drop-in for Laravel's {@see FileLoader} that merges the admin's
 * translation overrides onto the JSON string group, so the bare `__()`
 * / `trans()` helper (validation messages, mailables, Blade `@lang`) is
 * override-aware too — not just the React/widget paths that read through
 * {@see TranslationLoader}.
 *
 * Octane note: Laravel's Translator memoizes a loaded group per process,
 * so a freshly-written override reflects here on the next worker that
 * loads the locale (immediate without Octane / on worker recycle with
 * it). The React SPA, widget, and marketing surfaces read through
 * {@see TranslationLoader} and reflect the edit on the very next request.
 */
class OverrideTranslationLoader extends FileLoader
{
    public function load($locale, $group, $namespace = null)
    {
        $lines = parent::load($locale, $group, $namespace);

        // JSON translations (the `lang/{locale}.json` convention this app
        // uses) load under the `*` group + `*` namespace. PHP array files
        // (validation.php etc.) come through with a real group name and
        // are left untouched.
        if ($group === '*' && $namespace === '*') {
            $lines = array_merge($lines, app(TranslationOverrides::class)->forLocale($locale));
        }

        return $lines;
    }
}
