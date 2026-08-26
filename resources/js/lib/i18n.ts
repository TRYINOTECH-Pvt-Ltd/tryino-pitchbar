import { usePage } from '@inertiajs/react';
import type { I18nShared, LocaleEntry, SupportedLocale } from '@/types';

type SharedWithI18n = {
    i18n?: I18nShared;
};

const FALLBACK_LOCALE: SupportedLocale = 'en';

/**
 * Resolve a locale's display label using the catalog the server
 * shipped. Falls back to the code in upper-case when the catalog
 * doesn't know about it (a JSON file dropped without a matching
 * LocaleCatalog entry — still works, just shows "FOO" until someone
 * curates the metadata).
 */
export function localeLabel(
    catalog: Record<string, LocaleEntry> | undefined,
    code: string,
): string {
    return catalog?.[code]?.native ?? code.toUpperCase();
}

export function localeFlag(
    catalog: Record<string, LocaleEntry> | undefined,
    code: string,
): string {
    return catalog?.[code]?.flag ?? '🌐';
}

export function localeIsRtl(
    catalog: Record<string, LocaleEntry> | undefined,
    code: string,
): boolean {
    return catalog?.[code]?.rtl ?? false;
}

/**
 * Replace `:placeholder` markers and `{name}` markers with values from
 * the replacements map. Both styles are supported because Laravel uses
 * `:foo` while a lot of older React code uses `{foo}`.
 */
function interpolate(
    line: string,
    replacements?: Record<string, string | number>,
): string {
    if (!replacements) {
        return line;
    }

    let out = line;

    for (const [key, raw] of Object.entries(replacements)) {
        const value = String(raw);
        out = out
            .replace(new RegExp(':' + key + '\\b', 'g'), value)
            .replace(new RegExp('\\{' + key + '\\}', 'g'), value);
    }

    return out;
}

/**
 * Pick the singular or plural form from a `singular|plural` line based
 * on `count`. Mirrors Laravel's basic `trans_choice` behaviour for the
 * common 2-form case (en/es/fr/tr all fit).
 */
function pluralize(line: string, count: number): string {
    const parts = line.split('|');

    if (parts.length < 2) {
        return line;
    }

    return count === 1 ? parts[0] : parts[1];
}

/**
 * Translate a key. The English source string IS the key — that mirrors
 * Laravel's `lang/{locale}.json` convention and means a missing
 * translation simply renders the English source.
 */
export function translate(
    translations: Record<string, string> | undefined | null,
    key: string,
    replacements?: Record<string, string | number>,
): string {
    const line = translations?.[key] ?? key;

    return interpolate(line, replacements);
}

/**
 * Same as translate but with explicit count for pluralization.
 */
export function translateChoice(
    translations: Record<string, string> | undefined | null,
    key: string,
    count: number,
    replacements?: Record<string, string | number>,
): string {
    const line = translations?.[key] ?? key;
    const picked = pluralize(line, count);

    return interpolate(picked, { ...replacements, count });
}

/**
 * Reads the i18n payload shared by HandleInertiaRequests. Uses Inertia
 * subscriptions so a partial reload that updates `i18n` re-renders
 * consumers with the new translation map.
 */
export function useI18n(): I18nShared {
    const shared = usePage().props as unknown as SharedWithI18n;

    return (
        shared.i18n ?? {
            locale: FALLBACK_LOCALE,
            fallback: FALLBACK_LOCALE,
            supported: [FALLBACK_LOCALE],
            catalog: {},
            translations: {},
            multilingual: false,
        }
    );
}

/**
 * Hook entry-point used everywhere in the admin SPA:
 *
 *   const t = useT();
 *   <button>{t('Save changes')}</button>
 *   <p>{t('Showing :from–:to of :total', { from: 1, to: 10, total: 42 })}</p>
 *
 * Must only be called inside the Inertia tree. Primitives rendered
 * outside (e.g. shadcn Dialog mounted by ConfirmDialogProvider which
 * sits above the Inertia root via `withApp`) must NOT call useT() —
 * usePage() throws there and crashes the modal. DialogContent uses an
 * English `closeLabel` prop with a hardcoded fallback so it works in
 * any tree.
 */
export function useT(): {
    t: (key: string, replacements?: Record<string, string | number>) => string;
    tChoice: (
        key: string,
        count: number,
        replacements?: Record<string, string | number>,
    ) => string;
    locale: SupportedLocale;
    multilingual: boolean;
} {
    const i18n = useI18n();

    return {
        t: (key, replacements) =>
            translate(i18n.translations, key, replacements),
        tChoice: (key, count, replacements) =>
            translateChoice(i18n.translations, key, count, replacements),
        locale: i18n.locale,
        multilingual: i18n.multilingual,
    };
}
