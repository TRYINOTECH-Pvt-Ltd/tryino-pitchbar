import type { AgentConfig } from './api';

/**
 * Module-singleton copy map. Populated once on /init from
 * `agent.copy`. Reads everywhere else are zero-cost dictionary
 * lookups — no React context, no prop drilling, no per-render
 * subscription overhead. Keeps the widget bundle thin.
 */
let copy: Record<string, string> = {};
let locale = 'en';

/**
 * Locales that render right-to-left. Mirrors the subset of
 * LocaleCatalog::RTL_LOCALES on the server we need at render time —
 * kept inline so the widget bundle stays dependency-free.
 */
const RTL_LOCALES = new Set([
    'ar',
    'arc',
    'dv',
    'fa',
    'he',
    'ku',
    'ps',
    'sd',
    'ug',
    'ur',
    'yi',
]);

export function setI18n(agent: AgentConfig | null): void {
    copy = agent?.copy ?? {};
    locale = agent?.locale ?? 'en';
}

/** Whether the active locale renders right-to-left. */
export function isRtl(): boolean {
    return RTL_LOCALES.has(locale.toLowerCase());
}

/** Writing direction (`dir` attribute value) for the active locale. */
export function direction(): 'rtl' | 'ltr' {
    return isRtl() ? 'rtl' : 'ltr';
}

/**
 * Translate an English source string. Missing keys fall back to the
 * source so a fresh deploy on a new locale never shows blanks.
 *
 * Both `:placeholder` and `{placeholder}` markers are interpolated.
 */
export function t(
    key: string,
    replacements?: Record<string, string | number>,
): string {
    let line = copy[key] ?? key;

    if (replacements) {
        for (const k in replacements) {
            const value = String(replacements[k]);
            line = line
                .replace(new RegExp(':' + k + '\\b', 'g'), value)
                .replace(new RegExp('\\{' + k + '\\}', 'g'), value);
        }
    }

    return line;
}
