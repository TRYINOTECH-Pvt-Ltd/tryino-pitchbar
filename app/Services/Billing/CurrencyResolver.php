<?php

namespace App\Services\Billing;

use Illuminate\Http\Request;

/**
 * Picks the currency a visitor should see on the pricing page.
 *
 * Resolution order:
 *   1. Explicit `?currency=eur` query string (visitor toggled the
 *      currency picker)
 *   2. Workspace's `preferred_currency` (when authenticated + in a
 *      workspace context)
 *   3. CF-IPCountry header → ISO 4217 code mapping
 *   4. Accept-Language → primary-locale → ISO 4217 mapping
 *   5. Fallback: USD
 *
 * The resolver never picks a currency that isn't in the plan's
 * `prices` JSON map — if the visitor's resolved currency has no entry
 * we fall through to the next step.
 */
final class CurrencyResolver
{
    /** @var array<string, string> CF-IPCountry → ISO 4217 */
    private const COUNTRY_TO_CURRENCY = [
        'US' => 'USD', 'CA' => 'CAD', 'MX' => 'MXN', 'BR' => 'BRL',
        'GB' => 'GBP', 'IE' => 'EUR', 'DE' => 'EUR', 'FR' => 'EUR',
        'ES' => 'EUR', 'IT' => 'EUR', 'PT' => 'EUR', 'NL' => 'EUR',
        'BE' => 'EUR', 'AT' => 'EUR', 'FI' => 'EUR', 'GR' => 'EUR',
        'CH' => 'CHF', 'NO' => 'NOK', 'SE' => 'SEK', 'DK' => 'DKK',
        'IS' => 'ISK', 'PL' => 'PLN', 'CZ' => 'CZK', 'HU' => 'HUF',
        'RO' => 'RON', 'BG' => 'BGN', 'HR' => 'HRK', 'RS' => 'RSD',
        'UA' => 'UAH', 'RU' => 'RUB', 'KZ' => 'KZT', 'GE' => 'GEL', 'AM' => 'AMD',
        'AU' => 'AUD', 'NZ' => 'NZD', 'JP' => 'JPY', 'CN' => 'CNY',
        'HK' => 'HKD', 'TW' => 'TWD', 'SG' => 'SGD', 'KR' => 'KRW',
        'IN' => 'INR', 'BD' => 'BDT', 'PK' => 'PKR', 'NP' => 'NPR',
        'LK' => 'LKR', 'ID' => 'IDR', 'MY' => 'MYR', 'PH' => 'PHP',
        'TH' => 'THB', 'VN' => 'VND',
        'AE' => 'AED', 'SA' => 'SAR', 'TR' => 'TRY', 'IL' => 'ILS',
        'ZA' => 'ZAR', 'EG' => 'EGP', 'NG' => 'NGN', 'KE' => 'KES', 'MA' => 'MAD',
        'AR' => 'ARS', 'CL' => 'CLP', 'CO' => 'COP', 'PE' => 'PEN', 'UY' => 'UYU',
    ];

    /** @var array<string, string> Primary-language → ISO 4217 */
    private const LANG_TO_CURRENCY = [
        'en' => 'USD', 'de' => 'EUR', 'fr' => 'EUR', 'es' => 'EUR',
        'it' => 'EUR', 'pt' => 'EUR', 'nl' => 'EUR', 'ja' => 'JPY',
        'zh' => 'CNY', 'ko' => 'KRW', 'ar' => 'AED', 'hi' => 'INR',
        'bn' => 'BDT', 'tr' => 'TRY', 'ru' => 'RUB', 'pl' => 'PLN',
        'cs' => 'CZK', 'hu' => 'HUF', 'el' => 'EUR', 'sv' => 'SEK',
        'no' => 'NOK', 'fi' => 'EUR', 'da' => 'DKK',
    ];

    /**
     * @param  array<int, string>  $availableCurrencies  Plan-supported codes (lowercase)
     */
    public function resolve(Request $request, array $availableCurrencies): string
    {
        $available = array_map('strtolower', $availableCurrencies);
        if ($available === []) {
            return 'usd';
        }

        // 1. Explicit visitor toggle.
        $explicit = strtolower((string) $request->query('currency', ''));
        if ($explicit !== '' && in_array($explicit, $available, true)) {
            return $explicit;
        }

        // 2. Authenticated workspace preference.
        $user = $request->user();
        if ($user !== null) {
            $workspace = method_exists($user, 'defaultWorkspace')
                ? $user->defaultWorkspace
                : null;
            $pref = strtolower((string) ($workspace->preferred_currency ?? ''));
            if ($pref !== '' && in_array($pref, $available, true)) {
                return $pref;
            }
        }

        // 3. CF-IPCountry header (Cloudflare proxy ahead of us).
        $country = strtoupper((string) $request->header('CF-IPCountry', ''));
        if (isset(self::COUNTRY_TO_CURRENCY[$country])) {
            $code = strtolower(self::COUNTRY_TO_CURRENCY[$country]);
            if (in_array($code, $available, true)) {
                return $code;
            }
        }

        // 4. Accept-Language → primary locale.
        $accept = strtolower((string) $request->header('Accept-Language', ''));
        if ($accept !== '') {
            $primary = explode('-', explode(',', $accept)[0])[0] ?? '';
            $code = strtolower(self::LANG_TO_CURRENCY[$primary] ?? '');
            if ($code !== '' && in_array($code, $available, true)) {
                return $code;
            }
        }

        // 5. Fallback: USD if available, else first available currency.
        return in_array('usd', $available, true) ? 'usd' : $available[0];
    }
}
