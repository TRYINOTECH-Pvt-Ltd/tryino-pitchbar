import { Link, usePage } from '@inertiajs/react';
import { AlertTriangle } from 'lucide-react';
import { useEffect, useRef } from 'react';
import { useT } from '@/lib/i18n';

type BillingSummary = {
    plan: { name: string } | null;
    used: number;
    limit: number;
    percent: number;
} | null;

export function UsageBanner() {
    const { t } = useT();
    const { billingSummary } = usePage<{ billingSummary: BillingSummary }>()
        .props;
    const bannerRef = useRef<HTMLDivElement | null>(null);
    const hasLimit = billingSummary !== null && billingSummary.limit > 0;
    const overLimit = hasLimit && billingSummary.used >= billingSummary.limit;
    const nearLimit = hasLimit && !overLimit && billingSummary.percent >= 80;
    const isVisible = hasLimit && (overLimit || nearLimit);

    useEffect(() => {
        if (!isVisible) {
            document.documentElement.style.removeProperty(
                '--app-shell-top-offset',
            );

            return;
        }

        const node = bannerRef.current;

        if (!node) {
            return;
        }

        const syncOffset = () => {
            document.documentElement.style.setProperty(
                '--app-shell-top-offset',
                `${node.offsetHeight}px`,
            );
        };

        syncOffset();

        const observer = new ResizeObserver(syncOffset);

        observer.observe(node);
        window.addEventListener('resize', syncOffset);

        return () => {
            observer.disconnect();
            window.removeEventListener('resize', syncOffset);
            document.documentElement.style.removeProperty(
                '--app-shell-top-offset',
            );
        };
    }, [isVisible]);

    if (!billingSummary || !hasLimit) {
        return null;
    }

    if (!isVisible) {
        return null;
    }

    return (
        <div
            ref={bannerRef}
            className={`sticky top-0 z-40 flex items-center justify-between gap-3 px-4 py-2 text-sm shadow ${
                overLimit ? 'bg-rose-600 text-white' : 'bg-amber-500 text-white'
            }`}
        >
            <div className="flex items-center gap-2">
                <AlertTriangle className="size-4" />
                <span>
                    {overLimit
                        ? t(
                              'Monthly conversation limit reached (:used / :limit). New visitors are paused.',
                              {
                                  used: billingSummary.used.toLocaleString(),
                                  limit: billingSummary.limit.toLocaleString(),
                              },
                          )
                        : t(
                              ":percent% of this month's conversations used (:used / :limit).",
                              {
                                  percent: billingSummary.percent,
                                  used: billingSummary.used.toLocaleString(),
                                  limit: billingSummary.limit.toLocaleString(),
                              },
                          )}
                </span>
            </div>
            <Link
                href="/app/billing"
                className="rounded bg-white/20 px-3 py-1 text-xs font-medium hover:bg-white/30"
            >
                {overLimit ? t('Upgrade now') : t('Manage plan')}
            </Link>
        </div>
    );
}
