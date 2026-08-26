import { Head, router } from '@inertiajs/react';
import {
    Activity,
    RefreshCw,
    RotateCcw,
    ShieldAlert,
    ShieldCheck,
} from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { BreadcrumbItem } from '@/types';

type WidgetEventRow = {
    id: number;
    type: string;
    severity: string;
    provider: string | null;
    message: string | null;
    context: Record<string, unknown> | null;
    agent_id: string | null;
    conversation_id: string | null;
    occurred_at: string | null;
    resolved_at: string | null;
};

type Summary = {
    total_24h: number;
    by_type: Record<string, number>;
    open: number;
    down_last_hour: number;
};

type Filters = {
    type: string;
    severity: string;
    status: string;
    window: string;
};

type Props = {
    events: WidgetEventRow[];
    summary: Summary;
    verdict: string;
    types: string[];
    filters: Filters;
};

const BASE_PATH = '/settings/system/widget-monitor';

const TYPE_LABEL: Record<string, string> = {
    provider_down: 'Provider down',
    stream_failed: 'Stream failed',
    provider_failover: 'Failover',
    client_stalled: 'Client freeze',
    tool_loop_timeout: 'Tool-loop timeout',
    retrieval_failed: 'Retrieval failed',
};

function severityTone(severity: string): string {
    if (severity === 'error') {
        return 'bg-red-100 text-red-700 dark:bg-red-950 dark:text-red-300';
    }

    if (severity === 'warning') {
        return 'bg-amber-100 text-amber-700 dark:bg-amber-950 dark:text-amber-300';
    }

    return 'bg-muted text-muted-foreground';
}

