import { Link } from '@inertiajs/react';
import { Activity, AlertTriangle, Server } from 'lucide-react';
import type { ReactNode } from 'react';
import { Card } from '@/components/ui/card';
import { failed as adminFailedJobs } from '@/routes/admin/jobs';

export type RecentTick = {
    at: string | null;
    processed: number;
    failed: number;
    remaining: number;
    ms: number;
};

export type RecentFailure = {
    uuid: string;
    job: string;
    queue: string;
    agent_id: string | null;
    agent_name: string | null;
    failed_at: string | null;
    exception_first_line: string;
};

export type JobRunStatus = 'running' | 'done' | 'failed';

export type RecentRun = {
    uuid: string;
    job: string;
    queue: string;
    agent_id: string | null;
    agent_name: string | null;
    status: JobRunStatus;
    started_at: string | null;
    finished_at: string | null;
    duration_ms: number | null;
    exception_first_line: string | null;
};

export type QueueHealthShape = {
    last_tick_at: string | null;
    seconds_since_last_tick: number | null;
    liveness: 'live' | 'stale' | 'dead' | 'unknown';
    ticks_last_hour: number;
    ticks_last_24h: number;
    jobs_processed_last_hour: number;
    jobs_processed_last_24h: number;
    pending_jobs: number;
    failed_jobs: number;
    recent_ticks: RecentTick[];
    recent_failures: RecentFailure[];
    recent_runs: RecentRun[];
};

type T = (
    key: string,
    replacements?: Record<string, string | number>,
) => string;

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

/**
 * Live queue + cron-tick health widget. Same component on the admin
 * dashboard (compact, polled every 8s) and the dedicated
 * /admin/queue-health page (full-width view). Defensive against the
 * `health` prop being missing — renders a "loading" placeholder
 * instead of throwing so a partial deploy never blanks the dashboard.
 */
