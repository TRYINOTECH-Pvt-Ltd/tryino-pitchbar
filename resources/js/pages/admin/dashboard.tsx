import { Head, Link, router } from '@inertiajs/react';
import {
    Activity,
    AlertTriangle,
    Bot,
    Briefcase,
    Filter,
    Inbox,
    MessageSquare,
    Sparkles,
    Users,
} from 'lucide-react';
import { useEffect } from 'react';
import type { ReactNode } from 'react';
import { QueueHealthCard } from '@/components/admin/queue-health-card';
import type { QueueHealthShape } from '@/components/admin/queue-health-card';
import { MetricCard, trend } from '@/components/metric-card';
import { Sparkline } from '@/components/sparkline';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import AdminLayout from '@/layouts/admin-layout';
import { useT } from '@/lib/i18n';
import { dashboard as adminDashboard } from '@/routes/admin';
import { index as adminAgentsIndex } from '@/routes/admin/agents';
import { failed as adminFailedJobs } from '@/routes/admin/jobs';
import { index as adminUsersIndex } from '@/routes/admin/users';
import { index as adminWorkspacesIndex } from '@/routes/admin/workspaces';

type Series = {
    value: number;
    series: number[];
    prior_30d: number;
    last_30d?: number;
    published?: number;
};

type Metrics = {
    users: Series;
    workspaces: Series;
    agents: Series;
    leads: Series;
    conversations: Series;
    messages: Series;
};

type Window = { from: string; to: string; days: string[] };

type ChartData = {
    days: string[];
    conversations: number[];
    messages: number[];
};

type GrowthReport = {
    days: string[];
    users: number[];
    workspaces: number[];
    agents: number[];
};

type FunnelStage = {
    label: string;
    value: number;
    helper: string;
};

type LeadCaptureReport = {
    value: number;
    prior_value: number;
    last_7d: number;
    series: number[];
    leads: number;
    conversations: number;
};

type EngagementReport = {
    value: number;
    prior_value: number;
    last_7d: number;
    series: number[];
    messages: number;
    conversations: number;
};

type DeploymentReport = {
    published_agents: number;
    active_published_agents: number;
    inactive_published_agents: number;
    utilization_rate: number;
    published_workspaces: number;
    workspaces_with_traffic: number;
    workspace_coverage_rate: number;
    avg_conversations_per_active_agent: number;
};

type Reports = {
    growth: GrowthReport;
    funnel: FunnelStage[];
    lead_capture: LeadCaptureReport;
    engagement: EngagementReport;
    deployment: DeploymentReport;
};

type RightNow = {
    active_conversations: number;
    messages_today: number;
    as_of: string;
};

type RecentSignup = {
    id: number;
    name: string;
    email: string;
    role: string;
    created_at: string;
};

type ActivityItem = {
    kind: string;
    title: string;
    subtitle: string | null;
    at: string;
};

type Props = {
    window: Window;
    metrics: Metrics;
    chart: ChartData;
    reports: Reports;
    right_now: RightNow;
    recent_signups: RecentSignup[];
    activity: ActivityItem[];
    queueHealth: QueueHealthShape;
};

const ACCENT = '#f43f5e'; // rose-500 — matches the platform-admin badge tone

function relativeTime(iso: string | null): string {
    if (!iso) {
        return '';
    }

    const ms = Date.now() - new Date(iso).getTime();
    const sec = Math.max(1, Math.round(ms / 1000));

    if (sec < 60) {
        return 'just now';
    }

    const min = Math.round(sec / 60);

    if (min < 60) {
        return `${min}m ago`;
    }

    const h = Math.round(min / 60);

    if (h < 24) {
        return `${h}h ago`;
    }

    const d = Math.round(h / 24);

    return `${d} day${d === 1 ? '' : 's'} ago`;
}

