<?php

namespace App\Services\I18n;

use Illuminate\Http\Request;
use Illuminate\Support\Str;

class LocaleResolver
{
    /**
     * Per-instance memo for the directory scan. Keeping this on the
     * instance (resolver is bound `scoped` per-request via Octane) means
     * scandir runs at most once per request even when the resolver is
     * called from middleware + sharing + suggestion all in the same
     * cycle. Cleared at the end of each request when Octane flushes
     * the container scope.
     *
     * @var array<int, string>|null
     */
    private ?array $supportedMemo = null;

    public const DEFAULT = 'en';

    /**
     * ISO 3166-1 alpha-2 country → locale slug map. Drives the
     * geo-suggested locale banner (#49). Only countries we have
     * translations for appear here — visitors from anywhere else
     * get no suggestion and stay on English.
     *
     * Latin American Spanish-speaking countries route to `es`;
     * European/Quebec French to `fr`; Turkey to `tr`. Anything
     * unmapped → no suggestion.
     */
    public const COUNTRY_TO_LOCALE = [
        // Spanish
        'ES' => 'es', 'MX' => 'es', 'AR' => 'es', 'CO' => 'es',
        'CL' => 'es', 'PE' => 'es', 'VE' => 'es', 'UY' => 'es',
        'EC' => 'es', 'BO' => 'es', 'CR' => 'es', 'CU' => 'es',
        'DO' => 'es', 'GT' => 'es', 'HN' => 'es', 'NI' => 'es',
        'PA' => 'es', 'PY' => 'es', 'SV' => 'es', 'PR' => 'es',
        // French
        'FR' => 'fr', 'BE' => 'fr', 'LU' => 'fr', 'MC' => 'fr',
        'CH' => 'fr', // Swiss French is the largest non-German cohort
        // Turkish
        'TR' => 'tr',
    ];

    /**
     * Cookie name that stores the visitor's "no thanks" choice. 180-day
     * lifetime — long enough that we don't pester anyone, short enough
     * that a returning visitor on a fresh device still gets the offer.
     */
    public const DISMISS_COOKIE = 'pb_locale_dismiss';

    /**
     * Resolve which locale should be active for a given request.
     *
     * Priority:
     *   1. Explicit `?locale=` query (only if allow-listed)
     *   2. Authenticated user's saved `locale` column
     *   3. `pb_locale` cookie (set after geo-banner switch or unauth
     *      switch path — survives across sessions for non-signed-in
     *      visitors).
     *   4. `Accept-Language` header (highest q-value match)
     *   5. App default
     */
    public function forRequest(Request $request): string
    {
        $explicit = $request->query('locale');
        if (is_string($explicit) && $this->isSupported($explicit)) {
            return $explicit;
        }

        $user = $request->user();
        if ($user !== null && is_string($user->locale ?? null) && $this->isSupported($user->locale)) {
            return $user->locale;
        }

        $cookie = $request->cookie('pb_locale');
        if (is_string($cookie) && $this->isSupported($cookie)) {
            return $cookie;
        }

        $accept = $request->header('Accept-Language');
        if (is_string($accept) && $accept !== '') {
            $resolved = $this->fromAcceptLanguage($accept);
            if ($resolved !== null) {
                return $resolved;
            }
        }

        return config('app.locale', self::DEFAULT);
    }

    /**
     * Resolve a locale for a widget visitor.
     *
     * Priority:
     *   1. `$explicit` — the host page's language, sent by the widget on
     *      /init (the embed's `data-locale`, else the page's `<html lang>`).
     *      This is "what language is this site in", so it wins over the
     *      agent's configured default — a widget on a Dutch page (or our
     *      own language-switched marketing site) follows the page instead
     *      of staying pinned to the agent's language. Matched on the exact
     *      tag, then its primary subtag ("en-GB" → "en").
     *   2. The agent's `language_default` (admin's configured default).
     *   3. `Accept-Language` header.
     *   4. App default ("en").
     *
     * Backwards compatible: callers that don't pass `$explicit` get the
     * prior agent-default-first behaviour.
     */
    public function forWidget(Request $request, ?string $agentDefault, ?string $explicit = null): string
    {
        $explicitResolved = $this->normaliseToSupported($explicit);
        if ($explicitResolved !== null) {
            return $explicitResolved;
        }

        if (is_string($agentDefault) && $this->isSupported($agentDefault)) {
            return $agentDefault;
        }

        $accept = $request->header('Accept-Language');
        if (is_string($accept) && $accept !== '') {
            $resolved = $this->fromAcceptLanguage($accept);
            if ($resolved !== null) {
                return $resolved;
            }
        }

        return self::DEFAULT;
    }

