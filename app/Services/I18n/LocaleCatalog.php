<?php

namespace App\Services\I18n;

/**
 * Curated catalog of locales the app KNOWS ABOUT for display purposes.
 *
 * The presence of an entry here does NOT make the locale active —
 * activation requires a matching `lang/{code}.json` file on disk
 * (LocaleResolver::supported() scans the directory). The catalog only
 * supplies metadata (native name, English name, flag, RTL flag) so the
 * picker UI can render any language the buyer drops in without code
 * changes.
 *
 * Anything not in the catalog still works — `entryFor()` falls back to
 * a generic { code.toUpperCase(), code, 🌐, ltr } stub. Adding a
 * locale that isn't in the catalog is therefore zero-friction; adding
 * it here just upgrades the picker visuals.
 */
final class LocaleCatalog
{
    /**
     * Right-to-left writing systems we know about. Drives the
     * `<html dir>` attribute and the picker's sort grouping.
     *
     * @var array<int, string>
     */
    public const RTL_LOCALES = ['ar', 'arc', 'dv', 'fa', 'ha-Arab', 'he', 'khw', 'ks', 'ku', 'ps', 'ur', 'ug', 'yi'];

    /**
     * 120 popular world languages, alphabetically by ISO code.
     *
     * Schema per entry:
     *   - code: BCP-47 / ISO 639-1 (or -3) tag
     *   - native: language name as native speakers spell it
     *   - english: English exonym
     *   - flag: best-fit emoji (most-populous country, or 🌐 for pan-regional)
     *   - rtl: bool
     *
     * Flags are intentionally country-flavoured for visual recognition.
     * They are NOT a political claim about which country "owns" the
     * language — switch the emoji freely if a buyer requests a different
     * one for their market.
     *
     * @var array<string, array{native: string, english: string, flag: string, rtl: bool}>
     */
    public const ENTRIES = [
        'af' => ['native' => 'Afrikaans', 'english' => 'Afrikaans', 'flag' => '🇿🇦', 'rtl' => false],
        'am' => ['native' => 'አማርኛ', 'english' => 'Amharic', 'flag' => '🇪🇹', 'rtl' => false],
        'ar' => ['native' => 'العربية', 'english' => 'Arabic', 'flag' => '🇸🇦', 'rtl' => true],
        'as' => ['native' => 'অসমীয়া', 'english' => 'Assamese', 'flag' => '🇮🇳', 'rtl' => false],
        'ay' => ['native' => 'Aymar aru', 'english' => 'Aymara', 'flag' => '🇧🇴', 'rtl' => false],
        'az' => ['native' => 'Azərbaycan', 'english' => 'Azerbaijani', 'flag' => '🇦🇿', 'rtl' => false],
        'be' => ['native' => 'Беларуская', 'english' => 'Belarusian', 'flag' => '🇧🇾', 'rtl' => false],
        'bg' => ['native' => 'Български', 'english' => 'Bulgarian', 'flag' => '🇧🇬', 'rtl' => false],
        'bn' => ['native' => 'বাংলা', 'english' => 'Bengali', 'flag' => '🇧🇩', 'rtl' => false],
        'bo' => ['native' => 'བོད་ཡིག', 'english' => 'Tibetan', 'flag' => '🌐', 'rtl' => false],
        'bs' => ['native' => 'Bosanski', 'english' => 'Bosnian', 'flag' => '🇧🇦', 'rtl' => false],
        'br' => ['native' => 'Brezhoneg', 'english' => 'Breton', 'flag' => '🌐', 'rtl' => false],
        'ca' => ['native' => 'Català', 'english' => 'Catalan', 'flag' => '🌐', 'rtl' => false],
        'ceb' => ['native' => 'Cebuano', 'english' => 'Cebuano', 'flag' => '🇵🇭', 'rtl' => false],
        'co' => ['native' => 'Corsu', 'english' => 'Corsican', 'flag' => '🌐', 'rtl' => false],
        'cs' => ['native' => 'Čeština', 'english' => 'Czech', 'flag' => '🇨🇿', 'rtl' => false],
        'cy' => ['native' => 'Cymraeg', 'english' => 'Welsh', 'flag' => '🏴󠁧󠁢󠁷󠁬󠁳󠁿', 'rtl' => false],
        'da' => ['native' => 'Dansk', 'english' => 'Danish', 'flag' => '🇩🇰', 'rtl' => false],
        'de' => ['native' => 'Deutsch', 'english' => 'German', 'flag' => '🇩🇪', 'rtl' => false],
        'dv' => ['native' => 'ދިވެހި', 'english' => 'Dhivehi', 'flag' => '🇲🇻', 'rtl' => true],
        'dz' => ['native' => 'རྫོང་ཁ', 'english' => 'Dzongkha', 'flag' => '🇧🇹', 'rtl' => false],
        'ee' => ['native' => 'Eʋegbe', 'english' => 'Ewe', 'flag' => '🇬🇭', 'rtl' => false],
        'el' => ['native' => 'Ελληνικά', 'english' => 'Greek', 'flag' => '🇬🇷', 'rtl' => false],
        'en' => ['native' => 'English', 'english' => 'English', 'flag' => '🇺🇸', 'rtl' => false],
        'eo' => ['native' => 'Esperanto', 'english' => 'Esperanto', 'flag' => '🌐', 'rtl' => false],
        'es' => ['native' => 'Español', 'english' => 'Spanish', 'flag' => '🇪🇸', 'rtl' => false],
        'et' => ['native' => 'Eesti', 'english' => 'Estonian', 'flag' => '🇪🇪', 'rtl' => false],
        'eu' => ['native' => 'Euskara', 'english' => 'Basque', 'flag' => '🌐', 'rtl' => false],
        'fa' => ['native' => 'فارسی', 'english' => 'Persian', 'flag' => '🇮🇷', 'rtl' => true],
        'ff' => ['native' => 'Fulfulde', 'english' => 'Fula', 'flag' => '🌐', 'rtl' => false],
        'fi' => ['native' => 'Suomi', 'english' => 'Finnish', 'flag' => '🇫🇮', 'rtl' => false],
        'fil' => ['native' => 'Filipino', 'english' => 'Filipino', 'flag' => '🇵🇭', 'rtl' => false],
        'fj' => ['native' => 'Vosa Vakaviti', 'english' => 'Fijian', 'flag' => '🇫🇯', 'rtl' => false],
        'fo' => ['native' => 'Føroyskt', 'english' => 'Faroese', 'flag' => '🇫🇴', 'rtl' => false],
        'fr' => ['native' => 'Français', 'english' => 'French', 'flag' => '🇫🇷', 'rtl' => false],
        'fy' => ['native' => 'Frysk', 'english' => 'Frisian', 'flag' => '🇳🇱', 'rtl' => false],
        'ga' => ['native' => 'Gaeilge', 'english' => 'Irish', 'flag' => '🇮🇪', 'rtl' => false],
        'gd' => ['native' => 'Gàidhlig', 'english' => 'Scottish Gaelic', 'flag' => '🏴󠁧󠁢󠁳󠁣󠁴󠁿', 'rtl' => false],
        'gl' => ['native' => 'Galego', 'english' => 'Galician', 'flag' => '🌐', 'rtl' => false],
        'gn' => ['native' => 'Avañeʼẽ', 'english' => 'Guarani', 'flag' => '🇵🇾', 'rtl' => false],
        'gu' => ['native' => 'ગુજરાતી', 'english' => 'Gujarati', 'flag' => '🇮🇳', 'rtl' => false],
        'ha' => ['native' => 'Hausa', 'english' => 'Hausa', 'flag' => '🇳🇬', 'rtl' => false],
        'haw' => ['native' => 'ʻŌlelo Hawaiʻi', 'english' => 'Hawaiian', 'flag' => '🇺🇸', 'rtl' => false],
        'he' => ['native' => 'עברית', 'english' => 'Hebrew', 'flag' => '🇮🇱', 'rtl' => true],
        'hi' => ['native' => 'हिन्दी', 'english' => 'Hindi', 'flag' => '🇮🇳', 'rtl' => false],
        'hr' => ['native' => 'Hrvatski', 'english' => 'Croatian', 'flag' => '🇭🇷', 'rtl' => false],
        'ht' => ['native' => 'Kreyòl ayisyen', 'english' => 'Haitian Creole', 'flag' => '🇭🇹', 'rtl' => false],
        'hu' => ['native' => 'Magyar', 'english' => 'Hungarian', 'flag' => '🇭🇺', 'rtl' => false],
        'hy' => ['native' => 'Հայերեն', 'english' => 'Armenian', 'flag' => '🇦🇲', 'rtl' => false],
        'id' => ['native' => 'Bahasa Indonesia', 'english' => 'Indonesian', 'flag' => '🇮🇩', 'rtl' => false],
        'ig' => ['native' => 'Igbo', 'english' => 'Igbo', 'flag' => '🇳🇬', 'rtl' => false],
        'is' => ['native' => 'Íslenska', 'english' => 'Icelandic', 'flag' => '🇮🇸', 'rtl' => false],
        'it' => ['native' => 'Italiano', 'english' => 'Italian', 'flag' => '🇮🇹', 'rtl' => false],
        'ja' => ['native' => '日本語', 'english' => 'Japanese', 'flag' => '🇯🇵', 'rtl' => false],
        'jv' => ['native' => 'Basa Jawa', 'english' => 'Javanese', 'flag' => '🇮🇩', 'rtl' => false],
        'ka' => ['native' => 'ქართული', 'english' => 'Georgian', 'flag' => '🇬🇪', 'rtl' => false],
        'kk' => ['native' => 'Қазақша', 'english' => 'Kazakh', 'flag' => '🇰🇿', 'rtl' => false],
        'km' => ['native' => 'ខ្មែរ', 'english' => 'Khmer', 'flag' => '🇰🇭', 'rtl' => false],
        'kn' => ['native' => 'ಕನ್ನಡ', 'english' => 'Kannada', 'flag' => '🇮🇳', 'rtl' => false],
        'ko' => ['native' => '한국어', 'english' => 'Korean', 'flag' => '🇰🇷', 'rtl' => false],
        'ku' => ['native' => 'Kurdî', 'english' => 'Kurdish', 'flag' => '🌐', 'rtl' => false],
        'ky' => ['native' => 'Кыргызча', 'english' => 'Kyrgyz', 'flag' => '🇰🇬', 'rtl' => false],
        'la' => ['native' => 'Latina', 'english' => 'Latin', 'flag' => '🌐', 'rtl' => false],
        'lb' => ['native' => 'Lëtzebuergesch', 'english' => 'Luxembourgish', 'flag' => '🇱🇺', 'rtl' => false],
        'lg' => ['native' => 'Luganda', 'english' => 'Luganda', 'flag' => '🇺🇬', 'rtl' => false],
        'ln' => ['native' => 'Lingála', 'english' => 'Lingala', 'flag' => '🇨🇩', 'rtl' => false],
        'lo' => ['native' => 'ລາວ', 'english' => 'Lao', 'flag' => '🇱🇦', 'rtl' => false],
        'lt' => ['native' => 'Lietuvių', 'english' => 'Lithuanian', 'flag' => '🇱🇹', 'rtl' => false],
        'lv' => ['native' => 'Latviešu', 'english' => 'Latvian', 'flag' => '🇱🇻', 'rtl' => false],
        'mg' => ['native' => 'Malagasy', 'english' => 'Malagasy', 'flag' => '🇲🇬', 'rtl' => false],
        'mi' => ['native' => 'Māori', 'english' => 'Maori', 'flag' => '🇳🇿', 'rtl' => false],
        'mk' => ['native' => 'Македонски', 'english' => 'Macedonian', 'flag' => '🇲🇰', 'rtl' => false],
        'ml' => ['native' => 'മലയാളം', 'english' => 'Malayalam', 'flag' => '🇮🇳', 'rtl' => false],
        'mn' => ['native' => 'Монгол', 'english' => 'Mongolian', 'flag' => '🇲🇳', 'rtl' => false],
        'mr' => ['native' => 'मराठी', 'english' => 'Marathi', 'flag' => '🇮🇳', 'rtl' => false],
        'ms' => ['native' => 'Bahasa Melayu', 'english' => 'Malay', 'flag' => '🇲🇾', 'rtl' => false],
        'mt' => ['native' => 'Malti', 'english' => 'Maltese', 'flag' => '🇲🇹', 'rtl' => false],
        'my' => ['native' => 'မြန်မာ', 'english' => 'Burmese', 'flag' => '🇲🇲', 'rtl' => false],
        'ne' => ['native' => 'नेपाली', 'english' => 'Nepali', 'flag' => '🇳🇵', 'rtl' => false],
        'nl' => ['native' => 'Nederlands', 'english' => 'Dutch', 'flag' => '🇳🇱', 'rtl' => false],
        'nn' => ['native' => 'Nynorsk', 'english' => 'Norwegian Nynorsk', 'flag' => '🇳🇴', 'rtl' => false],
        'no' => ['native' => 'Norsk', 'english' => 'Norwegian', 'flag' => '🇳🇴', 'rtl' => false],
        'ny' => ['native' => 'Chichewa', 'english' => 'Chichewa', 'flag' => '🇲🇼', 'rtl' => false],
        'oc' => ['native' => 'Occitan', 'english' => 'Occitan', 'flag' => '🌐', 'rtl' => false],
        'om' => ['native' => 'Afaan Oromoo', 'english' => 'Oromo', 'flag' => '🇪🇹', 'rtl' => false],
        'or' => ['native' => 'ଓଡ଼ିଆ', 'english' => 'Odia', 'flag' => '🇮🇳', 'rtl' => false],
        'pa' => ['native' => 'ਪੰਜਾਬੀ', 'english' => 'Punjabi', 'flag' => '🇮🇳', 'rtl' => false],
        'pap' => ['native' => 'Papiamentu', 'english' => 'Papiamento', 'flag' => '🌐', 'rtl' => false],
        'pl' => ['native' => 'Polski', 'english' => 'Polish', 'flag' => '🇵🇱', 'rtl' => false],
        'ps' => ['native' => 'پښتو', 'english' => 'Pashto', 'flag' => '🇦🇫', 'rtl' => true],
        'pt' => ['native' => 'Português', 'english' => 'Portuguese', 'flag' => '🇵🇹', 'rtl' => false],
        'pt-BR' => ['native' => 'Português (Brasil)', 'english' => 'Portuguese (Brazil)', 'flag' => '🇧🇷', 'rtl' => false],
        'qu' => ['native' => 'Runa Simi', 'english' => 'Quechua', 'flag' => '🇵🇪', 'rtl' => false],
        'rm' => ['native' => 'Rumantsch', 'english' => 'Romansh', 'flag' => '🇨🇭', 'rtl' => false],
        'ro' => ['native' => 'Română', 'english' => 'Romanian', 'flag' => '🇷🇴', 'rtl' => false],
        'ru' => ['native' => 'Русский', 'english' => 'Russian', 'flag' => '🇷🇺', 'rtl' => false],
        'rw' => ['native' => 'Kinyarwanda', 'english' => 'Kinyarwanda', 'flag' => '🇷🇼', 'rtl' => false],
        'sd' => ['native' => 'سنڌي', 'english' => 'Sindhi', 'flag' => '🇵🇰', 'rtl' => true],
        'si' => ['native' => 'සිංහල', 'english' => 'Sinhala', 'flag' => '🇱🇰', 'rtl' => false],
        'sk' => ['native' => 'Slovenčina', 'english' => 'Slovak', 'flag' => '🇸🇰', 'rtl' => false],
        'sl' => ['native' => 'Slovenščina', 'english' => 'Slovenian', 'flag' => '🇸🇮', 'rtl' => false],
        'sm' => ['native' => 'Gagana Samoa', 'english' => 'Samoan', 'flag' => '🇼🇸', 'rtl' => false],
        'sn' => ['native' => 'chiShona', 'english' => 'Shona', 'flag' => '🇿🇼', 'rtl' => false],
        'so' => ['native' => 'Soomaali', 'english' => 'Somali', 'flag' => '🇸🇴', 'rtl' => false],
        'sq' => ['native' => 'Shqip', 'english' => 'Albanian', 'flag' => '🇦🇱', 'rtl' => false],
        'sr' => ['native' => 'Српски', 'english' => 'Serbian', 'flag' => '🇷🇸', 'rtl' => false],
        'st' => ['native' => 'Sesotho', 'english' => 'Southern Sotho', 'flag' => '🇱🇸', 'rtl' => false],
        'su' => ['native' => 'Basa Sunda', 'english' => 'Sundanese', 'flag' => '🇮🇩', 'rtl' => false],
        'sv' => ['native' => 'Svenska', 'english' => 'Swedish', 'flag' => '🇸🇪', 'rtl' => false],
        'sw' => ['native' => 'Kiswahili', 'english' => 'Swahili', 'flag' => '🇰🇪', 'rtl' => false],
        'ta' => ['native' => 'தமிழ்', 'english' => 'Tamil', 'flag' => '🇮🇳', 'rtl' => false],
        'te' => ['native' => 'తెలుగు', 'english' => 'Telugu', 'flag' => '🇮🇳', 'rtl' => false],
        'tg' => ['native' => 'Тоҷикӣ', 'english' => 'Tajik', 'flag' => '🇹🇯', 'rtl' => false],
        'th' => ['native' => 'ไทย', 'english' => 'Thai', 'flag' => '🇹🇭', 'rtl' => false],
        'ti' => ['native' => 'ትግርኛ', 'english' => 'Tigrinya', 'flag' => '🇪🇷', 'rtl' => false],
        'tk' => ['native' => 'Türkmen', 'english' => 'Turkmen', 'flag' => '🇹🇲', 'rtl' => false],
        'tl' => ['native' => 'Tagalog', 'english' => 'Tagalog', 'flag' => '🇵🇭', 'rtl' => false],
        'to' => ['native' => 'Lea Faka-Tonga', 'english' => 'Tongan', 'flag' => '🇹🇴', 'rtl' => false],
        'tr' => ['native' => 'Türkçe', 'english' => 'Turkish', 'flag' => '🇹🇷', 'rtl' => false],
        'tt' => ['native' => 'Татар', 'english' => 'Tatar', 'flag' => '🌐', 'rtl' => false],
        'ug' => ['native' => 'ئۇيغۇرچە', 'english' => 'Uyghur', 'flag' => '🌐', 'rtl' => true],
        'uk' => ['native' => 'Українська', 'english' => 'Ukrainian', 'flag' => '🇺🇦', 'rtl' => false],
        'ur' => ['native' => 'اردو', 'english' => 'Urdu', 'flag' => '🇵🇰', 'rtl' => true],
        'uz' => ['native' => 'Oʻzbekcha', 'english' => 'Uzbek', 'flag' => '🇺🇿', 'rtl' => false],
        'vi' => ['native' => 'Tiếng Việt', 'english' => 'Vietnamese', 'flag' => '🇻🇳', 'rtl' => false],
        'wo' => ['native' => 'Wolof', 'english' => 'Wolof', 'flag' => '🇸🇳', 'rtl' => false],
        'xh' => ['native' => 'isiXhosa', 'english' => 'Xhosa', 'flag' => '🇿🇦', 'rtl' => false],
        'yi' => ['native' => 'ייִדיש', 'english' => 'Yiddish', 'flag' => '🌐', 'rtl' => true],
        'yo' => ['native' => 'Yorùbá', 'english' => 'Yoruba', 'flag' => '🇳🇬', 'rtl' => false],
        'zh-Hans' => ['native' => '简体中文', 'english' => 'Chinese (Simplified)', 'flag' => '🇨🇳', 'rtl' => false],
        'zh-Hant' => ['native' => '繁體中文', 'english' => 'Chinese (Traditional)', 'flag' => '🇹🇼', 'rtl' => false],
        'zu' => ['native' => 'isiZulu', 'english' => 'Zulu', 'flag' => '🇿🇦', 'rtl' => false],
    ];