export function QueueHealthCard({
    health,
    t,
}: {
    health: QueueHealthShape | null | undefined;
    t: T;
}): ReactNode {
    if (!health) {
        return (
            <Card className="p-4 text-sm text-muted-foreground">
                <div className="flex items-center gap-2">
                    <Server className="size-3.5" />
                    {t('Queue health is loading…')}
                </div>
            </Card>
        );
    }

    const liveness = health.liveness;
    const tone: Record<typeof liveness, { dot: string; label: string }> = {
        live: { dot: 'bg-emerald-500', label: t('Live — firing on schedule') },
        stale: { dot: 'bg-amber-500', label: t('Stale — last tick > 90s ago') },
        dead: {
            dot: 'bg-rose-500',
            label: t('Dead — no tick in last 5 minutes'),
        },
        unknown: { dot: 'bg-slate-400', label: t('Waiting for first tick') },
    };
    const livenessTone = tone[liveness];
    const lastTickRel = relativeTime(health.last_tick_at);

    return (
        <Card className="p-0">
            <div className="flex flex-wrap items-center gap-3 border-b px-4 py-3">
                <div className="rounded-lg bg-indigo-500/10 p-1.5">
                    <Server className="size-3.5 text-indigo-600" />
                </div>
                <h2 className="text-sm font-medium">{t('Queue health')}</h2>
                <span
                    className="ms-auto inline-flex items-center gap-2 rounded-full border bg-background px-2.5 py-1 text-xs"
                    title={livenessTone.label}
                >
                    <span
                        className={`size-2 rounded-full ${livenessTone.dot} ${liveness === 'live' ? 'animate-pulse' : ''}`}
                    />
                    <span className="font-medium">{livenessTone.label}</span>
                </span>
            </div>

            <div className="grid grid-cols-2 gap-3 px-4 py-3 sm:grid-cols-4">
                <div>
                    <div className="text-[11px] tracking-wider text-muted-foreground uppercase">
                        {t('Last tick')}
                    </div>
                    <div className="mt-1 text-base font-semibold tabular-nums">
                        {lastTickRel || t('No ticks yet')}
                    </div>
                    <div className="text-xs text-muted-foreground">
                        {t('Cron expected every 60s')}
                    </div>
                </div>
                <div>
                    <div className="text-[11px] tracking-wider text-muted-foreground uppercase">
                        {t('Pending jobs')}
                    </div>
                    <div
                        className={`mt-1 text-base font-semibold tabular-nums ${health.pending_jobs > 50 ? 'text-amber-600' : 'text-foreground'}`}
                    >
                        {health.pending_jobs.toLocaleString()}
                    </div>
                    <div className="text-xs text-muted-foreground">
                        {t('Waiting to be picked up')}
                    </div>
                </div>
                <div>
                    <div className="text-[11px] tracking-wider text-muted-foreground uppercase">
                        {t('Processed (1h)')}
                    </div>
                    <div className="mt-1 text-base font-semibold tabular-nums">
                        {health.jobs_processed_last_hour.toLocaleString()}
                    </div>
                    <div className="text-xs text-muted-foreground">
                        {t(':ticks ticks · expected ≈ 60', {
                            ticks: health.ticks_last_hour,
                        })}
                    </div>
                </div>
                <div>
                    <div className="text-[11px] tracking-wider text-muted-foreground uppercase">
                        {t('Failed jobs')}
                    </div>
                    <div
                        className={`mt-1 text-base font-semibold tabular-nums ${health.failed_jobs > 0 ? 'text-rose-600' : 'text-foreground'}`}
                    >
                        {health.failed_jobs.toLocaleString()}
                    </div>
                    <Link
                        href={adminFailedJobs()}
                        className="text-xs text-indigo-600 hover:underline"
                        prefetch
                    >
                        {t('Open failed queue →')}
                    </Link>
                </div>
            </div>

            {health.recent_ticks.length > 0 ? (
                <div className="border-t">
                    <div className="hidden grid-cols-[1fr_80px_80px_80px_80px] gap-3 px-4 py-2 text-[11px] tracking-wider text-muted-foreground uppercase sm:grid">
                        <span>{t('When')}</span>
                        <span className="text-end">{t('Processed')}</span>
                        <span className="text-end">{t('Failed')}</span>
                        <span className="text-end">{t('Remaining')}</span>
                        <span className="text-end">{t('Elapsed')}</span>
                    </div>
                    <div className="max-h-64 divide-y overflow-y-auto">
                        {health.recent_ticks.map((tick, idx) => (
                            <div
                                key={`${tick.at ?? 'unknown'}-${idx}`}
                                className="grid gap-1 px-4 py-2 text-xs tabular-nums sm:grid-cols-[1fr_80px_80px_80px_80px] sm:items-center sm:gap-3"
                            >
                                <span className="text-muted-foreground">
                                    {relativeTime(tick.at) || '—'}
                                </span>
                                <span className="text-end">
                                    {tick.processed}
                                </span>
                                <span
                                    className={`text-end ${tick.failed > 0 ? 'text-rose-600' : ''}`}
                                >
                                    {tick.failed}
                                </span>
                                <span className="text-end">
                                    {tick.remaining}
                                </span>
                                <span className="text-end text-muted-foreground">
                                    {tick.ms} ms
                                </span>
                            </div>
                        ))}
                    </div>
                </div>
            ) : null}

            {health.recent_failures.length > 0 ? (
                <div className="border-t bg-rose-500/[0.04]">
                    <div className="flex items-center justify-between border-b border-rose-500/15 px-4 py-2">
                        <div className="flex items-center gap-2 text-xs font-medium text-rose-700 dark:text-rose-400">
                            <AlertTriangle className="size-3.5" />
                            {t('Recent failures (paste to your dev team)')}
                        </div>
                        <Link
                            href={adminFailedJobs()}
                            className="text-xs text-rose-700 hover:underline dark:text-rose-400"
                            prefetch
                        >
                            {t('Open full failed queue →')}
                        </Link>
                    </div>
                    <div className="max-h-64 divide-y divide-rose-500/10 overflow-y-auto">
                        {health.recent_failures.map((fail) => (
                            <div
                                key={fail.uuid}
                                className="grid gap-1 px-4 py-2.5 text-xs"
                            >
                                <div className="flex items-center justify-between gap-3">
                                    <span className="truncate font-mono text-[11px] font-medium text-foreground">
                                        {fail.job}
                                    </span>
                                    <span className="shrink-0 text-[11px] text-muted-foreground tabular-nums">
                                        {fail.failed_at
                                            ? relativeTime(fail.failed_at) ||
                                              fail.failed_at
                                            : '—'}
                                    </span>
                                </div>
                                <p
                                    className="text-[11px] leading-5 break-words text-rose-800 dark:text-rose-300"
                                    title={fail.exception_first_line}
                                >
                                    {fail.exception_first_line}
                                </p>
                                <div className="flex flex-wrap items-center gap-3 pt-1 text-[10px] text-muted-foreground">
                                    {fail.agent_name || fail.agent_id ? (
                                        <span
                                            className="inline-flex items-center gap-1 rounded-full bg-sky-500/10 px-2 py-0.5 text-[10px] font-medium text-sky-700 dark:text-sky-300"
                                            title={fail.agent_id ?? undefined}
                                        >
                                            {t('Agent')}:{' '}
                                            {fail.agent_name ?? fail.agent_id}
                                        </span>
                                    ) : null}
                                    <span>
                                        {t('Queue: :queue', {
                                            queue: fail.queue || 'default',
                                        })}
                                    </span>
                                    <span className="font-mono">
                                        uuid: {fail.uuid.slice(0, 8)}
                                    </span>
                                </div>
                            </div>
                        ))}
                    </div>
                </div>
            ) : null}

            {health.recent_runs.length > 0 ? (
                <div className="border-t">
                    <div className="flex items-center justify-between border-b px-4 py-2">
                        <div className="flex items-center gap-2 text-xs font-medium text-foreground">
                            <Activity className="size-3.5 text-emerald-600" />
                            {t('Live job feed')}
                        </div>
                        <div className="flex items-center gap-3 text-[11px] text-muted-foreground">
                            <span className="inline-flex items-center gap-1">
                                <span className="inline-block size-1.5 rounded-full bg-amber-500" />
                                {t('Running')}
                            </span>
                            <span className="inline-flex items-center gap-1">
                                <span className="inline-block size-1.5 rounded-full bg-emerald-500" />
                                {t('Done')}
                            </span>
                            <span className="inline-flex items-center gap-1">
                                <span className="inline-block size-1.5 rounded-full bg-rose-500" />
                                {t('Failed')}
                            </span>
                        </div>
                    </div>
                    <div className="max-h-[28rem] divide-y overflow-y-auto font-mono">
                        {health.recent_runs.map((run) => (
                            <JobRunRow key={run.uuid} run={run} t={t} />
                        ))}
                    </div>
                </div>
            ) : null}
        </Card>
    );
}