function formatRange(from: string, to: string): string {
    const opts: Intl.DateTimeFormatOptions = { month: 'short', day: 'numeric' };
    const fromStr = new Date(from).toLocaleDateString('en-US', opts);
    const toStr = new Date(to).toLocaleDateString('en-US', {
        ...opts,
        year: 'numeric',
    });

    return `${fromStr} – ${toStr}`;
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
        <div className="flex min-w-0 items-center justify-between gap-3 border-b px-4 py-2 last:border-b-0 xl:border-e xl:border-b-0 xl:last:border-e-0">
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

/**
 * Two-series line chart (conversations + messages) with a soft area
 * fill on the primary series. Pure SVG, no chart-lib dependency.
 */
function PlatformChart({
    chart,
    accent,
}: {
    chart: ChartData;
    accent: string;
}) {
    const w = 760;
    const h = 240;
    const padX = 24;
    const padY = 16;
    const innerW = w - padX * 2;
    const innerH = h - padY * 2;

    const allValues = [...chart.conversations, ...chart.messages];
    const max = Math.max(1, ...allValues);

    const stepX = innerW / Math.max(1, chart.days.length - 1);

    const toPath = (values: number[]) =>
        values
            .map(
                (v, i) =>
                    `${(padX + i * stepX).toFixed(1)},${(padY + innerH - (v / max) * innerH).toFixed(1)}`,
            )
            .join(' ');

    const convPath = toPath(chart.conversations);
    const msgPath = toPath(chart.messages);
    const convArea = `${padX},${padY + innerH} ${convPath} ${padX + innerW},${padY + innerH}`;

    // Five evenly spaced y-axis labels (0, 25%, 50%, 75%, 100%).
    const ticks = [0, 0.25, 0.5, 0.75, 1].map((t) => Math.round(max * t));
    // Five evenly spaced x-axis date labels.
    const xLabels = [
        0,
        Math.floor(chart.days.length / 4),
        Math.floor(chart.days.length / 2),
        Math.floor((chart.days.length * 3) / 4),
        chart.days.length - 1,
    ]
        .map((i) => ({
            i,
            label: chart.days[i]
                ? new Date(chart.days[i]).toLocaleDateString('en-US', {
                      month: 'short',
                      day: 'numeric',
                  })
                : '',
        }))
        .filter((p) => p.label !== '');

    return (
        <svg
            viewBox={`0 0 ${w} ${h + 24}`}
            className="h-64 w-full"
            preserveAspectRatio="xMidYMid meet"
            aria-hidden
        >
            {ticks.map((t, i) => {
                const y = padY + innerH - (t / max) * innerH;

                return (
                    <g key={i}>
                        <line
                            x1={padX}
                            y1={y}
                            x2={padX + innerW}
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
                            {t}
                        </text>
                    </g>
                );
            })}
            <polygon points={convArea} fill={accent} fillOpacity={0.12} />
            <polyline
                points={convPath}
                fill="none"
                stroke={accent}
                strokeWidth={1.75}
                strokeLinecap="round"
                strokeLinejoin="round"
            />
            <polyline
                points={msgPath}
                fill="none"
                stroke={accent}
                strokeOpacity={0.5}
                strokeWidth={1.5}
                strokeDasharray="4 4"
                strokeLinecap="round"
                strokeLinejoin="round"
            />
            {xLabels.map(({ i, label }) => (
                <text
                    key={i}
                    x={padX + i * stepX}
                    y={h + 16}
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

export default function PlatformDashboard({
    window,
    metrics,
    chart,
    reports,
    right_now,
    recent_signups,
    activity,
    queueHealth,
}: Props) {
    const { t } = useT();

    // Poll the queueHealth prop every 8 seconds so the widget reads
    // "live" — pending jobs tick down, last-tick recency clock stays
    // fresh, recent ticks list refreshes. only: ['queueHealth'] keeps
    // the rest of the dashboard quiet (no flicker on the big chart,
    // no metric re-paint). Reference setInterval through globalThis
    // because the prop named `window` shadows the global identifier.
    useEffect(() => {
        const id = globalThis.setInterval(() => {
            router.reload({ only: ['queueHealth'] });
        }, 8000);

        return () => globalThis.clearInterval(id);
    }, []);

    return (
        <AdminLayout
            breadcrumbs={[{ title: t('Dashboard'), href: adminDashboard() }]}
        >
            <Head title={t('Platform admin')} />
            <div className="flex min-h-0 flex-1 flex-col bg-card">
                <div className="flex min-h-10 flex-wrap items-center gap-2 border-b px-3 py-2 sm:py-1.5">
                    <span className="inline-flex items-center gap-2 rounded-md border bg-background px-3 py-1.5 text-xs text-muted-foreground">
                        <Filter className="size-3.5" />
                        {formatRange(window.from, window.to)}
                    </span>
                    <span className="inline-flex items-center gap-2 rounded-md border border-rose-500/15 bg-rose-500/10 px-3 py-1.5 text-xs text-rose-700 dark:text-rose-300">
                        <Activity className="size-3.5" />
                        {t(':count live now', {
                            count: right_now.active_conversations.toLocaleString(),
                        })}
                    </span>
                    <span className="inline-flex items-center gap-2 rounded-md border bg-background px-3 py-1.5 text-xs text-muted-foreground">
                        <Sparkles className="size-3.5" />
                        {t(':count messages / 30d', {
                            count: metrics.messages.value.toLocaleString(),
                        })}
                    </span>

                    <div className="flex w-full flex-wrap items-center gap-2 sm:ms-auto sm:w-auto">
                        <Button
                            asChild
                            variant="outline"
                            size="sm"
                            className="flex-1 sm:flex-none"
                        >
                            <Link href={adminWorkspacesIndex()} prefetch>
                                <Briefcase className="size-3.5" />
                                {t('Workspaces')}
                            </Link>
                        </Button>
                        <Button
                            asChild
                            variant="outline"
                            size="sm"
                            className="flex-1 sm:flex-none"
                        >
                            <Link href={adminUsersIndex()} prefetch>
                                <Users className="size-3.5" />
                                {t('Users')}
                            </Link>
                        </Button>
                        <Button
                            asChild
                            variant="outline"
                            size="sm"
                            className="flex-1 sm:flex-none"
                        >
                            <Link href={adminFailedJobs()} prefetch>
                                <AlertTriangle className="size-3.5" />
                                {t('Queue Failures')}
                            </Link>
                        </Button>
                    </div>
                </div>

                <div className="min-h-0 flex-1 overflow-y-auto">
                    <div className="space-y-4 p-4">
                        <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-6">
                            <MetricCard
                                label={t('Users')}
                                value={metrics.users.value.toLocaleString()}
                                sub={trendSub(
                                    metrics.users.last_30d ?? 0,
                                    metrics.users.prior_30d,
                                    undefined,
                                    t,
                                )}
                                icon={<Users className="size-4 text-sky-600" />}
                                iconTone="bg-sky-500/10"
                                series={metrics.users.series}
                                accent={ACCENT}
                                trendData={trend(
                                    metrics.users.last_30d ?? 0,
                                    metrics.users.prior_30d,
                                )}
                            />
                            <MetricCard
                                label={t('Workspaces')}
                                value={metrics.workspaces.value.toLocaleString()}
                                sub={trendSub(
                                    metrics.workspaces.last_30d ?? 0,
                                    metrics.workspaces.prior_30d,
                                    undefined,
                                    t,
                                )}
                                icon={
                                    <Briefcase className="size-4 text-violet-600" />
                                }
                                iconTone="bg-violet-500/10"
                                series={metrics.workspaces.series}
                                accent={ACCENT}
                                trendData={trend(
                                    metrics.workspaces.last_30d ?? 0,
                                    metrics.workspaces.prior_30d,
                                )}
                            />
                            <MetricCard
                                label={t('Agents')}
                                value={metrics.agents.value.toLocaleString()}
                                sub={t(':count published', {
                                    count: metrics.agents.published ?? 0,
                                })}
                                icon={<Bot className="size-4 text-amber-600" />}
                                iconTone="bg-amber-500/10"
                                series={metrics.agents.series}
                                accent={ACCENT}
                                trendData={trend(
                                    metrics.agents.last_30d ?? 0,
                                    metrics.agents.prior_30d,
                                )}
                            />
                            <MetricCard
                                label={t('Leads')}
                                value={metrics.leads.value.toLocaleString()}
                                sub={t(':count in last 30 days', {
                                    count: metrics.leads.last_30d ?? 0,
                                })}
                                icon={
                                    <Inbox className="size-4 text-emerald-600" />
                                }
                                iconTone="bg-emerald-500/10"
                                series={metrics.leads.series}
                                accent={ACCENT}
                                trendData={trend(
                                    metrics.leads.last_30d ?? 0,
                                    metrics.leads.prior_30d,
                                )}
                            />
                            <MetricCard
                                label={t('Conversations (30d)')}
                                value={metrics.conversations.value.toLocaleString()}
                                sub={trendSub(
                                    metrics.conversations.value,
                                    metrics.conversations.prior_30d,
                                    t('vs prior 30 days'),
                                    t,
                                )}
                                icon={
                                    <MessageSquare className="size-4 text-rose-600" />
                                }
                                iconTone="bg-rose-500/10"
                                series={metrics.conversations.series}
                                accent={ACCENT}
                                trendData={trend(
                                    metrics.conversations.value,
                                    metrics.conversations.prior_30d,
                                )}
                            />
                            <MetricCard
                                label={t('Messages (30d)')}
                                value={metrics.messages.value.toLocaleString()}
                                sub={trendSub(
                                    metrics.messages.value,
                                    metrics.messages.prior_30d,
                                    t('vs prior 30 days'),
                                    t,
                                )}
                                icon={
                                    <Sparkles className="size-4 text-fuchsia-600" />
                                }
                                iconTone="bg-fuchsia-500/10"
                                series={metrics.messages.series}
                                accent={ACCENT}
                                trendData={trend(
                                    metrics.messages.value,
                                    metrics.messages.prior_30d,
                                )}
                            />
                        </div>

                        <div className="grid gap-4 xl:grid-cols-[minmax(0,1.45fr)_minmax(320px,0.95fr)]">
                            <Card className="overflow-hidden p-0">
                                <div className="flex items-start justify-between gap-4 border-b px-4 py-4">
                                    <div>
                                        <p className="text-sm font-medium">
                                            {t('Platform report')}
                                        </p>
                                        <p className="mt-0.5 text-xs text-muted-foreground">
                                            {t(
                                                'Conversations are the primary line. Messages are the dotted line, so platform activity and support load stay visible together.',
                                            )}
                                        </p>
                                    </div>
                                    <div className="rounded-full border bg-background px-3 py-1 text-[11px] font-medium text-muted-foreground">
                                        {formatRange(window.from, window.to)}
                                    </div>
                                </div>

                                <div className="px-3 pt-4 pb-2 sm:px-4">
                                    <PlatformChart
                                        chart={chart}
                                        accent={ACCENT}
                                    />
                                </div>

                                <div className="grid gap-0 border-t sm:grid-cols-2 xl:grid-cols-5">
                                    <FooterMetric
                                        icon={<Users className="size-3.5" />}
                                        label={t('Total users')}
                                        value={metrics.users.value.toLocaleString()}
                                    />
                                    <FooterMetric
                                        icon={
                                            <Briefcase className="size-3.5" />
                                        }
                                        label={t('Workspaces')}
                                        value={metrics.workspaces.value.toLocaleString()}
                                    />
                                    <FooterMetric
                                        icon={<Bot className="size-3.5" />}
                                        label={t('Agents live')}
                                        value={`${metrics.agents.published ?? 0}/${metrics.agents.value || 0}`}
                                    />
                                    <FooterMetric
                                        icon={<Inbox className="size-3.5" />}
                                        label={t('Leads (30d)')}
                                        value={(
                                            metrics.leads.last_30d ?? 0
                                        ).toLocaleString()}
                                    />
                                    <FooterMetric
                                        icon={
                                            <MessageSquare className="size-3.5" />
                                        }
                                        label={t('Messages today')}
                                        value={right_now.messages_today.toLocaleString()}
                                    />
                                </div>
                            </Card>

                            <div className="grid gap-4">
                                <Card className="p-4">
                                    <div className="flex items-start justify-between gap-3">
                                        <div>
                                            <p className="text-sm font-medium">
                                                {t('Right now')}
                                            </p>
                                            <p className="mt-0.5 text-xs text-muted-foreground">
                                                {t(
                                                    'Live platform load across active customer conversations.',
                                                )}
                                            </p>
                                        </div>
                                        <span className="inline-flex items-center gap-1 rounded-full bg-rose-500/10 px-2 py-0.5 text-[10px] font-semibold text-rose-600 uppercase dark:text-rose-400">
                                            <span className="inline-block size-1.5 animate-pulse rounded-full bg-rose-500" />
                                            {t('Live')}
                                        </span>
                                    </div>

                                    <div className="mt-4 grid grid-cols-2 gap-2">
                                        <div className="rounded-xl border bg-background px-3 py-3">
                                            <p className="text-xs text-muted-foreground">
                                                {t('Active conversations')}
                                            </p>
                                            <p className="mt-1 text-2xl font-semibold text-foreground tabular-nums">
                                                {right_now.active_conversations.toLocaleString()}
                                            </p>
                                        </div>
                                        <div className="rounded-xl border bg-background px-3 py-3">
                                            <p className="text-xs text-muted-foreground">
                                                {t('Messages today')}
                                            </p>
                                            <p className="mt-1 text-2xl font-semibold text-foreground tabular-nums">
                                                {right_now.messages_today.toLocaleString()}
                                            </p>
                                        </div>
                                    </div>

                                    <p className="mt-3 text-[11px] text-muted-foreground">
                                        {t('Updated :time', {
                                            time: relativeTime(right_now.as_of),
                                        })}
                                    </p>
                                </Card>

                                <Card className="p-4">
                                    <div className="flex items-start justify-between gap-3">
                                        <div>
                                            <p className="text-sm font-medium">
                                                30-day activation funnel
                                            </p>
                                            <p className="mt-0.5 text-xs text-muted-foreground">
                                                From fresh signups to captured
                                                leads.
                                            </p>
                                        </div>
                                        <span className="rounded-full border bg-background px-3 py-1 text-[11px] font-medium text-muted-foreground">
                                            Funnel
                                        </span>
                                    </div>

                                    <div className="mt-4 space-y-3">
                                        {reports.funnel.map((stage) => (
                                            <FunnelRow
                                                key={stage.label}
                                                stage={stage}
                                                maxValue={Math.max(
                                                    ...reports.funnel.map(
                                                        (item) => item.value,
                                                    ),
                                                    1,
                                                )}
                                            />
                                        ))}
                                    </div>
                                </Card>
                            </div>
                        </div>

                        <div className="grid gap-4 xl:grid-cols-[minmax(0,1.45fr)_minmax(320px,0.95fr)]">
                            <Card className="overflow-hidden p-0">
                                <div className="flex flex-wrap items-center gap-2 border-b px-4 py-4">
                                    <div className="rounded-lg bg-violet-500/10 p-2">
                                        <Activity className="size-4 text-violet-600" />
                                    </div>
                                    <div>
                                        <h2 className="text-sm font-medium text-foreground">
                                            Growth mix
                                        </h2>
                                        <p className="text-xs text-muted-foreground">
                                            New users, workspaces, and agents
                                            created over the last 30 days.
                                        </p>
                                    </div>
                                    <div className="flex w-full items-center gap-3 text-xs text-muted-foreground sm:ms-auto sm:w-auto">
                                        <LegendDot
                                            color="#0ea5e9"
                                            label="Users"
                                        />
                                        <LegendDot
                                            color="#8b5cf6"
                                            label="Workspaces"
                                        />
                                        <LegendDot
                                            color="#f59e0b"
                                            label="Agents"
                                        />
                                    </div>
                                </div>
                                <div className="px-3 pt-4 pb-2 sm:px-4">
                                    <GrowthMixChart report={reports.growth} />
                                </div>
                            </Card>

                            <Card className="p-4">
                                <div className="flex items-start justify-between gap-3">
                                    <div>
                                        <p className="text-sm font-medium">
                                            Deployment health
                                        </p>
                                        <p className="mt-0.5 text-xs text-muted-foreground">
                                            Published agent activity and live
                                            workspace coverage.
                                        </p>
                                    </div>
                                    <div className="rounded-lg bg-sky-500/10 p-2">
                                        <Bot className="size-4 text-sky-600" />
                                    </div>
                                </div>

                                <div className="mt-4 grid gap-4">
                                    <div>
                                        <div className="flex items-end justify-between gap-3">
                                            <div>
                                                <p className="text-3xl font-semibold tracking-tight text-foreground tabular-nums">
                                                    {reports.deployment.utilization_rate.toFixed(
                                                        1,
                                                    )}
                                                    %
                                                </p>
                                                <p className="text-xs text-muted-foreground">
                                                    published agents active in
                                                    the last 30 days
                                                </p>
                                            </div>
                                            <p className="text-xs text-muted-foreground">
                                                {reports.deployment.active_published_agents.toLocaleString()}{' '}
                                                of{' '}
                                                {reports.deployment.published_agents.toLocaleString()}
                                            </p>
                                        </div>
                                        <ProgressBar
                                            value={
                                                reports.deployment
                                                    .utilization_rate
                                            }
                                            accentClass="bg-sky-500"
                                        />
                                    </div>
                                    <div>
                                        <div className="flex items-end justify-between gap-3">
                                            <div>
                                                <p className="text-3xl font-semibold tracking-tight text-foreground tabular-nums">
                                                    {reports.deployment.workspace_coverage_rate.toFixed(
                                                        1,
                                                    )}
                                                    %
                                                </p>
                                                <p className="text-xs text-muted-foreground">
                                                    workspaces generated live
                                                    traffic in the last 30 days
                                                </p>
                                            </div>
                                            <p className="text-xs text-muted-foreground">
                                                {reports.deployment.workspaces_with_traffic.toLocaleString()}{' '}
                                                of{' '}
                                                {metrics.workspaces.value.toLocaleString()}
                                            </p>
                                        </div>
                                        <ProgressBar
                                            value={
                                                reports.deployment
                                                    .workspace_coverage_rate
                                            }
                                            accentClass="bg-violet-500"
                                        />
                                    </div>
                                    <div className="grid gap-2 rounded-xl border bg-muted/30 p-3 text-sm text-muted-foreground">
                                        <div className="flex items-center justify-between gap-3">
                                            <span
                                                title={t(
                                                    'Counts agents with is_published=true that had ZERO non-playground visitor conversations in the last 30 days. Playground tests do not count toward "active".',
                                                )}
                                            >
                                                {t('Inactive published agents')}
                                            </span>
                                            <span className="font-medium text-foreground">
                                                {reports.deployment.inactive_published_agents.toLocaleString()}
                                                <span className="ms-1 text-muted-foreground">
                                                    /{' '}
                                                    {reports.deployment.published_agents.toLocaleString()}
                                                </span>
                                            </span>
                                        </div>
                                        <div className="flex items-center justify-between gap-3">
                                            <span>
                                                Workspaces with published agents
                                            </span>
                                            <span className="font-medium text-foreground">
                                                {reports.deployment.published_workspaces.toLocaleString()}
                                            </span>
                                        </div>
                                        <div className="flex items-center justify-between gap-3">
                                            <span>
                                                Avg conversations / active agent
                                            </span>
                                            <span className="font-medium text-foreground">
                                                {reports.deployment.avg_conversations_per_active_agent.toFixed(
                                                    1,
                                                )}
                                            </span>
                                        </div>
                                    </div>
                                </div>
                            </Card>
                        </div>

                        <div className="grid gap-4 xl:grid-cols-2">
                            <ReportMetricCard
                                title="Lead capture rate"
                                value={`${reports.lead_capture.value.toFixed(1)}%`}
                                helper={`${reports.lead_capture.leads.toLocaleString()} leads from ${reports.lead_capture.conversations.toLocaleString()} conversations`}
                                footer={`${reports.lead_capture.last_7d.toFixed(1)}% over the last 7 days`}
                                accent="#10b981"
                                trendData={trend(
                                    reports.lead_capture.value,
                                    reports.lead_capture.prior_value,
                                )}
                                icon={
                                    <Inbox className="size-4 text-emerald-600" />
                                }
                                iconTone="bg-emerald-500/10"
                                series={reports.lead_capture.series}
                            />
                            <ReportMetricCard
                                title="Conversation depth"
                                value={reports.engagement.value.toFixed(2)}
                                helper={`${reports.engagement.messages.toLocaleString()} messages across ${reports.engagement.conversations.toLocaleString()} conversations`}
                                footer={`${reports.engagement.last_7d.toFixed(2)} messages / conversation in the last 7 days`}
                                accent="#f97316"
                                trendData={trend(
                                    reports.engagement.value,
                                    reports.engagement.prior_value,
                                )}
                                icon={
                                    <MessageSquare className="size-4 text-orange-600" />
                                }
                                iconTone="bg-orange-500/10"
                                series={reports.engagement.series}
                            />
                        </div>

                        <QueueHealthCard health={queueHealth} t={t} />

                        <div className="grid grid-cols-1 gap-4 xl:grid-cols-2">
                            <Card className="p-0">
                                <div className="flex items-center gap-2 border-b px-4 py-3">
                                    <div className="rounded-lg bg-sky-500/10 p-1.5">
                                        <Users className="size-3.5 text-sky-600" />
                                    </div>
                                    <h2 className="text-sm font-medium">
                                        Recent signups
                                    </h2>
                                </div>
                                <div className="divide-y">
                                    <div className="hidden grid-cols-[1fr_1.4fr_120px_120px] gap-3 px-4 py-2 text-[11px] tracking-wider text-muted-foreground uppercase sm:grid">
                                        <span>Name</span>
                                        <span>Email</span>
                                        <span>Role</span>
                                        <span>Signed up</span>
                                    </div>
                                    {recent_signups.map((u) => (
                                        <div
                                            key={u.id}
                                            className="grid gap-2 px-4 py-3 text-sm sm:grid-cols-[1fr_1.4fr_120px_120px] sm:items-center sm:gap-3"
                                        >
                                            <div className="flex min-w-0 items-center gap-2">
                                                <span className="inline-flex size-6 items-center justify-center rounded-full bg-rose-500/15 text-[10px] font-semibold text-rose-700 dark:text-rose-400">
                                                    {initials(u.name)}
                                                </span>
                                                <span className="truncate font-medium">
                                                    {u.name}
                                                </span>
                                            </div>
                                            <span className="truncate text-muted-foreground">
                                                {u.email}
                                            </span>
                                            <span>
                                                <RolePill role={u.role} />
                                            </span>
                                            <span className="text-xs text-muted-foreground">
                                                {relativeTime(u.created_at)}
                                            </span>
                                        </div>
                                    ))}
                                </div>
                                <div className="border-t px-4 py-3">
                                    <Link
                                        href={adminUsersIndex()}
                                        prefetch
                                        className="text-xs text-muted-foreground hover:text-foreground"
                                    >
                                        View all users →
                                    </Link>
                                </div>
                            </Card>

                            <Card className="p-0">
                                <div className="flex flex-wrap items-center gap-2 border-b px-4 py-3">
                                    <div className="rounded-lg bg-amber-500/10 p-1.5">
                                        <Activity className="size-3.5 text-amber-600" />
                                    </div>
                                    <h2 className="text-sm font-medium">
                                        Platform activity
                                    </h2>
                                    <Link
                                        href={adminAgentsIndex()}
                                        prefetch
                                        className="text-xs text-muted-foreground hover:text-foreground sm:ms-auto"
                                    >
                                        View all activity →
                                    </Link>
                                </div>
                                <div className="divide-y">
                                    {activity.length === 0 ? (
                                        <p className="px-4 py-6 text-sm text-muted-foreground">
                                            No recent activity.
                                        </p>
                                    ) : (
                                        activity.map((a, i) => (
                                            <div
                                                key={i}
                                                className="flex items-start gap-3 px-4 py-3 text-sm"
                                            >
                                                <div className="mt-0.5 rounded-md bg-muted p-1.5">
                                                    <ActivityIcon
                                                        kind={a.kind}
                                                    />
                                                </div>
                                                <div className="min-w-0 flex-1">
                                                    <p className="truncate font-medium">
                                                        {a.title}
                                                    </p>
                                                    {a.subtitle && (
                                                        <p className="truncate text-xs text-muted-foreground">
                                                            {a.subtitle}
                                                        </p>
                                                    )}
                                                </div>
                                                <span className="text-xs text-muted-foreground">
                                                    {relativeTime(a.at)}
                                                </span>
                                            </div>
                                        ))
                                    )}
                                </div>
                            </Card>
                        </div>
                    </div>
                </div>
            </div>
        </AdminLayout>
    );
}

function LegendDot({ color, label }: { color: string; label: string }) {
    return (
        <span className="inline-flex items-center gap-1.5">
            <span
                className="inline-block size-2 rounded-full"
                style={{ background: color }}
            />
            {label}
        </span>
    );
}

function GrowthMixChart({ report }: { report: GrowthReport }) {
    const w = 720;
    const h = 220;
    const padX = 28;
    const padY = 16;
    const innerW = w - padX * 2;
    const innerH = h - padY * 2;
    const series = [report.users, report.workspaces, report.agents];
    const max = Math.max(1, ...series.flat());
    const stepX = innerW / Math.max(1, report.days.length - 1);

    const toPath = (values: number[]) =>
        values
            .map(
                (value, index) =>
                    `${(padX + index * stepX).toFixed(1)},${(padY + innerH - (value / max) * innerH).toFixed(1)}`,
            )
            .join(' ');

    const xLabels = [
        0,
        Math.floor(report.days.length / 4),
        Math.floor(report.days.length / 2),
        Math.floor((report.days.length * 3) / 4),
        report.days.length - 1,
    ]
        .map((index) => ({
            index,
            label: report.days[index]
                ? new Date(report.days[index]).toLocaleDateString('en-US', {
                      month: 'short',
                      day: 'numeric',
                  })
                : '',
        }))
        .filter((item) => item.label !== '');

    return (
        <svg
            viewBox={`0 0 ${w} ${h + 24}`}
            className="h-64 w-full"
            preserveAspectRatio="xMidYMid meet"
            aria-hidden
        >
            {[0, 0.25, 0.5, 0.75, 1].map((tick, index) => {
                const tickValue = Math.round(max * tick);
                const y = padY + innerH - (tickValue / max) * innerH;

                return (
                    <g key={index}>
                        <line
                            x1={padX}
                            y1={y}
                            x2={padX + innerW}
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
                            {tickValue}
                        </text>
                    </g>
                );
            })}
            <polyline
                points={toPath(report.users)}
                fill="none"
                stroke="#0ea5e9"
                strokeWidth="2"
                strokeLinecap="round"
                strokeLinejoin="round"
            />
            <polyline
                points={toPath(report.workspaces)}
                fill="none"
                stroke="#8b5cf6"
                strokeWidth="2"
                strokeLinecap="round"
                strokeLinejoin="round"
            />
            <polyline
                points={toPath(report.agents)}
                fill="none"
                stroke="#f59e0b"
                strokeWidth="2"
                strokeLinecap="round"
                strokeLinejoin="round"
            />
            {xLabels.map(({ index, label }) => (
                <text
                    key={index}
                    x={padX + index * stepX}
                    y={h + 16}
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

function FunnelRow({
    stage,
    maxValue,
}: {
    stage: FunnelStage;
    maxValue: number;
}) {
    const width = maxValue > 0 ? (stage.value / maxValue) * 100 : 0;

    return (
        <div>
            <div className="flex items-baseline justify-between gap-3">
                <div>
                    <p className="text-sm font-medium text-foreground">
                        {stage.label}
                    </p>
                    <p className="text-xs text-muted-foreground">
                        {stage.helper}
                    </p>
                </div>
                <p className="text-sm font-semibold text-foreground tabular-nums">
                    {stage.value.toLocaleString()}
                </p>
            </div>
            <div className="mt-2 h-2 rounded-full bg-muted">
                <div
                    className="h-2 rounded-full bg-emerald-500"
                    style={{
                        width: `${Math.max(width, stage.value > 0 ? 10 : 0)}%`,
                    }}
                />
            </div>
        </div>
    );
}

function ReportMetricCard({
    title,
    value,
    helper,
    footer,
    accent,
    trendData,
    icon,
    iconTone,
    series,
}: {
    title: string;
    value: string;
    helper: string;
    footer: string;
    accent: string;
    trendData?: { dir: 'up' | 'down' | 'flat'; pct: number };
    icon: ReactNode;
    iconTone: string;
    series: number[];
}) {
    return (
        <Card className="p-4 sm:p-5">
            <div className="flex items-start justify-between gap-3">
                <div className={`rounded-lg p-2 ${iconTone}`}>{icon}</div>
                {trendData && trendData.dir !== 'flat' ? (
                    <span
                        className={`inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[11px] font-medium ${
                            trendData.dir === 'up'
                                ? 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-400'
                                : 'bg-rose-500/10 text-rose-700 dark:text-rose-400'
                        }`}
                    >
                        {trendData.dir === 'up' ? 'Up' : 'Down'} {trendData.pct}
                        %
                    </span>
                ) : null}
            </div>
            <p className="mt-3 text-xs font-medium tracking-wider text-muted-foreground uppercase">
                {title}
            </p>
            <p className="mt-1 text-3xl font-semibold tracking-tight text-foreground tabular-nums">
                {value}
            </p>
            <p className="mt-1 text-sm text-muted-foreground">{helper}</p>
            <div className="mt-4 flex items-end justify-between gap-4">
                <p className="max-w-[14rem] text-xs text-muted-foreground">
                    {footer}
                </p>
                <Sparkline
                    values={series}
                    accent={accent}
                    width={112}
                    height={36}
                />
            </div>
        </Card>
    );
}

function ProgressBar({
    value,
    accentClass,
}: {
    value: number;
    accentClass: string;
}) {
    return (
        <div className="mt-2 h-2 rounded-full bg-muted">
            <div
                className={`h-2 rounded-full ${accentClass}`}
                style={{ width: `${Math.min(Math.max(value, 0), 100)}%` }}
            />
        </div>
    );
}

function trendSub(
    current: number,
    prior: number,
    fallback?: string,
    translate?: (key: string) => string,
): string {
    const trendData = trend(current, prior);
    const localizedFallback =
        fallback ??
        (translate ? translate('vs prior 30 days') : 'vs prior 30 days');

    if (trendData.dir === 'flat') {
        return localizedFallback;
    }

    const arrow = trendData.dir === 'up' ? '↑' : '↓';

    return `${arrow} ${trendData.pct}% ${localizedFallback}`;
}

function initials(name: string): string {
    const parts = name.trim().split(/\s+/);

    if (parts.length === 1) {
        return parts[0]?.slice(0, 2).toUpperCase() ?? '?';
    }

    return (
        (parts[0]?.[0] ?? '') + (parts[parts.length - 1]?.[0] ?? '')
    ).toUpperCase();
}

function RolePill({ role }: { role: string }) {
    const isAdmin = role === 'super_admin';

    return (
        <span
            className={`rounded px-2 py-0.5 text-[11px] ${
                isAdmin
                    ? 'bg-rose-500/15 text-rose-700 dark:text-rose-400'
                    : 'bg-muted text-muted-foreground'
            }`}
        >
            {role}
        </span>
    );
}

function ActivityIcon({ kind }: { kind: string }): ReactNode {
    if (kind === 'agent.published') {
        return <Bot className="size-3.5 text-emerald-600" />;
    }

    if (kind === 'workspace.created') {
        return <Briefcase className="size-3.5 text-violet-600" />;
    }

    return <Activity className="size-3.5 text-muted-foreground" />;
}
