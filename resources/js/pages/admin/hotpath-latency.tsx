import { Head, router } from '@inertiajs/react';
import { Activity, Gauge, RefreshCw, TimerReset } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { BreadcrumbItem } from '@/types';

type Turn = {
    at: string | null;
    conversation_id: string | null;
    agent_id: string | null;
    retrieve_ms: number;
    tool_loop_ms: number;
    first_token_ms: number;
    llm_ms: number;
    total_ms: number;
    embed_ms: number;
    ann_ms: number;
    hydrate_ms: number;
    rerank_ms: number;
    cache_hit: boolean;
    rerank_skipped: boolean;
    sources: number;
    tokens_out: number;
    is_playground: boolean;
    route: string;
    route_reason: string;
};

type StageAggregate = { p50: number; p95: number; max: number };

type Props = {
    turns: Turn[];
    aggregates: Record<string, StageAggregate>;
    verdict: string;
};

const STAGE_CARDS: Array<{ key: string; label: string }> = [
    { key: 'first_token_ms', label: 'First token' },
    { key: 'tool_loop_ms', label: 'Tool loop' },
    { key: 'retrieve_ms', label: 'Retrieval (total)' },
    { key: 'embed_ms', label: 'Embed query' },
    { key: 'ann_ms', label: 'Vector search' },
    { key: 'rerank_ms', label: 'Rerank' },
    { key: 'llm_ms', label: 'LLM full reply' },
    { key: 'total_ms', label: 'Turn total' },
];

function msTone(ms: number): string {
    if (ms === 0) {
        return 'text-muted-foreground';
    }

    if (ms < 300) {
        return 'text-emerald-600 dark:text-emerald-400';
    }

    if (ms < 800) {
        return 'text-amber-600 dark:text-amber-400';
    }

    return 'text-red-600 dark:text-red-400';
}