function JobRunRow({ run, t }: { run: RecentRun; t: T }): ReactNode {
    const statusTone: Record<JobRunStatus, string> = {
        running: 'text-amber-600 dark:text-amber-400',
        done: 'text-emerald-600 dark:text-emerald-400',
        failed: 'text-rose-600 dark:text-rose-400',
    };
    const statusLabel: Record<JobRunStatus, string> = {
        running: t('RUNNING'),
        done: t('DONE'),
        failed: t('FAIL'),
    };
    const started = run.started_at ? new Date(run.started_at) : null;
    const startedLabel = started
        ? `${started.toLocaleDateString('en-CA')} ${started.toLocaleTimeString('en-GB', { hour12: false })}`
        : '—';
    const durationLabel =
        run.duration_ms === null
            ? ''
            : run.duration_ms >= 1000
              ? `${(run.duration_ms / 1000).toFixed(0)}s`
              : `${run.duration_ms} ms`;

    return (
        <div
            className="grid gap-1 px-4 py-1.5 text-[11px]"
            title={run.exception_first_line ?? undefined}
        >
            <div className="flex items-center gap-3">
                <span className="shrink-0 text-muted-foreground tabular-nums">
                    {startedLabel}
                </span>
                <span className="min-w-0 flex-1 truncate text-emerald-700 dark:text-emerald-300">
                    {run.job}
                </span>
                {run.agent_name || run.agent_id ? (
                    <span
                        className="shrink-0 rounded bg-sky-500/10 px-1.5 py-0.5 text-[10px] font-medium text-sky-700 dark:text-sky-300"
                        title={run.agent_id ?? undefined}
                    >
                        {run.agent_name ?? run.agent_id}
                    </span>
                ) : null}
                <span className="shrink-0 text-muted-foreground tabular-nums">
                    {durationLabel}
                </span>
                <span
                    className={`shrink-0 font-semibold ${statusTone[run.status]}`}
                >
                    {statusLabel[run.status]}
                </span>
            </div>
            {run.status === 'failed' && run.exception_first_line ? (
                <p className="pl-0 text-[10px] leading-4 break-words text-rose-700 dark:text-rose-400">
                    {run.exception_first_line}
                </p>
            ) : null}
        </div>
    );
}
