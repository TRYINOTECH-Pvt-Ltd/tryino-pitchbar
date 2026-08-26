import { Link, usePage } from '@inertiajs/react';
import { ArrowUpRight, Sparkles } from 'lucide-react';
import { useT } from '@/lib/i18n';

type ResourceKey = 'agent' | 'source' | 'workflow' | 'integration' | 'member';

type LimitInfo = {
    allowed: boolean;
    limit: number | null;
    current: number;
    remaining: number | null;
};

type Props = {
    resource: ResourceKey;
};

const RESOURCE_LABELS: Record<ResourceKey, string> = {
    agent: 'agents',
    source: 'knowledge sources',
    workflow: 'workflows',
    integration: 'integrations',
    member: 'members',
};

export function usePlanLimit(resource: ResourceKey): LimitInfo | null {
    const { workspaceLimits } = usePage<{
        workspaceLimits?: Record<ResourceKey, LimitInfo> | null;
    }>().props;

    return workspaceLimits?.[resource] ?? null;
}

/**
 * Inline nudge banner that renders when the workspace has hit (or is
 * 1 away from) the per-resource plan cap. Disable the matching "Add"
 * button alongside this banner so the operator can't even attempt an
 * action the server would reject. Buyer ask 2026-05-19 (sources page
 * let user pick 10 + then failed at save).
 */
export function PlanLimitBanner({ resource }: Props) {
    const { t } = useT();
    const info = usePlanLimit(resource);

    if (info === null || info.limit === null) {
        return null;
    }

    if (info.allowed && (info.remaining ?? 1) > 0) {
        return null;
    }

    const label = RESOURCE_LABELS[resource];

    return (
        <div className="flex flex-wrap items-start gap-3 rounded-md border border-amber-300/70 bg-amber-50 px-4 py-3 text-sm dark:border-amber-700 dark:bg-amber-900/20">
            <Sparkles className="mt-0.5 size-4 shrink-0 text-amber-700 dark:text-amber-300" />
            <div className="min-w-0 flex-1">
                <p className="font-medium text-amber-900 dark:text-amber-100">
                    {t(
                        "You're at your plan's :label limit (:current of :limit used).",
                        {
                            label: t(label),
                            current: info.current,
                            limit: info.limit,
                        },
                    )}
                </p>
                <p className="mt-0.5 text-xs leading-5 text-amber-800/90 dark:text-amber-200/80">
                    {t(
                        'Upgrade your plan to add more :label without losing what you already have.',
                        { label: t(label) },
                    )}
                </p>
            </div>
            <Link
                href="/app/billing"
                className="inline-flex shrink-0 items-center gap-1 rounded-full bg-amber-900 px-3 py-1.5 text-xs font-medium text-amber-50 hover:bg-amber-950 dark:bg-amber-200 dark:text-amber-900 dark:hover:bg-amber-100"
            >
                {t('Upgrade')}
                <ArrowUpRight className="size-3.5" />
            </Link>
        </div>
    );
}
