<?php

namespace App\Http\Controllers\Admin\Platform;

use App\Services\I18n\LocaleCatalog;
use App\Services\I18n\LocaleResolver;
use App\Services\I18n\TranslationLoader;
use App\Services\I18n\TranslationOverrides;
use App\Services\Vertical\VerticalChrome;
use App\Support\MarketingCopy;
use App\Support\SeoMeta;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Platform admin translation manager (/admin/translations, super_admin
 * only via the route group). Lets the operator edit the value of any
 * string in any shipped language and fill in missing strings, from the
 * UI. Edits are stored as DB overrides layered over lang/{locale}.json
 * by {@see TranslationOverrides}; the files stay the canonical key set.
 */
class TranslationController
{
    private const PER_PAGE = 50;

    public function __construct(
        private readonly LocaleResolver $locales,
        private readonly TranslationLoader $loader,
        private readonly TranslationOverrides $overrides,
    ) {}

    /**
     * The Translation Manager is part of the multilingual layer — when
     * MULTILINGUAL_ENABLED is off the site is English-only and the manager
     * is hidden, so every action 404s.
     */
    private function ensureEnabled(): void
    {
        abort_unless((bool) config('app.multilingual_enabled'), 404);
    }

    /**
     * The full set of translatable strings, keyed by their English source:
     * the shipped en.json key set PLUS the operator-editable marketing copy
     * (hero / pricing / FAQ) PLUS the SEO meta templates (per-route page
     * title + description). Neither marketing copy nor SEO meta are lang
     * keys, so without adding them here they couldn't be overridden and
     * would render in English on the public site. en.json wins on collision.
     *
     * @return array<string, string>
     */
    private function translatableSource(): array
    {
        $source = $this->loader->fileFor('en');

        foreach (MarketingCopy::strings() as $string) {
            if (! array_key_exists($string, $source)) {
                $source[$string] = $string;
            }
        }

        foreach (SeoMeta::translatableStrings() as $string) {
            if (! array_key_exists($string, $source)) {
                $source[$string] = $string;
            }
        }

        foreach (VerticalChrome::translatableStrings() as $string) {
            if (! array_key_exists($string, $source)) {
                $source[$string] = $string;
            }
        }

        return $source;
    }

    /**
     * Locale picker with per-language progress (translated / total) +
     * missing and overridden counts.
     */
    public function index(): Response
    {
        $this->ensureEnabled();

        $supported = $this->locales->supported();
        $catalog = LocaleCatalog::hydrate($supported);
        $sourceKeys = array_keys($this->translatableSource());
        $total = count($sourceKeys);
        $overrideCounts = $this->overrides->countsByLocale();

        $rows = [];
        foreach ($supported as $code) {
            $resolved = $this->loader->jsonFor($code);
            $translated = 0;
            foreach ($sourceKeys as $key) {
                if (($resolved[$key] ?? '') !== '') {
                    $translated++;
                }
            }

            $rows[] = [
                'code' => $code,
                'native' => $catalog[$code]['native'] ?? strtoupper($code),
                'flag' => $catalog[$code]['flag'] ?? '🌐',
                'rtl' => (bool) ($catalog[$code]['rtl'] ?? false),
                'translated' => $translated,
                'total' => $total,
                'missing' => max(0, $total - $translated),
                'overrides' => (int) ($overrideCounts[$code] ?? 0),
                'is_source' => $code === 'en',
            ];
        }

        return Inertia::render('admin/translations/index', [
            'locales' => $rows,
            'total_keys' => $total,
        ]);
    }