export default function WidgetMonitor({
    events,
    summary,
    verdict,
    types,
    filters,
}: Props) {
    const { t } = useT();

    const breadcrumbs: BreadcrumbItem[] = [
        { title: t('Settings'), href: '/settings/system' },
        { title: t('Widget Monitor'), href: BASE_PATH },
    ];

    const applyFilter = (key: keyof Filters, value: string) => {
        router.get(
            BASE_PATH,
            { ...filters, [key]: value },
            { preserveScroll: true, preserveState: false },
        );
    };

    const toggleResolve = (id: number) => {
        router.post(`${BASE_PATH}/${id}/resolve`, {}, { preserveScroll: true });
    };

    const typeLabel = (type: string) => t(TYPE_LABEL[type] ?? type);

    // Most-urgent-first colour for the verdict banner.
    const verdictTone =
        summary.down_last_hour > 0 || (summary.by_type.stream_failed ?? 0) > 0
            ? 'border-l-red-500'
            : summary.open > 0
              ? 'border-l-amber-500'
              : 'border-l-emerald-500';

    const summaryCards: Array<{ label: string; value: number; tone: string }> =
        [
            {
                label: t('Open'),
                value: summary.open,
                tone:
                    summary.open > 0
                        ? 'text-amber-600 dark:text-amber-400'
                        : 'text-muted-foreground',
            },
            {
                label: t('Provider down'),
                value: summary.by_type.provider_down ?? 0,
                tone:
                    (summary.by_type.provider_down ?? 0) > 0
                        ? 'text-red-600 dark:text-red-400'
                        : 'text-muted-foreground',
            },
            {
                label: t('Stream failed'),
                value: summary.by_type.stream_failed ?? 0,
                tone:
                    (summary.by_type.stream_failed ?? 0) > 0
                        ? 'text-red-600 dark:text-red-400'
                        : 'text-muted-foreground',
            },
            {
                label: t('Failover'),
                value: summary.by_type.provider_failover ?? 0,
                tone: 'text-muted-foreground',
            },
            {
                label: t('Client freeze'),
                value: summary.by_type.client_stalled ?? 0,
                tone:
                    (summary.by_type.client_stalled ?? 0) > 0
                        ? 'text-amber-600 dark:text-amber-400'
                        : 'text-muted-foreground',
            },
            {
                label: t('Total (24h)'),
                value: summary.total_24h,
                tone: 'text-foreground',
            },
        ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('Widget Monitor')} />
            <div className="mx-auto w-full max-w-6xl space-y-5 p-4 sm:p-6">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div className="flex items-center gap-3">
                        <div className="rounded-md border bg-muted/30 p-2">
                            {summary.down_last_hour > 0 ? (
                                <ShieldAlert className="size-5 text-red-500" />
                            ) : (
                                <ShieldCheck className="size-5 text-muted-foreground" />
                            )}
                        </div>
                        <div>
                            <h1 className="text-2xl font-semibold">
                                {t('Widget Monitor')}
                            </h1>
                            <p className="text-sm text-muted-foreground">
                                {t(
                                    'Errors, provider outages and visitor-reported freezes from the live chat widget. Track them here and mark each one resolved as you fix it.',
                                )}
                            </p>
                        </div>
                    </div>
                    <Button
                        variant="outline"
                        size="sm"
                        onClick={() => router.reload()}
                    >
                        <RefreshCw className="me-1.5 size-4" />
                        {t('Refresh')}
                    </Button>
                </div>

                <Card className={cn('border-l-4 p-4', verdictTone)}>
                    <div className="flex items-start gap-2">
                        <Activity className="mt-0.5 size-4 shrink-0 text-primary" />
                        <p className="text-sm">{verdict}</p>
                    </div>
                </Card>

                <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
                    {summaryCards.map((card) => (
                        <Card key={card.label} className="p-3">
                            <p className="text-[10px] font-medium tracking-wide text-muted-foreground uppercase">
                                {card.label}
                            </p>
                            <p
                                className={cn(
                                    'mt-1 text-2xl font-semibold tabular-nums',
                                    card.tone,
                                )}
                            >
                                {card.value}
                            </p>
                        </Card>
                    ))}
                </div>

                <div className="flex flex-wrap items-center gap-2">
                    <FilterSelect
                        label={t('Window')}
                        value={filters.window}
                        onChange={(v) => applyFilter('window', v)}
                        options={[
                            { value: '24h', label: t('Last 24h') },
                            { value: '7d', label: t('Last 7 days') },
                            { value: '30d', label: t('Last 30 days') },
                        ]}
                    />
                    <FilterSelect
                        label={t('Type')}
                        value={filters.type}
                        onChange={(v) => applyFilter('type', v)}
                        options={[
                            { value: '', label: t('All types') },
                            ...types.map((type) => ({
                                value: type,
                                label: typeLabel(type),
                            })),
                        ]}
                    />
                    <FilterSelect
                        label={t('Severity')}
                        value={filters.severity}
                        onChange={(v) => applyFilter('severity', v)}
                        options={[
                            { value: '', label: t('All') },
                            { value: 'error', label: t('Error') },
                            { value: 'warning', label: t('Warning') },
                        ]}
                    />
                    <FilterSelect
                        label={t('Status')}
                        value={filters.status}
                        onChange={(v) => applyFilter('status', v)}
                        options={[
                            { value: 'all', label: t('All') },
                            { value: 'open', label: t('Open') },
                            { value: 'resolved', label: t('Resolved') },
                        ]}
                    />
                </div>

                <Card className="p-0">
                    {events.length === 0 ? (
                        <div className="flex flex-col items-center gap-2 p-10 text-center text-muted-foreground">
                            <ShieldCheck className="size-8 opacity-40" />
                            <p className="text-sm">
                                {t(
                                    'No events match these filters. A quiet monitor is a healthy widget.',
                                )}
                            </p>
                        </div>
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="w-full text-sm">
                                <thead className="bg-muted/40 text-xs text-muted-foreground">
                                    <tr>
                                        <th className="px-3 py-2 text-left font-medium">
                                            {t('When')}
                                        </th>
                                        <th className="px-3 py-2 text-left font-medium">
                                            {t('Type')}
                                        </th>
                                        <th className="px-3 py-2 text-left font-medium">
                                            {t('Provider')}
                                        </th>
                                        <th className="px-3 py-2 text-left font-medium">
                                            {t('Detail')}
                                        </th>
                                        <th className="px-3 py-2 text-right font-medium">
                                            {t('Action')}
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {events.map((event) => (
                                        <tr
                                            key={event.id}
                                            className={cn(
                                                'border-t hover:bg-muted/30',
                                                event.resolved_at !== null &&
                                                    'opacity-50',
                                            )}
                                        >
                                            <td className="px-3 py-2 text-xs whitespace-nowrap text-muted-foreground">
                                                {event.occurred_at
                                                    ? new Date(
                                                          event.occurred_at,
                                                      ).toLocaleString()
                                                    : '—'}
                                            </td>
                                            <td className="px-3 py-2">
                                                <span
                                                    className={cn(
                                                        'rounded px-1.5 py-0.5 text-[11px] font-medium',
                                                        severityTone(
                                                            event.severity,
                                                        ),
                                                    )}
                                                >
                                                    {typeLabel(event.type)}
                                                </span>
                                            </td>
                                            <td className="px-3 py-2 text-xs text-muted-foreground">
                                                {event.provider ?? '—'}
                                            </td>
                                            <td className="px-3 py-2 text-[12px] text-muted-foreground">
                                                <span className="line-clamp-2">
                                                    {event.message ?? '—'}
                                                </span>
                                                {event.context?.exception ? (
                                                    <span className="mt-0.5 block font-mono text-[10px] text-muted-foreground/70">
                                                        {String(
                                                            event.context
                                                                .exception,
                                                        )}
                                                    </span>
                                                ) : null}
                                            </td>
                                            <td className="px-3 py-2 text-right">
                                                <Button
                                                    variant="ghost"
                                                    size="sm"
                                                    onClick={() =>
                                                        toggleResolve(event.id)
                                                    }
                                                >
                                                    {event.resolved_at !==
                                                    null ? (
                                                        <>
                                                            <RotateCcw className="me-1.5 size-3.5" />
                                                            {t('Reopen')}
                                                        </>
                                                    ) : (
                                                        <>
                                                            <ShieldCheck className="me-1.5 size-3.5" />
                                                            {t('Resolve')}
                                                        </>
                                                    )}
                                                </Button>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </Card>
                <p className="text-[11px] text-muted-foreground">
                    {t(
                        'Events are recorded off the visitor hot path — capturing a failure never slows a working chat. Showing the most recent :count.',
                        { count: events.length },
                    )}
                </p>
            </div>
        </AppLayout>
    );
}

function FilterSelect({
    label,
    value,
    onChange,
    options,
}: {
    label: string;
    value: string;
    onChange: (value: string) => void;
    options: Array<{ value: string; label: string }>;
}) {
    return (
        <label className="flex items-center gap-1.5 text-xs text-muted-foreground">
            <span>{label}</span>
            <select
                value={value}
                onChange={(e) => onChange(e.target.value)}
                className="rounded-md border bg-background px-2 py-1 text-xs text-foreground"
            >
                {options.map((option) => (
                    <option key={option.value} value={option.value}>
                        {option.label}
                    </option>
                ))}
            </select>
        </label>
    );
}
