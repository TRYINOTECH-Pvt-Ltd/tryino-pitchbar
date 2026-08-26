/**
 * Locale code as a free-form string. The set of valid codes is
 * defined at runtime by the `lang/*.json` files dropped in the
 * repository — there is no compile-time union we need to keep in
 * sync. Adding a new language is a matter of dropping a JSON file
 * (and ideally adding a catalog entry in
 * `app/Services/I18n/LocaleCatalog.php` so the picker shows a flag
 * + native name).
 */
export type SupportedLocale = string;

/**
 * Catalog metadata for one locale, hydrated server-side from
 * LocaleCatalog::hydrate(). The `code` is duplicated as a value so
 * Object.values(catalog) yields self-describing rows the picker can
 * sort/filter without a separate code lookup.
 */
export type LocaleEntry = {
    code: string;
    native: string;
    english: string;
    flag: string;
    rtl: boolean;
};

export type I18nShared = {
    locale: SupportedLocale;
    fallback: SupportedLocale;
    supported: SupportedLocale[];
    catalog: Record<string, LocaleEntry>;
    translations: Record<string, string>;
    /**
     * Master switch for the language layer (MULTILINGUAL_ENABLED). When
     * false the locale picker, geo banner, and Translation Manager hide.
     */
    multilingual: boolean;
};