    /**
     * Per-locale editor. Server-side search + filter + pagination over
     * the canonical English key set.
     */
    public function show(Request $request, string $locale): Response
    {
        $this->ensureEnabled();

        abort_unless(in_array($locale, $this->locales->supported(), true), 404);

        $catalog = LocaleCatalog::hydrate([$locale]);
        $source = $this->translatableSource();
        $file = $this->loader->fileFor($locale);
        $override = $this->overrides->forLocale($locale);

        $search = trim((string) $request->query('search', ''));
        $filter = (string) $request->query('filter', 'all'); // all | missing | overridden
        $page = max(1, (int) $request->query('page', 1));

        $needle = mb_strtolower($search);
        $matched = [];
        foreach ($source as $key => $sourceValue) {
            $hasOverride = array_key_exists($key, $override);
            $value = $hasOverride ? $override[$key] : ($file[$key] ?? '');
            $isMissing = ! $hasOverride && ($file[$key] ?? '') === '';

            if ($filter === 'missing' && ! $isMissing) {
                continue;
            }
            if ($filter === 'overridden' && ! $hasOverride) {
                continue;
            }
            if ($needle !== ''
                && ! str_contains(mb_strtolower((string) $key), $needle)
                && ! str_contains(mb_strtolower((string) $value), $needle)
                && ! str_contains(mb_strtolower((string) $sourceValue), $needle)
            ) {
                continue;
            }

            $matched[] = [
                'key' => (string) $key,
                'source' => (string) $sourceValue,
                'value' => (string) $value,
                'is_missing' => $isMissing,
                'is_overridden' => $hasOverride,
            ];
        }

        $totalMatched = count($matched);
        $lastPage = max(1, (int) ceil($totalMatched / self::PER_PAGE));
        $page = min($page, $lastPage);
        $rows = array_slice($matched, ($page - 1) * self::PER_PAGE, self::PER_PAGE);

        // Whole-locale tallies (independent of the current filter/search).
        $missingTotal = 0;
        foreach ($source as $key => $_) {
            if (! array_key_exists($key, $override) && ($file[$key] ?? '') === '') {
                $missingTotal++;
            }
        }

        return Inertia::render('admin/translations/edit', [
            'locale' => [
                'code' => $locale,
                'native' => $catalog[$locale]['native'] ?? strtoupper($locale),
                'flag' => $catalog[$locale]['flag'] ?? '🌐',
                'rtl' => (bool) ($catalog[$locale]['rtl'] ?? false),
                'is_source' => $locale === 'en',
            ],
            'rows' => $rows,
            'filters' => [
                'search' => $search,
                'filter' => in_array($filter, ['all', 'missing', 'overridden'], true) ? $filter : 'all',
            ],
            'pagination' => [
                'page' => $page,
                'last_page' => $lastPage,
                'per_page' => self::PER_PAGE,
                'total' => $totalMatched,
            ],
            'stats' => [
                'total' => count($source),
                'missing' => $missingTotal,
                'overrides' => count($override),
            ],
        ]);
    }

    /**
     * Upsert one override.
     */
    public function update(Request $request, string $locale): RedirectResponse
    {
        $this->ensureEnabled();

        abort_unless(in_array($locale, $this->locales->supported(), true), 404);

        $data = $request->validate([
            'key' => ['required', 'string', 'max:2000'],
            'value' => ['required', 'string', 'max:5000'],
        ]);

        // Editable keys = shipped en.json keys + the marketing copy strings.
        // An unknown key would never be read, so reject it.
        abort_unless(array_key_exists($data['key'], $this->translatableSource()), 422);

        $this->overrides->put($locale, $data['key'], $data['value'], $request->user()?->id);

        return back()->with('success', 'Translation saved.');
    }

    /**
     * Reset one override back to the shipped file value.
     */
    public function destroy(Request $request, string $locale): RedirectResponse
    {
        $this->ensureEnabled();

        abort_unless(in_array($locale, $this->locales->supported(), true), 404);

        $key = (string) Arr::get($request->validate([
            'key' => ['required', 'string', 'max:2000'],
        ]), 'key');

        $this->overrides->forget($locale, $key);

        return back()->with('success', 'Translation reset to the shipped default.');
    }
}
