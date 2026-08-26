import { ArrowDownRight, ArrowUpRight } from 'lucide-react';
import type { ReactNode } from 'react';
import { Sparkline } from '@/components/sparkline';
import { Card } from '@/components/ui/card';

export type TrendData = { dir: 'up' | 'down' | 'flat'; pct: number };

/**
 * Compute a trend object given a current and previous-period value.
 * Returns `flat` when the previous value was zero (no baseline) so we
 * don't render a "+infinity%" badge.
 */
export function trend(current: number, prior: number): TrendData {
    if (prior === 0) {
        return { dir: 'flat', pct: 0 };
    }

    const pct = Math.round(((current - prior) / prior) * 100);

    if (pct === 0) {
        return { dir: 'flat', pct: 0 };
    }

    return { dir: pct > 0 ? 'up' : 'down', pct: Math.abs(pct) };
}

export function MetricCard({
    label,
    value,
    sub,
    icon,
    iconTone,
    series,
    accent,
    trendData,
}: {
    label: string;
    value: number | string;
    sub?: string;
    icon: ReactNode;
    iconTone: string;
    series?: number[];
    accent?: string;
    trendData?: TrendData;
}) {
    return (
        <Card className="relative min-w-0 overflow-hidden p-4 sm:p-5">
            <div className="flex items-start justify-between">
                <div className={`rounded-lg p-2 ${iconTone}`}>{icon}</div>
                {trendData && trendData.dir !== 'flat' && (
                    <span
                        className={`inline-flex items-center gap-0.5 rounded-full px-2 py-0.5 text-[11px] font-medium ${
                            trendData.dir === 'up'
                                ? 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-400'
                                : 'bg-rose-500/10 text-rose-700 dark:text-rose-400'
                        }`}
                    >
                        {trendData.dir === 'up' ? (
                            <ArrowUpRight className="size-3" />
                        ) : (
                            <ArrowDownRight className="size-3" />
                        )}
                        {trendData.pct}%
                    </span>
                )}
            </div>
            <p className="mt-3 text-xs leading-tight font-medium tracking-wider text-muted-foreground uppercase">
                {label}
            </p>
            <p className="mt-1 text-2xl font-semibold tracking-tight tabular-nums sm:text-3xl">
                {value}
            </p>
            <div className="mt-1 flex min-w-0 flex-col gap-2 sm:flex-row sm:items-end sm:justify-between">
                {sub && (
                    <p className="min-w-0 text-xs text-muted-foreground">
                        {sub}
                    </p>
                )}
                {series && accent && (
                    <Sparkline values={series} accent={accent} />
                )}
            </div>
        </Card>
    );
}
