import { Head, Link, usePage } from '@inertiajs/react';
import {
    Activity,
    ArrowRight,
    Bot,
    Globe,
    Inbox,
    Library,
    MessageSquare,
    MessagesSquare,
    TrendingUp,
} from 'lucide-react';
import type { ReactNode } from 'react';
import { MetricCard, trend } from '@/components/metric-card';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { useBranding } from '@/hooks/use-branding';
import AppLayout from '@/layouts/app-layout';
import { useT } from '@/lib/i18n';
import { show as showAgent, index as agentsIndex } from '@/routes/agents';
import {
    contentGaps as analyticsContentGaps,
    overview as analyticsOverview,
} from '@/routes/analytics';
import { index as conversationsIndex } from '@/routes/conversations';
import { index as inboxIndex } from '@/routes/inbox';
import type { BreadcrumbItem } from '@/types';

type Window = {
    from: string;
    to: string;
    days: string[];
};

type SeriesMetric = {
    value: number;
    prior_value: number;
    series: number[];
};

type Metrics = {
    conversations: SeriesMetric;
    messages: SeriesMetric;
    leads: SeriesMetric;
    conversion_rate: SeriesMetric;
    engagement: SeriesMetric;
};

type Chart = {
    days: string[];
    conversations: number[];
    leads: number[];
};

type Summary = {
    active_agents: number;
    published_agents: number;
    pages_tracked: number;
    open_gaps: number;
};

type AgentRow = {
    id: string;
    name: string;
    is_published: boolean;
    conversations: number;
    messages: number;
    leads: number;
    conversion_rate: number;
    last_seen_at: string | null;
};

type PageRow = {
    page_url: string;
    conversations: number;
    leads: number;
    conversion_rate: number;
    last_seen_at: string | null;
};

type GapPreview = {
    id: string;
    question: string;
    occurrences: number;
    last_seen_at: string | null;
};

type ContentGapSummary = {
    open_count: number;
    top: GapPreview[];
};

type CsatWeek = {
    week: string;
    total: number;
    good: number;
    score: number | null;
};

type CsatLowRated = {
    id: string;
    agent_id: string;
    satisfaction_at: string | null;
    comment: string | null;
    page_url: string | null;
};

type CsatPayload = {
    series: CsatWeek[];
    latest_score: number | null;
    total_rated: number;
    low_rated: CsatLowRated[];
};

type Props = {
    window: Window;
    metrics: Metrics;
    chart: Chart;
    summary: Summary;
    agents: AgentRow[];
    pages: PageRow[];
    content_gaps: ContentGapSummary;
    csat?: CsatPayload;
    /**
     * True when one or more analytics aggregation blocks threw a
     * database exception (stale migration, missing column, etc.). The
     * controller still renders zeros for the failed sections; this
     * flag drives a one-line "Some metrics couldn't be loaded" banner
     * so the operator knows what they're looking at.
     */
    degraded?: boolean;
};

function relativeTime(
    iso: string | null,
    t: (key: string, replacements?: Record<string, string | number>) => string,
): string {
    if (!iso) {
        return t('No recent activity');
    }

    const ms = Date.now() - new Date(iso).getTime();
    const min = Math.max(1, Math.round(ms / 60000));

    if (min < 60) {
        return t(':count m ago', { count: min });
    }

    const hr = Math.round(min / 60);

    if (hr < 24) {
        return t(':count h ago', { count: hr });
    }

    const day = Math.round(hr / 24);

    if (day < 7) {
        return t(':count d ago', { count: day });
    }

    return new Date(iso).toLocaleDateString();
}

function formatRange(from: string, to: string): string {
    const opts: Intl.DateTimeFormatOptions = { month: 'short', day: 'numeric' };

    return `${new Date(from).toLocaleDateString('en-US', opts)} - ${new Date(
        to,
    ).toLocaleDateString('en-US', {
        ...opts,
        year: 'numeric',
    })}`;
}

function trendSub(
    current: number,
    prior: number,
    t: (key: string, replacements?: Record<string, string | number>) => string,
    fallback?: string,
) {
    const data = trend(current, prior);
    const localizedFallback = fallback ?? t('vs previous 30d');

    if (data.dir === 'flat') {
        return localizedFallback;
    }

    return t(':dir :pct% vs previous 30d', {
        dir: data.dir === 'up' ? t('Up') : t('Down'),
        pct: data.pct,
    });
}

