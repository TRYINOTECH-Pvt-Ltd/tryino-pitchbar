<?php

namespace App\Services\I18n;

use App\Models\TranslationOverride;
use Illuminate\Support\Facades\Cache;

/**
 * Reads + writes the admin's translation overrides and exposes them as a
 * per-locale key→value map. Layered over the shipped lang/{locale}.json
 * files by {@see TranslationLoader} and {@see OverrideTranslationLoader}.
 *
 * Octane safety: the per-locale maps are cached in the SHARED cache keyed
 * by a monotonically increasing version int. A write bumps the version,
 * which invalidates every worker's view at once — a per-process static
 * cache alone would leave other workers serving stale strings forever.
 */
class TranslationOverrides
{
    private const VERSION_KEY = 'i18n_overrides_version';

    /**
     * The override map for one locale: key → value.
     *
     * @return array<string, string>
     */
    public function forLocale(string $locale): array
    {
        return Cache::rememberForever(
            "i18n_overrides:{$this->version()}:{$locale}",
            fn (): array => TranslationOverride::query()
                ->where('locale', $locale)
                ->pluck('value', 'key')
                ->all(),
        );
    }

    /**
     * Override counts per locale, e.g. ['nl' => 12, 'de' => 3].
     *
     * @return array<string, int>
     */
    public function countsByLocale(): array
    {
        return Cache::rememberForever(
            "i18n_overrides_counts:{$this->version()}",
            fn (): array => TranslationOverride::query()
                ->selectRaw('locale, COUNT(*) as aggregate')
                ->groupBy('locale')
                ->pluck('aggregate', 'locale')
                ->map(fn ($n): int => (int) $n)
                ->all(),
        );
    }

    /**
     * Upsert one override and invalidate caches.
     */
    public function put(string $locale, string $key, string $value, ?int $userId = null): void
    {
        TranslationOverride::query()->updateOrCreate(
            ['locale' => $locale, 'key_sha1' => sha1($key)],
            ['key' => $key, 'value' => $value, 'updated_by' => $userId],
        );

        $this->bump();
    }

    /**
     * Remove one override (reset back to the shipped file value).
     */
    public function forget(string $locale, string $key): void
    {
        TranslationOverride::query()
            ->where('locale', $locale)
            ->where('key_sha1', sha1($key))
            ->delete();

        $this->bump();
    }

    /**
     * Bump the version so every Octane worker re-reads, and drop the
     * file loader's in-process cache so a merged view rebuilds.
     */
    public function bump(): void
    {
        if (Cache::get(self::VERSION_KEY) === null) {
            Cache::forever(self::VERSION_KEY, 0);
        }

        Cache::increment(self::VERSION_KEY);
        TranslationLoader::flush();
    }

    private function version(): int
    {
        return (int) Cache::get(self::VERSION_KEY, 0);
    }
}
