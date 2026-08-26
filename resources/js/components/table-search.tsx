import { Search, X } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { useT } from '@/lib/i18n';
import { replaceTableQuery } from '@/lib/table-query';

type Props = {
    /** Placeholder copy shown when the input is empty. */
    placeholder?: string;
    /** Initial value (usually `filters.q` from the page's Inertia props). */
    initialValue?: string;
    /** URL parameter name. Defaults to `q`. */
    paramName?: string;
    /**
     * Inertia partial-reload prop names. When supplied, the visit only
     * re-fetches these props on the server — much cheaper than a full
     * page reload for big lists.
     */
    only?: string[];
};

/**
 * Debounced search input that pushes the term into the URL via an
 * Inertia replace-visit, so the controller picks it up via
 * `$request->query('q')` and refilters the list. Reusable across all
 * index pages — admin and app — so we don't keep wiring bespoke
 * search logic per table.
 *
 * Skips the visit on first mount: `initialValue` already came from the
 * URL, so re-issuing the same visit on render would burn a request
 * and flicker the results.
 */
export function TableSearch({
    placeholder,
    initialValue = '',
    paramName = 'q',
    only,
}: Props) {
    const { t } = useT();
    const resolvedPlaceholder = placeholder ?? t('Search…');
    const [value, setValue] = useState(initialValue);
    const isFirstRender = useRef(true);

    useEffect(() => {
        if (isFirstRender.current) {
            isFirstRender.current = false;

            return;
        }

        const handle = window.setTimeout(() => {
            const trimmed = value.trim();

            replaceTableQuery(
                {
                    [paramName]: trimmed === '' ? null : trimmed,
                },
                {
                    only,
                },
            );
        }, 250);

        return () => window.clearTimeout(handle);
    }, [value, paramName, only]);

    return (
        <div className="relative w-full max-w-xs">
            <Search className="pointer-events-none absolute start-2.5 top-1/2 size-3.5 -translate-y-1/2 text-muted-foreground" />
            <input
                type="search"
                value={value}
                onChange={(e) => setValue(e.target.value)}
                placeholder={resolvedPlaceholder}
                className="h-7 w-full rounded-md border border-input bg-card ps-8 pe-7 text-xs shadow-xs transition outline-none placeholder:text-muted-foreground focus:border-ring focus:ring-2 focus:ring-ring/20"
            />
            {value !== '' && (
                <button
                    type="button"
                    onClick={() => setValue('')}
                    aria-label={t('Clear search')}
                    className="absolute end-1.5 top-1/2 -translate-y-1/2 rounded p-1 text-muted-foreground transition hover:text-foreground"
                >
                    <X className="size-3.5" />
                </button>
            )}
        </div>
    );
}
