<?php

namespace App\Services\Vertical;

use App\Support\SeoMeta;

/**
 * Enumerates every vertical preset's visitor-facing chrome strings — the
 * starter-prompt chips and the launcher label. Surfaced in the Translation
 * Manager (and filled by DeepL) so operators can localise them; the widget
 * /init endpoint then resolves each against the visitor's locale before
 * sending. Without this the preset defaults (e.g. the SaaS preset's
 * "What does it cost?" chips and "Ask about the product" launcher) render
 * in English on every locale because the widget prints them verbatim.
 *
 * Mirrors {@see SeoMeta::translatableStrings()}.
 */
class VerticalChrome
{
    /**
     * Every distinct starter-prompt + launcher-label string across all
     * presets, trimmed and deduped. Pure (no container/DB) so it is safe
     * to call from the translatable-source collector and tests alike.
     *
     * @return array<int, string>
     */
    public static function translatableStrings(): array
    {
        $registry = new VerticalPresetRegistry;
        $strings = [];

        foreach ($registry->all() as $preset) {
            foreach ($preset->starterPrompts() as $prompt) {
                $prompt = trim((string) $prompt);
                if ($prompt !== '') {
                    $strings[] = $prompt;
                }
            }

            $label = trim((string) ($preset->launcherLabel() ?? ''));
            if ($label !== '') {
                $strings[] = $label;
            }
        }

        return array_values(array_unique($strings));
    }
}