function FooterMetric({
    icon,
    label,
    value,
}: {
    icon: ReactNode;
    label: string;
    value: string;
}) {
    return (
        <div className="flex min-w-0 items-center justify-between gap-3 border-b px-4 py-2 last:border-b-0 sm:border-e sm:border-b-0 sm:last:border-e-0">
            <div className="flex min-w-0 items-center gap-2 text-muted-foreground">
                {icon}
                <span className="truncate text-xs">{label}</span>
            </div>
            <span className="text-xs font-medium text-foreground tabular-nums">
                {value}
            </span>
        </div>
    );
}

function AnalyticsChart({ chart }: { chart: Chart }) {
    const width = 760;
    const height = 240;
    const padX = 24;
    const padY = 16;
    const innerWidth = width - padX * 2;
    const innerHeight = height - padY * 2;
    const values = [...chart.conversations, ...chart.leads];
    const max = Math.max(1, ...values);
    const stepX = innerWidth / Math.max(1, chart.days.length - 1);

    const toPath = (series: number[]) =>
        series
            .map(
                (value, index) =>
                    `${(padX + index * stepX).toFixed(1)},${(padY + innerHeight - (value / max) * innerHeight).toFixed(1)}`,
            )
            .join(' ');

    const conversationsPath = toPath(chart.conversations);
    const leadsPath = toPath(chart.leads);
    const conversationArea = `${padX},${padY + innerHeight} ${conversationsPath} ${padX + innerWidth},${padY + innerHeight}`;
    const ticks = [0, 0.25, 0.5, 0.75, 1].map((ratio) =>
        Math.round(max * ratio),
    );
    const xLabels = [
        0,
        Math.floor(chart.days.length / 4),
        Math.floor(chart.days.length / 2),
        Math.floor((chart.days.length * 3) / 4),
        chart.days.length - 1,
    ]
        .map((index) => ({
            index,
            label: chart.days[index]
                ? new Date(chart.days[index]).toLocaleDateString('en-US', {
                      month: 'short',
                      day: 'numeric',
                  })
                : '',
        }))
        .filter(
            (label, index, rows) =>
                label.label !== '' &&
                rows.findIndex((row) => row.index === label.index) === index,
        );

    return (
        <svg
            viewBox={`0 0 ${width} ${height + 24}`}
            className="h-64 w-full"
            preserveAspectRatio="xMidYMid meet"
            aria-hidden
        >
            {ticks.map((tick, index) => {
                const y = padY + innerHeight - (tick / max) * innerHeight;

                return (
                    <g key={index}>
                        <line
                            x1={padX}
                            y1={y}
                            x2={padX + innerWidth}
                            y2={y}
                            stroke="currentColor"
                            strokeOpacity={0.06}
                        />
                        <text
                            x={padX - 6}
                            y={y + 4}
                            textAnchor="end"
                            fontSize="10"
                            fill="currentColor"
                            opacity={0.45}
                        >
                            {tick}
                        </text>
                    </g>
                );
            })}
            <polygon
                points={conversationArea}
                fill="#2563eb"
                fillOpacity={0.12}
            />
            <polyline
                points={conversationsPath}
                fill="none"
                stroke="#2563eb"
                strokeWidth={1.8}
                strokeLinecap="round"
                strokeLinejoin="round"
            />
            <polyline
                points={leadsPath}
                fill="none"
                stroke="#f97316"
                strokeWidth={1.7}
                strokeDasharray="4 4"
                strokeLinecap="round"
                strokeLinejoin="round"
            />
            {xLabels.map(({ index, label }) => (
                <text
                    key={index}
                    x={padX + index * stepX}
                    y={height + 16}
                    textAnchor="middle"
                    fontSize="10"
                    fill="currentColor"
                    opacity={0.45}
                >
                    {label}
                </text>
            ))}
        </svg>
    );
}