    /**
     * Look up metadata for a locale code. Falls back to a generic stub
     * (so dropping in `lang/foo.json` for a code we don't know about
     * still renders as "FOO · foo · 🌐 · ltr" in the picker).
     *
     * @return array{native: string, english: string, flag: string, rtl: bool}
     */
    public static function entryFor(string $code): array
    {
        if (isset(self::ENTRIES[$code])) {
            return self::ENTRIES[$code];
        }

        // Try the primary subtag (e.g. "pt-PT" → "pt") before stubbing.
        $primary = strtolower(strtok($code, '-_'));
        if ($primary !== false && isset(self::ENTRIES[$primary])) {
            return self::ENTRIES[$primary];
        }

        return [
            'native' => $code,
            'english' => $code,
            'flag' => '🌐',
            'rtl' => in_array($code, self::RTL_LOCALES, true),
        ];
    }

    /**
     * Hydrate metadata for an array of locale codes (typically the
     * output of LocaleResolver::supported()). Returns a code → entry
     * map suitable for shipping straight to the frontend picker.
     *
     * @param  array<int, string>  $codes
     * @return array<string, array{code: string, native: string, english: string, flag: string, rtl: bool}>
     */
    public static function hydrate(array $codes): array
    {
        $out = [];
        foreach ($codes as $code) {
            $entry = self::entryFor($code);
            $out[$code] = ['code' => $code, ...$entry];
        }

        return $out;
    }
}
