import { Link, usePage } from '@inertiajs/react';
import { Clock } from 'lucide-react';
import { useT } from '@/lib/i18n';
import { show as billingShow } from '@/routes/billing';

type Trial = {
    active: boolean;
    expired: boolean;
    ends_at: string | null;
    days_left: number | null;
} | null;

/**
 * Slim app-wide trial countdown. Renders only while a workspace is on an
 * active (not-yet-expired) trial — once it expires the EnsureTrialActive
 * middleware walls the surface to the billing page, so there's no app
 * shell left to host a banner.
 *
 * Mounted inside AppContent (not fixed-position) so it never competes
 * with UsageBanner for the `--app-shell-top-offset` sticky slot.
 */
export function TrialBanner() {
    const { t } = useT();
    const { trial } = usePage<{ trial: Trial }>().props;

    if (!trial || !trial.active) {
        return null;
    }

    const days = trial.days_left ?? 0;

    return (
        <div className="flex flex-wrap items-center justify-between gap-3 border-b border-amber-200 bg-amber-50 px-4 py-2 text-sm text-amber-900 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-200">
            <div className="flex items-center gap-2">
                <Clock className="size-4 shrink-0" />
                <span>
                    {days <= 1
                        ? t('Your free trial ends today. Upgrade to keep your agents live.')
                        : t(':days days left in your free trial.', {
                              days: days.toLocaleString(),
                          })}
                </span>
            </div>
            <Link
                href={billingShow().url}
                className="rounded-md bg-amber-600 px-3 py-1 text-xs font-medium text-white hover:bg-amber-700"
            >
                {t('Upgrade now')}
            </Link>
        </div>
    );
}