export default function AnalyticsIndex({
    window,
    metrics,
    chart,
    summary,
    agents,
    pages,
    content_gaps: contentGaps,
    csat,
    degraded,
}: Props) {
    const { t } = useT();
    const branding = useBranding();
    const brand = branding.site_title || 'Pitchbar';
    const { auth } = usePage<{
        auth: { user: { is_super_admin?: boolean } | null };
    }>().props;
    const isSuperAdmin = auth?.user?.is_super_admin === true;
    const breadcrumbs: BreadcrumbItem[] = [
        { title: t('Analytics'), href: analyticsOverview() },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('Analytics')} />
            <div className="flex flex-1 flex-col gap-4 p-4">
                {degraded && isSuperAdmin && (
                    <div className="rounded-md border border-amber-300 bg-amber-50 px-3 py-2 text-xs text-amber-800 dark:border-amber-700 dark:bg-amber-900/30 dark:text-amber-200">
                        {t(
                            "Some metrics couldn't be loaded. The most likely cause is a pending database migration — run `php artisan migrate` on the :brand host and reload. Details are in storage/logs/laravel.log.",
                            { brand },
                        )}
                    </div>
                )}
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <div className="inline-flex items-center gap-2 rounded-full border bg-background px-3 py-1 text-xs text-muted-foreground">
                            <Activity className="size-3.5" />
                            {formatRange(window.from, window.to)}
                        </div>
                        <h1 className="mt-3 text-2xl font-semibold tracking-tight">
                            {t('Analytics')}
                        </h1>
                        <p className="mt-1 max-w-3xl text-sm text-muted-foreground">
                            {t(
                                'Real traffic, lead capture, and knowledge signals across every agent in this workspace. Use this view to see which agents are pulling volume, which pages convert, and which unanswered questions still need attention.',
                            )}
                        </p>
                    </div>

                    <div className="flex flex-wrap items-center gap-2">
                        <Button asChild variant="outline" size="sm">
                            <Link href={conversationsIndex()} prefetch>
                                <MessagesSquare className="size-3.5" />
                                {t('Conversations')}
                            </Link>
                        </Button>
                        <Button asChild variant="outline" size="sm">
                            <Link href={inboxIndex()} prefetch>
                                <Inbox className="size-3.5" />
                                {t('Inbox')}
                            </Link>
                        </Button>
                        <Button asChild size="sm">
                            <Link href={analyticsContentGaps()} prefetch>
                                <Library className="size-3.5" />
                                {t('Content gaps')}
                            </Link>
                        </Button>
                    </div>
                </div>

                <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
                    <MetricCard
                        label={t('Conversations')}
                        value={metrics.conversations.value.toLocaleString()}
                        sub={trendSub(
                            metrics.conversations.value,
                            metrics.conversations.prior_value,
                            t,
                        )}
                        icon={<MessagesSquare className="size-4" />}
                        iconTone="bg-blue-500/10 text-blue-700 dark:text-blue-300"
                        series={metrics.conversations.series}
                        accent="#2563eb"
                        trendData={trend(
                            metrics.conversations.value,
                            metrics.conversations.prior_value,
                        )}
                    />
                    <MetricCard
                        label={t('Messages')}
                        value={metrics.messages.value.toLocaleString()}
                        sub={trendSub(
                            metrics.messages.value,
                            metrics.messages.prior_value,
                            t,
                        )}
                        icon={<MessageSquare className="size-4" />}
                        iconTone="bg-emerald-500/10 text-emerald-700 dark:text-emerald-300"
                        series={metrics.messages.series}
                        accent="#059669"
                        trendData={trend(
                            metrics.messages.value,
                            metrics.messages.prior_value,
                        )}
                    />
                    <MetricCard
                        label={t('Leads')}
                        value={metrics.leads.value.toLocaleString()}
                        sub={trendSub(
                            metrics.leads.value,
                            metrics.leads.prior_value,
                            t,
                        )}
                        icon={<Inbox className="size-4" />}
                        iconTone="bg-amber-500/10 text-amber-700 dark:text-amber-300"
                        series={metrics.leads.series}
                        accent="#f59e0b"
                        trendData={trend(
                            metrics.leads.value,
                            metrics.leads.prior_value,
                        )}
                    />
                    <MetricCard
                        label={t('Lead Conversion')}
                        value={`${metrics.conversion_rate.value.toFixed(1)}%`}
                        sub={trendSub(
                            metrics.conversion_rate.value,
                            metrics.conversion_rate.prior_value,
                            t,
                        )}
                        icon={<TrendingUp className="size-4" />}
                        iconTone="bg-rose-500/10 text-rose-700 dark:text-rose-300"
                        series={metrics.conversion_rate.series}
                        accent="#e11d48"
                        trendData={trend(
                            metrics.conversion_rate.value,
                            metrics.conversion_rate.prior_value,
                        )}
                    />
                    <MetricCard
                        label={t('Messages / Convo')}
                        value={metrics.engagement.value.toFixed(2)}
                        sub={trendSub(
                            metrics.engagement.value,
                            metrics.engagement.prior_value,
                            t,
                        )}
                        icon={<Activity className="size-4" />}
                        iconTone="bg-violet-500/10 text-violet-700 dark:text-violet-300"
                        series={metrics.engagement.series}
                        accent="#7c3aed"
                        trendData={trend(
                            metrics.engagement.value,
                            metrics.engagement.prior_value,
                        )}
                    />
                </div>

                <div className="grid gap-4 xl:grid-cols-[minmax(0,1.55fr)_minmax(320px,0.95fr)]">
                    <Card className="overflow-hidden p-0">
                        <div className="flex items-start justify-between gap-4 border-b px-4 py-4">
                            <div>
                                <p className="text-sm font-medium">
                                    {t('Traffic and capture trend')}
                                </p>
                                <p className="mt-0.5 text-xs text-muted-foreground">
                                    {t(
                                        'Conversations drive the blue area. Leads are the dotted line, so you can see whether capture is climbing with traffic.',
                                    )}
                                </p>
                            </div>
                            <div className="rounded-full border bg-background px-3 py-1 text-[11px] font-medium text-muted-foreground">
                                {t('Last 30 days')}
                            </div>
                        </div>
                        <div className="px-3 pt-4 pb-2 sm:px-4">
                            <AnalyticsChart chart={chart} />
                        </div>
                        <div className="grid gap-0 border-t sm:grid-cols-2 xl:grid-cols-4">
                            <FooterMetric
                                icon={<Bot className="size-3.5" />}
                                label={t('Active agents')}
                                value={summary.active_agents.toLocaleString()}
                            />
                            <FooterMetric
                                icon={<TrendingUp className="size-3.5" />}
                                label={t('Published agents')}
                                value={summary.published_agents.toLocaleString()}
                            />
                            <FooterMetric
                                icon={<Globe className="size-3.5" />}
                                label={t('Tracked pages')}
                                value={summary.pages_tracked.toLocaleString()}
                            />
                            <FooterMetric
                                icon={<Library className="size-3.5" />}
                                label={t('Open gaps')}
                                value={summary.open_gaps.toLocaleString()}
                            />
                        </div>
                    </Card>

                    <Card className="p-0">
                        <div className="flex items-center justify-between border-b px-4 py-4">
                            <div>
                                <p className="text-sm font-medium">
                                    {t('Top agents')}
                                </p>
                                <p className="mt-0.5 text-xs text-muted-foreground">
                                    {t(
                                        'Who is driving the most conversation and lead volume.',
                                    )}
                                </p>
                            </div>
                            <Button asChild variant="ghost" size="sm">
                                <Link href={agentsIndex()} prefetch>
                                    {t('All agents')}
                                </Link>
                            </Button>
                        </div>

                        {agents.length === 0 ? (
                            <div className="px-4 py-10 text-center text-sm text-muted-foreground">
                                {t(
                                    'No agent activity yet. Once visitors start chatting, the strongest performers will show up here.',
                                )}
                            </div>
                        ) : (
                            <div className="divide-y">
                                {agents.map((agent) => (
                                    <Link
                                        key={agent.id}
                                        href={showAgent(agent.id)}
                                        className="flex items-center gap-3 px-4 py-3 transition hover:bg-muted/50"
                                    >
                                        <div className="flex size-9 shrink-0 items-center justify-center rounded-full bg-muted text-muted-foreground">
                                            <Bot className="size-4" />
                                        </div>
                                        <div className="min-w-0 flex-1">
                                            <div className="flex flex-wrap items-center gap-2">
                                                <p className="truncate text-sm font-medium text-foreground">
                                                    {agent.name}
                                                </p>
                                                <span
                                                    className={`rounded-full px-2 py-0.5 text-[10px] font-medium ${
                                                        agent.is_published
                                                            ? 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-300'
                                                            : 'bg-muted text-muted-foreground'
                                                    }`}
                                                >
                                                    {agent.is_published
                                                        ? t('Live')
                                                        : t('Draft')}
                                                </span>
                                            </div>
                                            <p className="mt-1 text-[11px] text-muted-foreground">
                                                {t(
                                                    ':count messages · last active :time',
                                                    {
                                                        count: agent.messages.toLocaleString(),
                                                        time: relativeTime(
                                                            agent.last_seen_at,
                                                            t,
                                                        ),
                                                    },
                                                )}
                                            </p>
                                        </div>
                                        <div className="text-end">
                                            <p className="text-sm font-semibold text-foreground tabular-nums">
                                                {agent.conversations.toLocaleString()}
                                            </p>
                                            <p className="text-[11px] text-muted-foreground">
                                                {t(':count leads · :pct%', {
                                                    count: agent.leads.toLocaleString(),
                                                    pct: agent.conversion_rate.toFixed(
                                                        1,
                                                    ),
                                                })}
                                            </p>
                                        </div>
                                        <ArrowRight className="size-4 shrink-0 text-muted-foreground" />
                                    </Link>
                                ))}
                            </div>
                        )}
                    </Card>
                </div>

                <div className="grid gap-4 xl:grid-cols-2">
                    <Card className="p-0">
                        <div className="flex items-center justify-between border-b px-4 py-4">
                            <div>
                                <p className="text-sm font-medium">
                                    {t('Top pages')}
                                </p>
                                <p className="mt-0.5 text-xs text-muted-foreground">
                                    {t(
                                        'Which entry points pull traffic and turn it into leads.',
                                    )}
                                </p>
                            </div>
                            <Button asChild variant="ghost" size="sm">
                                <Link href={conversationsIndex()} prefetch>
                                    {t('Open conversations')}
                                </Link>
                            </Button>
                        </div>

                        {pages.length === 0 ? (
                            <div className="px-4 py-10 text-center text-sm text-muted-foreground">
                                {t(
                                    'No tracked pages yet. Page URLs appear here once the widget sees real visitor traffic.',
                                )}
                            </div>
                        ) : (
                            <div className="divide-y">
                                {pages.map((page) => {
                                    const href =
                                        page.page_url === '(no page url)'
                                            ? conversationsIndex()
                                            : conversationsIndex({
                                                  query: { q: page.page_url },
                                              });

                                    return (
                                        <Link
                                            key={page.page_url}
                                            href={href}
                                            className="flex items-center gap-3 px-4 py-3 transition hover:bg-muted/50"
                                        >
                                            <div className="flex size-9 shrink-0 items-center justify-center rounded-full bg-muted text-muted-foreground">
                                                <Globe className="size-4" />
                                            </div>
                                            <div className="min-w-0 flex-1">
                                                <p className="truncate text-sm font-medium text-foreground">
                                                    {page.page_url}
                                                </p>
                                                <p className="mt-1 text-[11px] text-muted-foreground">
                                                    {t('Last seen :time', {
                                                        time: relativeTime(
                                                            page.last_seen_at,
                                                            t,
                                                        ),
                                                    })}
                                                </p>
                                            </div>
                                            <div className="text-end">
                                                <p className="text-sm font-semibold text-foreground tabular-nums">
                                                    {page.conversations.toLocaleString()}
                                                </p>
                                                <p className="text-[11px] text-muted-foreground">
                                                    {t(':count leads · :pct%', {
                                                        count: page.leads.toLocaleString(),
                                                        pct: page.conversion_rate.toFixed(
                                                            1,
                                                        ),
                                                    })}
                                                </p>
                                            </div>
                                            <ArrowRight className="size-4 shrink-0 text-muted-foreground" />
                                        </Link>
                                    );
                                })}
                            </div>
                        )}
                    </Card>

                    <Card className="p-0">
                        <div className="flex items-center justify-between border-b px-4 py-4">
                            <div>
                                <p className="text-sm font-medium">
                                    {t('Content gaps')}
                                </p>
                                <p className="mt-0.5 text-xs text-muted-foreground">
                                    {t(
                                        'The unanswered questions creating the most friction right now.',
                                    )}
                                </p>
                            </div>
                            <Button asChild variant="ghost" size="sm">
                                <Link href={analyticsContentGaps()} prefetch>
                                    {t(':count open', {
                                        count: contentGaps.open_count.toLocaleString(),
                                    })}
                                </Link>
                            </Button>
                        </div>

                        {contentGaps.top.length === 0 ? (
                            <div className="px-4 py-10 text-center text-sm text-muted-foreground">
                                {t(
                                    'No open gaps. Your current knowledge coverage is keeping up with visitor questions.',
                                )}
                            </div>
                        ) : (
                            <div className="divide-y">
                                {contentGaps.top.map((gap) => (
                                    <Link
                                        key={gap.id}
                                        href={analyticsContentGaps()}
                                        className="flex items-center gap-3 px-4 py-3 transition hover:bg-muted/50"
                                    >
                                        <div className="flex size-9 shrink-0 items-center justify-center rounded-full bg-amber-500/10 text-amber-700 dark:text-amber-300">
                                            <Library className="size-4" />
                                        </div>
                                        <div className="min-w-0 flex-1">
                                            <p className="line-clamp-2 text-sm font-medium text-foreground">
                                                {gap.question}
                                            </p>
                                            <p className="mt-1 text-[11px] text-muted-foreground">
                                                {t('Last seen :time', {
                                                    time: relativeTime(
                                                        gap.last_seen_at,
                                                        t,
                                                    ),
                                                })}
                                            </p>
                                        </div>
                                        <div className="text-end">
                                            <p className="text-sm font-semibold text-foreground tabular-nums">
                                                {gap.occurrences.toLocaleString()}
                                            </p>
                                            <p className="text-[11px] text-muted-foreground">
                                                {t('occurrences')}
                                            </p>
                                        </div>
                                        <ArrowRight className="size-4 shrink-0 text-muted-foreground" />
                                    </Link>
                                ))}
                            </div>
                        )}
                    </Card>
                </div>

                <Link
                    href={analyticsContentGaps()}
                    className="block transition hover:opacity-90"
                >
                    <Card className="flex items-center justify-between gap-4 p-4">
                        <div>
                            <p className="text-sm font-medium">
                                {t('Need deeper remediation work?')}
                            </p>
                            <p className="mt-0.5 text-xs text-muted-foreground">
                                {t(
                                    'Open the full content-gap queue to see every unanswered question, sorted by repeat volume and last seen date.',
                                )}
                            </p>
                        </div>
                        <ArrowRight className="size-4 shrink-0 text-muted-foreground" />
                    </Card>
                </Link>

                {csat && csat.total_rated > 0 && (
                    <Card className="p-4">
                        <div className="flex flex-wrap items-baseline justify-between gap-3">
                            <div>
                                <p className="text-sm font-medium">
                                    {t('Visitor satisfaction (CSAT)')}
                                </p>
                                <p className="mt-0.5 text-xs text-muted-foreground">
                                    {t(
                                        'Share of conversations rated good vs bad, weekly over the last 12 weeks.',
                                    )}
                                </p>
                            </div>
                            {csat.latest_score !== null && (
                                <p className="text-2xl font-semibold tabular-nums">
                                    {csat.latest_score}%
                                    <span className="ml-2 text-xs font-normal text-muted-foreground">
                                        {t(':n ratings', {
                                            n: csat.total_rated.toLocaleString(),
                                        })}
                                    </span>
                                </p>
                            )}
                        </div>

                        <div className="mt-4 flex h-24 items-end gap-1">
                            {csat.series.map((w) => {
                                const score = w.score ?? 0;
                                const hasData = w.score !== null;
                                const barColor = hasData
                                    ? score >= 70
                                        ? 'bg-emerald-500'
                                        : score >= 40
                                          ? 'bg-amber-500'
                                          : 'bg-rose-500'
                                    : 'bg-muted';

                                return (
                                    <div
                                        key={w.week}
                                        className="flex flex-1 flex-col items-center"
                                        title={`${w.week} — ${hasData ? score + '%' : t('no ratings')}`}
                                    >
                                        <div
                                            className={`w-full rounded-t ${barColor}`}
                                            style={{
                                                height: hasData
                                                    ? `${Math.max(score, 4)}%`
                                                    : '4%',
                                            }}
                                        />
                                    </div>
                                );
                            })}
                        </div>

                        {csat.low_rated.length > 0 && (
                            <div className="mt-5">
                                <p className="text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                                    {t('Recent low-rated conversations')}
                                </p>
                                <div className="mt-2 divide-y">
                                    {csat.low_rated.map((c) => (
                                        <Link
                                            key={c.id}
                                            href={`/app/conversations/${c.id}`}
                                            className="block py-2 transition hover:bg-muted/40"
                                        >
                                            <p className="line-clamp-2 text-sm">
                                                {c.comment ?? t('No comment')}
                                            </p>
                                            <p className="mt-1 text-[11px] text-muted-foreground">
                                                {c.page_url ?? '—'}
                                                {c.satisfaction_at && (
                                                    <>
                                                        {' · '}
                                                        {new Date(
                                                            c.satisfaction_at,
                                                        ).toLocaleString()}
                                                    </>
                                                )}
                                            </p>
                                        </Link>
                                    ))}
                                </div>
                            </div>
                        )}
                    </Card>
                )}
            </div>
        </AppLayout>
    );
}