export default function HotPathLatency({ turns, aggregates, verdict }: Props) {
    const { t } = useT();

    const breadcrumbs: BreadcrumbItem[] = [
        { title: t('Settings'), href: '/settings/system' },
        {
            title: t('Hot path latency'),
            href: '/settings/system/hotpath-latency',
        },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('Hot path latency')} />
            <div className="mx-auto w-full max-w-6xl space-y-5 p-4 sm:p-6">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div className="flex items-center gap-3">
                        <div className="rounded-md border bg-muted/30 p-2">
                            <Gauge className="size-5 text-muted-foreground" />
                        </div>
                        <div>
                            <h1 className="text-2xl font-semibold">
                                {t('Hot path latency')}
                            </h1>
                            <p className="text-sm text-muted-foreground">
                                {t(
                                    'Per-stage timings for the last :count visitor turns. Find which round-trip makes the bot feel slow.',
                                    { count: turns.length },
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

                <Card className="border-l-4 border-l-primary p-4">
                    <div className="flex items-start gap-2">
                        <Activity className="mt-0.5 size-4 shrink-0 text-primary" />
                        <p className="text-sm">{verdict}</p>
                    </div>
                </Card>

                <div className="grid grid-cols-2 gap-3 sm:grid-cols-4 lg:grid-cols-8">
                    {STAGE_CARDS.map(({ key, label }) => {
                        const agg = aggregates[key] ?? {
                            p50: 0,
                            p95: 0,
                            max: 0,
                        };

                        return (
                            <Card key={key} className="p-3">
                                <p className="text-[10px] font-medium tracking-wide text-muted-foreground uppercase">
                                    {t(label)}
                                </p>
                                <p
                                    className={cn(
                                        'mt-1 text-lg font-semibold tabular-nums',
                                        msTone(agg.p50),
                                    )}
                                >
                                    {agg.p50}ms
                                </p>
                                <p className="text-[10px] text-muted-foreground">
                                    p95 {agg.p95}ms · max {agg.max}ms
                                </p>
                            </Card>
                        );
                    })}
                </div>

                <Card className="p-0">
                    {turns.length === 0 ? (
                        <div className="flex flex-col items-center gap-2 p-10 text-center text-muted-foreground">
                            <TimerReset className="size-8 opacity-40" />
                            <p className="text-sm">
                                {t(
                                    'No turns recorded yet. Send a message through the widget or playground, then refresh.',
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
                                        <th className="px-3 py-2 text-right font-medium">
                                            {t('First token')}
                                        </th>
                                        <th className="px-3 py-2 text-right font-medium">
                                            {t('Tool loop')}
                                        </th>
                                        <th className="px-3 py-2 text-right font-medium">
                                            {t('Retrieve')}
                                        </th>
                                        <th className="px-3 py-2 text-right font-medium">
                                            {t('Embed')}
                                        </th>
                                        <th className="px-3 py-2 text-right font-medium">
                                            {t('Search')}
                                        </th>
                                        <th className="px-3 py-2 text-right font-medium">
                                            {t('Rerank')}
                                        </th>
                                        <th className="px-3 py-2 text-right font-medium">
                                            {t('LLM')}
                                        </th>
                                        <th className="px-3 py-2 text-right font-medium">
                                            {t('Total')}
                                        </th>
                                        <th className="px-3 py-2 text-left font-medium">
                                            {t('Notes')}
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {turns.map((turn, i) => (
                                        <tr
                                            key={`${turn.conversation_id}-${i}`}
                                            className="border-t hover:bg-muted/30"
                                        >
                                            <td className="px-3 py-2 text-xs whitespace-nowrap text-muted-foreground">
                                                {turn.at
                                                    ? new Date(
                                                          turn.at,
                                                      ).toLocaleTimeString()
                                                    : '—'}
                                            </td>
                                            <td
                                                className={cn(
                                                    'px-3 py-2 text-right font-medium tabular-nums',
                                                    msTone(turn.first_token_ms),
                                                )}
                                            >
                                                {turn.first_token_ms}ms
                                            </td>
                                            <td
                                                className={cn(
                                                    'px-3 py-2 text-right text-xs tabular-nums',
                                                    turn.tool_loop_ms > 0
                                                        ? msTone(
                                                              turn.tool_loop_ms,
                                                          )
                                                        : 'text-muted-foreground',
                                                )}
                                            >
                                                {turn.tool_loop_ms > 0
                                                    ? `${turn.tool_loop_ms}ms`
                                                    : '—'}
                                            </td>
                                            <td
                                                className={cn(
                                                    'px-3 py-2 text-right tabular-nums',
                                                    msTone(turn.retrieve_ms),
                                                )}
                                            >
                                                {turn.retrieve_ms}ms
                                            </td>
                                            <td className="px-3 py-2 text-right text-xs text-muted-foreground tabular-nums">
                                                {turn.cache_hit
                                                    ? '—'
                                                    : `${turn.embed_ms}ms`}
                                            </td>
                                            <td className="px-3 py-2 text-right text-xs text-muted-foreground tabular-nums">
                                                {turn.cache_hit
                                                    ? '—'
                                                    : `${turn.ann_ms}ms`}
                                            </td>
                                            <td className="px-3 py-2 text-right text-xs text-muted-foreground tabular-nums">
                                                {turn.cache_hit
                                                    ? '—'
                                                    : turn.rerank_skipped
                                                      ? t('skipped')
                                                      : `${turn.rerank_ms}ms`}
                                            </td>
                                            <td className="px-3 py-2 text-right text-xs text-muted-foreground tabular-nums">
                                                {turn.llm_ms}ms
                                            </td>
                                            <td className="px-3 py-2 text-right font-medium tabular-nums">
                                                {turn.total_ms}ms
                                            </td>
                                            <td className="px-3 py-2 text-[11px] text-muted-foreground">
                                                {[
                                                    turn.route !== ''
                                                        ? `${turn.route}${turn.route_reason !== '' ? ` (${turn.route_reason})` : ''}`
                                                        : null,
                                                    turn.cache_hit
                                                        ? t(
                                                              'retrieve cache hit',
                                                          )
                                                        : null,
                                                    turn.rerank_skipped
                                                        ? t('rerank skipped')
                                                        : null,
                                                    turn.is_playground
                                                        ? t('playground')
                                                        : null,
                                                    `${turn.sources} ${t('sources')}`,
                                                    `${turn.tokens_out} ${t('tokens')}`,
                                                ]
                                                    .filter(Boolean)
                                                    .join(' · ')}
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
                        'Buffer holds the most recent 100 turns (7-day TTL). Timings are recorded after the reply finishes streaming — collecting them costs the visitor nothing.',
                    )}
                </p>
            </div>
        </AppLayout>
    );
}
