<?php

namespace App\Services\I18n;

class TranslationLoader
{
    /** @var array<string, array<string, string>> */
    private static array $cache = [];

    public function __construct(private readonly TranslationOverrides $overrides) {}

    /**
     * The strings shipped to the React SPA + widget for a locale: the
     * `lang/{locale}.json` file map with the admin's DB overrides merged
     * on top (overrides win). The file read is cached per Octane process;
     * the overrides carry their own version-busted shared cache so an
     * admin edit is reflected on the next request across every worker.
     *
     * @return array<string, string>
     */
    public function jsonFor(string $locale): array
    {
        return array_merge(
            $this->fileFor($locale),
            $this->overrides->forLocale($locale),
        );
    }

    /**
     * Resolve a single key for a locale, override first, then the file.
     * Returns null when neither has it (caller falls back to the key).
     */
    public function get(string $locale, string $key): ?string
    {
        $override = $this->overrides->forLocale($locale);
        if (array_key_exists($key, $override)) {
            return $override[$key];
        }

        $file = $this->fileFor($locale);

        return $file[$key] ?? null;
    }

    /**
     * The raw `lang/{locale}.json` map (no overrides), cached per Octane
     * process — the files don't change between requests.
     *
     * @return array<string, string>
     */
    public function fileFor(string $locale): array
    {
        if (isset(self::$cache[$locale])) {
            return self::$cache[$locale];
        }

        $path = base_path("lang/{$locale}.json");
        if (! is_file($path)) {
            return self::$cache[$locale] = [];
        }

        $raw = @file_get_contents($path);
        if ($raw === false) {
            return self::$cache[$locale] = [];
        }

        $decoded = json_decode($raw, true);

        return self::$cache[$locale] = is_array($decoded) ? $decoded : [];
    }

    /**
     * Drop the in-process file cache. Used by tests that mutate lang
     * files and by {@see TranslationOverrides::bump()} on a write.
     */
    public static function flush(): void
    {
        self::$cache = [];
    }
}
