import { Link, usePage } from '@inertiajs/react';
import { Sparkles, X } from 'lucide-react';
import { useState } from 'react';
import { useT } from '@/lib/i18n';

type ChangelogTeaser = {
    version: string;
    released_at: string | null;
    title: string;
    last_seen_at: string | null;
} | null;

/**
 * "What's new" banner shown to authenticated workspace members
 * when there's a published changelog entry newer than their
 * last_changelog_seen_at timestamp. Dismissing the banner POSTs to
 * /changelog/seen so the timestamp updates and the banner stays
 * hidden until the next entry lands.
 *
 * Mounted INSIDE `<AppContent>` (not above `<AppShell>`) so the
 * fixed-position sidebar can't clip the banner's left edge — that
 * was the v1.1.0 launch bug where "What's new in" rendered as
 * "'s new in" on wide screens.
 *
 * Returns null when there's nothing to show — never renders for
 * super_admins (they author the changelog themselves, see
 * HandleInertiaRequests::changelogTeaser).
 */
export function WhatsNewBanner() {
    const { t } = useT();
    const { changelogTeaser } = usePage<{
        changelogTeaser: ChangelogTeaser;
    }>().props;
    const [dismissed, setDismissed] = useState(false);

    if (!changelogTeaser) {
        return null;
    }

    if (dismissed) {
        return null;
    }

    // Hide if the user has already acknowledged a changelog entry
    // released at or after the latest. We compare ISO strings —
    // lexical sort works for ISO-8601.
    if (
        changelogTeaser.last_seen_at !== null &&
        changelogTeaser.released_at !== null &&
        changelogTeaser.last_seen_at >= changelogTeaser.released_at
    ) {
        return null;
    }

    const dismiss = () => {
        setDismissed(true);
        // Fire-and-forget — we already updated local state so the
        // banner disappears even if the network call fails.
        fetch('/changelog/seen', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'X-CSRF-TOKEN':
                    (
                        document.querySelector(
                            'meta[name="csrf-token"]',
                        ) as HTMLMetaElement | null
                    )?.content ?? '',
                Accept: 'application/json',
            },
        }).catch(() => {});
    };

    return (
        <div className="border-b border-emerald-200 bg-emerald-50/70 text-emerald-900 dark:border-emerald-900/40 dark:bg-emerald-950/40 dark:text-emerald-200">
            <div className="flex items-center gap-2 px-3 py-1.5 text-sm sm:gap-3 sm:px-4">
                <Sparkles
                    aria-hidden="true"
                    className="size-4 shrink-0 text-emerald-600"
                />

                {/*
                 * Lead chunk — must never get truncated. "What's new
                 * in v1.1.0" is the load-bearing context for the
                 * whole banner; clipping its start is what the v1.1
                 * launch bug looked like.
                 */}
                <span className="hidden shrink-0 font-medium sm:inline">
                    {t("What's new in")}
                </span>
                <span className="shrink-0 rounded-full border border-emerald-300 bg-white/70 px-2 py-0.5 font-mono text-xs font-semibold text-emerald-800 dark:border-emerald-700/60 dark:bg-emerald-900/40 dark:text-emerald-200">
                    {changelogTeaser.version}
                </span>

                {/*
                 * Title — gets truncated if the available width
                 * doesn't fit the whole headline. min-w-0 lets the
                 * flex child shrink below its content size; truncate
                 * draws the ellipsis on the right (the safe end —
                 * the version + intro stay readable).
                 */}
                <span
                    className="min-w-0 flex-1 truncate"
                    title={changelogTeaser.title}
                >
                    {changelogTeaser.title}
                </span>

                <Link
                    href="/changelog"
                    className="shrink-0 rounded-md border border-emerald-300 bg-white/80 px-2.5 py-0.5 text-xs font-semibold hover:bg-white dark:border-emerald-700/60 dark:bg-transparent"
                    onClick={dismiss}
                >
                    {t('Read')}
                </Link>
                <button
                    type="button"
                    onClick={dismiss}
                    aria-label={t('Dismiss')}
                    className="shrink-0 rounded p-0.5 hover:bg-emerald-100 dark:hover:bg-emerald-900/40"
                >
                    <X className="size-4" />
                </button>
            </div>
        </div>
    );
}
