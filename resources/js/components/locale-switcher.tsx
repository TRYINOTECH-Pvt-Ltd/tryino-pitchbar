import { router } from '@inertiajs/react';
import { Check, Globe, Search, X } from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { localeFlag, useI18n, useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { LocaleEntry, SupportedLocale } from '@/types';

/**
 * Header pill that opens a searchable modal listing every locale the
 * server discovered in `lang/*.json`. Switching PATCHes
 * /settings/locale (server saves to users.locale + pb_locale cookie),
 * then reloads so every mounted page re-renders in the new language.
 *
 * Designed for a 120+ locale catalog: the dropdown that worked for 4
 * locales becomes unscrollable once we ship the long tail. A modal
 * with a fuzzy search input scales to thousands of entries.
 */
/**
 * Visual variant for the trigger pill. The picker modal stays
 * identical across both — only the button chrome differs so it
 * doesn't look out of place on the marketing site (cream
 * background, sans dark Tailwind tokens).
 *
 *   - "admin"     — default. Outlined dark-token pill, matches the
 *                   admin shell header where it sits next to other
 *                   icon buttons of the same style.
 *   - "marketing" — ghost-style pill matching the Login link on the
 *                   marketing nav: transparent, soft hover, no
 *                   dark background tokens.
 */
type LocaleSwitcherVariant = 'admin' | 'marketing';

const TRIGGER_CLASS: Record<LocaleSwitcherVariant, string> = {
    admin: 'size-9 rounded-xl border-border/70 bg-background/80 shadow-xs',
    marketing:
        'size-9 rounded-md border-transparent bg-transparent shadow-none ' +
        'text-[#41483f] transition hover:bg-[#173f2c]/8 hover:text-[#173f2c]',
};

export function LocaleSwitcher({
    variant = 'admin',
}: {
    variant?: LocaleSwitcherVariant;
} = {}) {
    const { t } = useT();
    const { locale, supported, catalog, multilingual } = useI18n();
    const [open, setOpen] = useState(false);

    const switchTo = (next: SupportedLocale) => {
        setOpen(false);

        if (next === locale) {
            return;
        }

        router.patch(
            '/locale/switch',
            { locale: next },
            { preserveScroll: true, preserveState: false },
        );
    };

    const flag = localeFlag(catalog, locale);

    // Language layer off → no picker (the site is English-only).
    if (!multilingual) {
        return null;
    }

    return (
        <>
            <Tooltip>
                <TooltipTrigger asChild>
                    <Button
                        type="button"
                        variant="outline"
                        size="icon"
                        onClick={() => setOpen(true)}
                        aria-label={t('Language')}
                        className={TRIGGER_CLASS[variant]}
                    >
                        <span className="text-base leading-none" aria-hidden>
                            {flag}
                        </span>
                        <span className="sr-only">{t('Language')}</span>
                    </Button>
                </TooltipTrigger>
                <TooltipContent side="bottom">{t('Language')}</TooltipContent>
            </Tooltip>

            <LocalePickerDialog
                open={open}
                onOpenChange={setOpen}
                locale={locale}
                supported={supported}
                catalog={catalog}
                onPick={switchTo}
            />
        </>
    );
}

/**
 * Reusable picker dialog. Marketing-shell, admin sidebar, settings
 * page all open the same component — keeps the UX identical and
 * means a fix to the search ranking lands everywhere.
 */
export function LocalePickerDialog({
    open,
    onOpenChange,
    locale,
    supported,
    catalog,
    onPick,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    locale: string;
    supported: string[];
    catalog: Record<string, LocaleEntry>;
    onPick: (code: SupportedLocale) => void;
}) {
    const { t } = useT();
    const [query, setQuery] = useState('');
    const inputRef = useRef<HTMLInputElement | null>(null);

    // Reset + autofocus on open. Without the timeout the input's
    // focus call lands before Radix has finished mounting it. setQuery
    // also runs inside the timeout so the effect doesn't re-render
    // synchronously on mount.
    useEffect(() => {
        if (!open) {
            return;
        }

        const id = window.setTimeout(() => {
            setQuery('');
            inputRef.current?.focus();
        }, 30);

        return () => window.clearTimeout(id);
    }, [open]);

    const rows = useMemo(() => {
        const items = supported.map((code) => {
            const entry = catalog[code];

            return {
                code,
                native: entry?.native ?? code.toUpperCase(),
                english: entry?.english ?? code,
                flag: entry?.flag ?? '🌐',
                rtl: entry?.rtl ?? false,
            };
        });

        const q = query.trim().toLowerCase();

        if (q === '') {
            return items;
        }

        return items.filter(
            (r) =>
                r.code.toLowerCase().includes(q) ||
                r.native.toLowerCase().includes(q) ||
                r.english.toLowerCase().includes(q),
        );
    }, [supported, catalog, query]);

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle className="flex items-center gap-2 text-base">
                        <Globe className="size-4" />
                        {t('Choose your language')}
                    </DialogTitle>
                </DialogHeader>

                <div className="relative">
                    <Search className="absolute start-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
                    <input
                        ref={inputRef}
                        type="search"
                        value={query}
                        onChange={(e) => setQuery(e.target.value)}
                        placeholder={t('Search languages…')}
                        className="w-full rounded-md border border-border bg-background py-2 ps-9 pe-9 text-sm outline-none placeholder:text-muted-foreground focus:border-ring focus:ring-1 focus:ring-ring"
                        autoComplete="off"
                        spellCheck={false}
                    />
                    {query !== '' && (
                        <button
                            type="button"
                            onClick={() => setQuery('')}
                            className="absolute end-2 top-1/2 inline-flex size-6 -translate-y-1/2 items-center justify-center rounded-full text-muted-foreground transition hover:bg-muted hover:text-foreground"
                            aria-label={t('Clear search')}
                        >
                            <X className="size-3" />
                        </button>
                    )}
                </div>

                <ul
                    className="-mx-1 max-h-[60vh] overflow-y-auto"
                    role="listbox"
                >
                    {rows.length === 0 ? (
                        <li className="px-3 py-6 text-center text-sm text-muted-foreground">
                            {t('No matching languages.')}
                        </li>
                    ) : (
                        rows.map((row) => {
                            const active = row.code === locale;

                            return (
                                <li key={row.code}>
                                    <button
                                        type="button"
                                        onClick={() => onPick(row.code)}
                                        role="option"
                                        aria-selected={active}
                                        dir={row.rtl ? 'rtl' : undefined}
                                        className={cn(
                                            'flex w-full items-center gap-3 rounded-md px-3 py-2 text-sm transition hover:bg-muted/60',
                                            active &&
                                                'bg-muted/40 font-semibold',
                                        )}
                                    >
                                        <span
                                            className="text-base leading-none"
                                            aria-hidden
                                        >
                                            {row.flag}
                                        </span>
                                        <span className="flex-1 truncate text-start">
                                            {row.native}
                                        </span>
                                        <span className="text-xs text-muted-foreground">
                                            {row.english}
                                        </span>
                                        {active ? (
                                            <Check className="size-4 text-emerald-600" />
                                        ) : null}
                                    </button>
                                </li>
                            );
                        })
                    )}
                </ul>
            </DialogContent>
        </Dialog>
    );
}
