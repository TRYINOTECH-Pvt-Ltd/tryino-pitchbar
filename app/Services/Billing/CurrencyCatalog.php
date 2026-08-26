<?php

namespace App\Services\Billing;

/**
 * ISO 4217 currency catalog. Sourced from Stripe's supported-currencies
 * list (the broadest of our three gateways) so the Plan editor can
 * surface every code an operator might want to sell in. Each entry
 * carries the display symbol, decimal places (so the UI knows whether
 * to render "₹1,234" vs "JP¥1234"), and which gateways accept it.
 *
 * Pricing inputs in the admin are always in minor units (cents-ish)
 * so the JSON shape stays integer + audit-friendly. The
 * `decimal_places` lookup lets the React form / pricing page render
 * human-readable values.
 */
final class CurrencyCatalog
{
    /**
     * @return array<int, array{code: string, name: string, symbol: string, decimal_places: int, gateways: array<int, string>}>
     */
    public static function all(): array
    {
        return self::raw();
    }

    /**
     * @return array<string, array{code: string, name: string, symbol: string, decimal_places: int, gateways: array<int, string>}>
     */
    public static function indexedByCode(): array
    {
        $out = [];
        foreach (self::raw() as $entry) {
            $out[strtolower($entry['code'])] = $entry;
        }

        return $out;
    }

    public static function find(string $code): ?array
    {
        return self::indexedByCode()[strtolower($code)] ?? null;
    }

    public static function decimalPlacesFor(string $code): int
    {
        return self::find($code)['decimal_places'] ?? 2;
    }

    public static function symbolFor(string $code): string
    {
        return self::find($code)['symbol'] ?? strtoupper($code);
    }

