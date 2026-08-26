import { router, usePage } from '@inertiajs/react';
import { Globe, X } from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { localeFlag, localeLabel, useI18n, useT } from '@/lib/i18n';
import type { SupportedLocale } from '@/types';

type Suggestion = {
    suggested: SupportedLocale;
    current: SupportedLocale;
};

type SharedWithSuggestion = {
    localeSuggestion?: Suggestion | null;
};

/**
 * Slim top-of-page banner offering the visitor's browser-locale
 * country a one-tap switch to the matching translation. Server side
 * (LocaleResolver::suggestionFor) suppresses the banner once the
 * dismiss cookie is set or when the suggestion already matches the
 * current locale, so this component just renders what it's told.
 *
 * Two actions:
 *   - "Switch to <locale>" — PATCH /locale/switch sets users.locale +
 *     pb_locale cookie, reload re-renders in the new language.
 *   - "✕" — POST /locale/dismiss-suggestion sets a 180-day cookie so
 *     this banner never appears on this device again.
 */
export function LocaleSuggestionBanner() {
    const { t } = useT();
    const { catalog, multilingual } = useI18n();
    const { localeSuggestion } = usePage().props as SharedWithSuggestion;
    const [hidden, setHidden] = useState(false);

    if (!multilingual || !localeSuggestion || hidden) {
        return null;
    }

    const { suggested } = localeSuggestion;
    const flag = localeFlag(catalog, suggested);
    const label = localeLabel(catalog, suggested);

    const accept = () => {
        setHidden(true);
        router.patch(
            '/locale/switch',
            { locale: suggested },
            { preserveScroll: true, preserveState: false },
        );
    };

    const dismiss = () => {
        setHidden(true);
        // Best-effort fire-and-forget — the next request returns the
        // cookie which suppresses the banner permanently. Failure
        // (offline, CSRF rotation) just means the banner reappears
        // on the next reload, which is acceptable.
        fetch('/locale/dismiss-suggestion', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'X-CSRF-TOKEN':
                    document
                        .querySelector('meta[name="csrf-token"]')
                        ?.getAttribute('content') ?? '',
                Accept: 'application/json',
            },
        }).catch(() => {});
    };

    return (
        <div
            role="status"
            className="flex items-center gap-3 border-b border-emerald-500/25 bg-emerald-500/8 px-4 py-2 text-sm"
        >
            <Globe className="size-4 shrink-0 text-emerald-700 dark:text-emerald-300" />
            <span className="flex-1 text-foreground">
                <span aria-hidden className="me-1.5">
                    {flag}
                </span>
                {t('Switch to')} <strong>{label}</strong>?
            </span>
            <Button
                type="button"
                size="sm"
                onClick={accept}
                className="h-7 rounded-full bg-emerald-600 px-3 text-xs font-semibold text-white hover:bg-emerald-700"
            >
                {t('Switch to :name', { name: label })}
            </Button>
            <Button
                type="button"
                size="icon"
                variant="ghost"
                onClick={dismiss}
                aria-label={t('Keep current language')}
                className="size-7 rounded-full text-muted-foreground hover:bg-muted/40"
            >
                <X className="size-3.5" />
            </Button>
        </div>
    );
}
