import { useI18n } from '@/lib/i18n';

export type Direction = 'ltr' | 'rtl';

/**
 * Resolve the writing direction for the current locale. Reads the
 * RTL flag from the shared catalog (LocaleCatalog::ENTRIES on the
 * server) so adding a new RTL locale to the catalog is the only
 * code change needed — every component using this hook flips
 * automatically.
 *
 * Returns the boolean form. Use `useDirection()` if you need the
 * 'ltr' | 'rtl' string (e.g. for `dir=` attributes on third-party
 * components).
 */
export function useIsRtl(): boolean {
    const { locale, catalog } = useI18n();

    return catalog?.[locale]?.rtl ?? false;
}

export function useDirection(): Direction {
    return useIsRtl() ? 'rtl' : 'ltr';
}