    /**
     * @return array<int, array{code: string, name: string, symbol: string, decimal_places: int, gateways: array<int, string>}>
     */
    private static function raw(): array
    {
        // Subset of ISO 4217 we actively support. Stripe supports
        // ~135, PayPal ~25, Razorpay ~100+. Each entry's `gateways`
        // list tells the Plan editor which gateways can SELL in that
        // currency so we warn early on incompatible combinations.
        return [
            ['code' => 'USD', 'name' => 'US Dollar', 'symbol' => '$', 'decimal_places' => 2, 'gateways' => ['stripe', 'paypal', 'razorpay']],
            ['code' => 'EUR', 'name' => 'Euro', 'symbol' => '€', 'decimal_places' => 2, 'gateways' => ['stripe', 'paypal']],
            ['code' => 'GBP', 'name' => 'British Pound', 'symbol' => '£', 'decimal_places' => 2, 'gateways' => ['stripe', 'paypal']],
            ['code' => 'CAD', 'name' => 'Canadian Dollar', 'symbol' => 'CA$', 'decimal_places' => 2, 'gateways' => ['stripe', 'paypal']],
            ['code' => 'AUD', 'name' => 'Australian Dollar', 'symbol' => 'A$', 'decimal_places' => 2, 'gateways' => ['stripe', 'paypal']],
            ['code' => 'NZD', 'name' => 'New Zealand Dollar', 'symbol' => 'NZ$', 'decimal_places' => 2, 'gateways' => ['stripe', 'paypal']],
            ['code' => 'JPY', 'name' => 'Japanese Yen', 'symbol' => 'JP¥', 'decimal_places' => 0, 'gateways' => ['stripe', 'paypal']],
            ['code' => 'CNY', 'name' => 'Chinese Yuan', 'symbol' => 'CN¥', 'decimal_places' => 2, 'gateways' => ['stripe']],
            ['code' => 'HKD', 'name' => 'Hong Kong Dollar', 'symbol' => 'HK$', 'decimal_places' => 2, 'gateways' => ['stripe', 'paypal']],
            ['code' => 'SGD', 'name' => 'Singapore Dollar', 'symbol' => 'S$', 'decimal_places' => 2, 'gateways' => ['stripe', 'paypal']],
            ['code' => 'TWD', 'name' => 'Taiwan Dollar', 'symbol' => 'NT$', 'decimal_places' => 2, 'gateways' => ['stripe', 'paypal']],
            ['code' => 'KRW', 'name' => 'South Korean Won', 'symbol' => '₩', 'decimal_places' => 0, 'gateways' => ['stripe']],
            ['code' => 'INR', 'name' => 'Indian Rupee', 'symbol' => '₹', 'decimal_places' => 2, 'gateways' => ['stripe', 'razorpay']],
            ['code' => 'BDT', 'name' => 'Bangladeshi Taka', 'symbol' => '৳', 'decimal_places' => 2, 'gateways' => ['stripe', 'razorpay']],
            ['code' => 'PKR', 'name' => 'Pakistani Rupee', 'symbol' => '₨', 'decimal_places' => 2, 'gateways' => ['stripe']],
            ['code' => 'NPR', 'name' => 'Nepalese Rupee', 'symbol' => 'रु', 'decimal_places' => 2, 'gateways' => ['stripe']],
            ['code' => 'LKR', 'name' => 'Sri Lankan Rupee', 'symbol' => 'Rs', 'decimal_places' => 2, 'gateways' => ['stripe']],
            ['code' => 'IDR', 'name' => 'Indonesian Rupiah', 'symbol' => 'Rp', 'decimal_places' => 2, 'gateways' => ['stripe', 'paypal']],
            ['code' => 'MYR', 'name' => 'Malaysian Ringgit', 'symbol' => 'RM', 'decimal_places' => 2, 'gateways' => ['stripe', 'paypal']],
            ['code' => 'PHP', 'name' => 'Philippine Peso', 'symbol' => '₱', 'decimal_places' => 2, 'gateways' => ['stripe', 'paypal']],
            ['code' => 'THB', 'name' => 'Thai Baht', 'symbol' => '฿', 'decimal_places' => 2, 'gateways' => ['stripe', 'paypal']],
            ['code' => 'VND', 'name' => 'Vietnamese Dong', 'symbol' => '₫', 'decimal_places' => 0, 'gateways' => ['stripe']],
            ['code' => 'AED', 'name' => 'UAE Dirham', 'symbol' => 'د.إ', 'decimal_places' => 2, 'gateways' => ['stripe', 'paypal']],
            ['code' => 'SAR', 'name' => 'Saudi Riyal', 'symbol' => '﷼', 'decimal_places' => 2, 'gateways' => ['stripe']],
            ['code' => 'TRY', 'name' => 'Turkish Lira', 'symbol' => '₺', 'decimal_places' => 2, 'gateways' => ['stripe', 'paypal']],
            ['code' => 'ILS', 'name' => 'Israeli Shekel', 'symbol' => '₪', 'decimal_places' => 2, 'gateways' => ['stripe', 'paypal']],
            ['code' => 'ZAR', 'name' => 'South African Rand', 'symbol' => 'R', 'decimal_places' => 2, 'gateways' => ['stripe']],
            ['code' => 'EGP', 'name' => 'Egyptian Pound', 'symbol' => 'E£', 'decimal_places' => 2, 'gateways' => ['stripe']],
            ['code' => 'NGN', 'name' => 'Nigerian Naira', 'symbol' => '₦', 'decimal_places' => 2, 'gateways' => ['stripe']],
            ['code' => 'KES', 'name' => 'Kenyan Shilling', 'symbol' => 'KSh', 'decimal_places' => 2, 'gateways' => ['stripe']],
            ['code' => 'MAD', 'name' => 'Moroccan Dirham', 'symbol' => 'DH', 'decimal_places' => 2, 'gateways' => ['stripe']],
            ['code' => 'MXN', 'name' => 'Mexican Peso', 'symbol' => 'MX$', 'decimal_places' => 2, 'gateways' => ['stripe', 'paypal']],
            ['code' => 'BRL', 'name' => 'Brazilian Real', 'symbol' => 'R$', 'decimal_places' => 2, 'gateways' => ['stripe', 'paypal']],
            ['code' => 'ARS', 'name' => 'Argentine Peso', 'symbol' => 'AR$', 'decimal_places' => 2, 'gateways' => ['stripe']],
            ['code' => 'CLP', 'name' => 'Chilean Peso', 'symbol' => 'CL$', 'decimal_places' => 0, 'gateways' => ['stripe']],
            ['code' => 'COP', 'name' => 'Colombian Peso', 'symbol' => 'CO$', 'decimal_places' => 2, 'gateways' => ['stripe']],
            ['code' => 'PEN', 'name' => 'Peruvian Sol', 'symbol' => 'S/', 'decimal_places' => 2, 'gateways' => ['stripe']],
            ['code' => 'UYU', 'name' => 'Uruguayan Peso', 'symbol' => '$U', 'decimal_places' => 2, 'gateways' => ['stripe']],
            ['code' => 'CHF', 'name' => 'Swiss Franc', 'symbol' => 'CHF', 'decimal_places' => 2, 'gateways' => ['stripe', 'paypal']],
            ['code' => 'NOK', 'name' => 'Norwegian Krone', 'symbol' => 'kr', 'decimal_places' => 2, 'gateways' => ['stripe', 'paypal']],
            ['code' => 'SEK', 'name' => 'Swedish Krona', 'symbol' => 'kr', 'decimal_places' => 2, 'gateways' => ['stripe', 'paypal']],
            ['code' => 'DKK', 'name' => 'Danish Krone', 'symbol' => 'kr', 'decimal_places' => 2, 'gateways' => ['stripe', 'paypal']],
            ['code' => 'ISK', 'name' => 'Icelandic Krona', 'symbol' => 'kr', 'decimal_places' => 0, 'gateways' => ['stripe']],
            ['code' => 'PLN', 'name' => 'Polish Zloty', 'symbol' => 'zł', 'decimal_places' => 2, 'gateways' => ['stripe', 'paypal']],
            ['code' => 'CZK', 'name' => 'Czech Koruna', 'symbol' => 'Kč', 'decimal_places' => 2, 'gateways' => ['stripe', 'paypal']],
            ['code' => 'HUF', 'name' => 'Hungarian Forint', 'symbol' => 'Ft', 'decimal_places' => 2, 'gateways' => ['stripe', 'paypal']],
            ['code' => 'RON', 'name' => 'Romanian Leu', 'symbol' => 'lei', 'decimal_places' => 2, 'gateways' => ['stripe']],
            ['code' => 'BGN', 'name' => 'Bulgarian Lev', 'symbol' => 'лв', 'decimal_places' => 2, 'gateways' => ['stripe']],
            ['code' => 'HRK', 'name' => 'Croatian Kuna', 'symbol' => 'kn', 'decimal_places' => 2, 'gateways' => ['stripe']],
            ['code' => 'RSD', 'name' => 'Serbian Dinar', 'symbol' => 'RSD', 'decimal_places' => 2, 'gateways' => ['stripe']],
            ['code' => 'UAH', 'name' => 'Ukrainian Hryvnia', 'symbol' => '₴', 'decimal_places' => 2, 'gateways' => ['stripe']],
            ['code' => 'RUB', 'name' => 'Russian Ruble', 'symbol' => '₽', 'decimal_places' => 2, 'gateways' => ['stripe']],
            ['code' => 'KZT', 'name' => 'Kazakhstani Tenge', 'symbol' => '₸', 'decimal_places' => 2, 'gateways' => ['stripe']],
            ['code' => 'GEL', 'name' => 'Georgian Lari', 'symbol' => '₾', 'decimal_places' => 2, 'gateways' => ['stripe']],
            ['code' => 'AMD', 'name' => 'Armenian Dram', 'symbol' => '֏', 'decimal_places' => 2, 'gateways' => ['stripe']],
        ];
    }
}