    /**
     * Resolve a raw locale tag to a supported locale, or null. Tries the
     * exact tag first, then its primary subtag, so a host page's regional
     * `<html lang>` ("en-GB", "pt-BR") degrades to a base language we ship
     * ("en", "pt") instead of being dropped.
     */
    private function normaliseToSupported(?string $tag): ?string
    {
        if (! is_string($tag)) {
            return null;
        }

        $tag = trim($tag);
        if ($tag === '') {
            return null;
        }

        if ($this->isSupported($tag)) {
            return $tag;
        }

        $primary = strtolower(Str::before($tag, '-'));

        return ($primary !== '' && $this->isSupported($primary)) ? $primary : null;
    }

    public function isSupported(string $locale): bool
    {
        return in_array($locale, $this->supported(), true);
    }

    /**
     * The locales the app actually ships translations for, derived by
     * scanning `lang/*.json`. Adding a new language is just dropping
     * a JSON file — no code change needed. English is guaranteed to
     * appear first (fallback contract); the rest are sorted
     * alphabetically by code.
     *
     * @return array<int, string>
     */
    public function supported(): array
    {
        if ($this->supportedMemo !== null) {
            return $this->supportedMemo;
        }

        $dir = base_path('lang');
        $codes = [];

        if (is_dir($dir)) {
            foreach ((array) scandir($dir) as $name) {
                if (! is_string($name) || ! str_ends_with($name, '.json')) {
                    continue;
                }
                $code = substr($name, 0, -5);
                if ($code === '' || $code === '_glossary' || str_starts_with($code, '_')) {
                    continue;
                }
                // BCP-47-ish: letters/digits/hyphen only. Reject odd
                // names so a stray editor backup file doesn't end up
                // in the picker.
                if (preg_match('/^[A-Za-z0-9-]{2,}$/', $code) !== 1) {
                    continue;
                }
                $codes[] = $code;
            }
        }

        sort($codes);
        // English always first so fallback semantics are obvious in
        // logs and so the Accept-Language tiebreaker has a stable winner.
        $codes = array_values(array_unique(array_merge(['en'], $codes)));

        return $this->supportedMemo = $codes;
    }

    /**
     * Decide whether to surface the "Switch to <locale>?" banner.
     *
     * Returns null when no banner should render. Suppression rules:
     *   1. The visitor already dismissed it (cookie set)
     *   2. The current locale already matches the suggested one
     *   3. We can't infer the country (no CF-IPCountry header, no
     *      Accept-Language clue)
     *   4. The country isn't in COUNTRY_TO_LOCALE (we don't ship
     *      translations for it yet)
     *
     * Non-null shape:
     *   ['suggested' => 'es', 'current' => 'en']
     *
     * @return array{suggested: string, current: string}|null
     */
    public function suggestionFor(Request $request, string $currentLocale): ?array
    {
        // The whole language layer is off — never suggest a switch.
        if (! config('app.multilingual_enabled')) {
            return null;
        }

        // Visitor opted out for this device.
        if ($request->cookie(self::DISMISS_COOKIE) !== null) {
            return null;
        }

        // Cloudflare adds CF-IPCountry on every request once the app
        // sits behind their CDN. Falls through cleanly when the header
        // is absent (local dev, non-CF deploys).
        $country = strtoupper((string) $request->header('CF-IPCountry', ''));

        // Some networks set 'XX' (unknown) or 'T1' (Tor). Treat as
        // unknown so we don't infer a wrong locale.
        if ($country === '' || $country === 'XX' || $country === 'T1') {
            return null;
        }

        $suggested = self::COUNTRY_TO_LOCALE[$country] ?? null;
        if ($suggested === null) {
            return null;
        }

        if ($suggested === $currentLocale) {
            return null;
        }

        return ['suggested' => $suggested, 'current' => $currentLocale];
    }

    /**
     * Pick the highest-q-value supported locale from an Accept-Language header.
     */
    private function fromAcceptLanguage(string $header): ?string
    {
        $best = null;
        $bestQ = -1.0;

        foreach (explode(',', $header) as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }

            $q = 1.0;
            $tag = $part;
            if (str_contains($part, ';')) {
                [$tag, $params] = array_pad(explode(';', $part, 2), 2, '');
                $tag = trim($tag);
                if (preg_match('/q\s*=\s*([0-9.]+)/i', $params, $m) === 1) {
                    $q = (float) $m[1];
                }
            }

            $primary = strtolower(Str::before($tag, '-'));
            if ($primary !== '' && $this->isSupported($primary) && $q > $bestQ) {
                $best = $primary;
                $bestQ = $q;
            }
        }

        return $best;
    }
}
