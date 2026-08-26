import { Head, Link } from '@inertiajs/react';
import {
    AlertTriangle,
    CheckCircle2,
    Cloud,
    Cog,
    Database,
    HelpCircle,
    Workflow,
    XCircle,
} from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { useT } from '@/lib/i18n';
import type { BreadcrumbItem } from '@/types';

type Status = 'ok' | 'warn' | 'fail';

type LlmStatus = {
    provider: string;
    model: string | null;
    ok: boolean;
    hint: string | null;
};

type EmbeddingStatus = {
    configured: boolean;
    hint: string | null;
};

type CloudflareStatus = {
    configured: boolean;
    account_id_present: boolean;
    token_present: boolean;
    hint: string | null;
};

type QueueStatus = {
    driver: string;
    pending: number;
    failed: number;
    liveness: 'live' | 'stale' | 'dead' | 'unknown' | 'inline';
    seconds_since_tick: number | null;
    last_tick_at: string | null;
    hint: string | null;
};

type SourceRow = {
    id: string;
    agent_id: string;
    type: string | null;
    status: string | null;
    error: string | null;
    created_at: string | null;
};

type EnvSummary = {
    app_env: string;
    app_url: string;
    app_debug: boolean;
    version: string;
};

type Props = {
    llm: LlmStatus;
    embedding_fallback: EmbeddingStatus;
    cloudflare: CloudflareStatus;
    queue: QueueStatus;
    sources: SourceRow[];
    env: EnvSummary;
};

function StatusIcon({ status }: { status: Status }) {
    if (status === 'ok') {
        return <CheckCircle2 className="size-4 text-emerald-600" />;
    }

    if (status === 'warn') {
        return <AlertTriangle className="size-4 text-amber-600" />;
    }

    return <XCircle className="size-4 text-red-600" />;
}

function CheckCard({
    icon,
    title,
    status,
    detail,
    hint,
}: {
    icon: React.ReactNode;
    title: string;
    status: Status;
    detail: string;
    hint: string | null;
}) {
    return (
        <Card className="flex flex-col gap-2 p-4">
            <div className="flex items-center gap-2">
                <span className="flex size-7 shrink-0 items-center justify-center rounded-md border bg-background text-muted-foreground">
                    {icon}
                </span>
                <h3 className="flex-1 text-sm font-medium text-foreground">
                    {title}
                </h3>
                <StatusIcon status={status} />
            </div>
            <p className="text-xs text-muted-foreground">{detail}</p>
            {hint && (
                <p className="rounded-md border-l-2 border-amber-300 bg-amber-50 px-2 py-1.5 text-[11px] leading-5 text-amber-900 dark:border-amber-700 dark:bg-amber-900/20 dark:text-amber-200">
                    {hint}
                </p>
            )}
        </Card>
    );
}

type ProbeResult = {
    ok: boolean;
    provider: string;
    model: string;
    content?: string;
    exception?: string;
    message?: string;
    elapsed_ms: number;
};

function LlmProbe() {
    const { t } = useT();
    const [busy, setBusy] = useState(false);
    const [result, setResult] = useState<ProbeResult | null>(null);

    const run = async () => {
        setBusy(true);
        setResult(null);
        const csrf =
            document
                .querySelector('meta[name="csrf-token"]')
                ?.getAttribute('content') ?? '';

        try {
            const response = await fetch('/app/system-health/probe-llm', {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': csrf,
                    Accept: 'application/json',
                },
            });
            const json = (await response.json()) as ProbeResult;
            setResult(json);
        } catch (e) {
            setResult({
                ok: false,
                provider: '?',
                model: '?',
                exception: 'NetworkError',
                message: e instanceof Error ? e.message : 'probe failed',
                elapsed_ms: 0,
            });
        } finally {
            setBusy(false);
        }
    };

    return (
        <Card className="p-4">
            <div className="flex items-center justify-between gap-2">
                <div>
                    <h3 className="text-sm font-medium">
                        {t('Probe LLM model')}
                    </h3>
                    <p className="text-xs text-muted-foreground">
                        {t(
                            'Send a 1-token request to the bound provider + model and show the verbatim response (or error) so you can pin down which model name is failing.',
                        )}
                    </p>
                </div>
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    onClick={run}
                    disabled={busy}
                >
                    {busy ? t('Probing…') : t('Run probe')}
                </Button>
            </div>
            {result && (
                <div
                    className={
                        'mt-3 rounded-md border p-2 text-xs ' +
                        (result.ok
                            ? 'border-emerald-300 bg-emerald-50 text-emerald-900 dark:border-emerald-700 dark:bg-emerald-900/20 dark:text-emerald-200'
                            : 'border-red-300 bg-red-50 text-red-900 dark:border-red-700 dark:bg-red-900/20 dark:text-red-200')
                    }
                >
                    <p>
                        <strong>{result.ok ? t('OK') : t('Failed')}</strong> ·{' '}
                        {result.provider} · <code>{result.model}</code> ·{' '}
                        {result.elapsed_ms}ms
                    </p>
                    {result.ok ? (
                        <pre className="mt-1 overflow-x-auto font-mono text-[11px] whitespace-pre-wrap">
                            {result.content}
                        </pre>
                    ) : (
                        <pre className="mt-1 overflow-x-auto font-mono text-[11px] whitespace-pre-wrap">
                            [{result.exception}] {result.message}
                        </pre>
                    )}
                </div>
            )}
        </Card>
    );
}

export default function SystemHealthPage({
    llm,
    embedding_fallback,
    cloudflare,
    queue,
    sources,
    env,
}: Props) {
    const { t } = useT();
    const breadcrumbs: BreadcrumbItem[] = [
        { title: t('System health'), href: '/app/system-health' },
    ];

    const llmStatus: Status = llm.ok ? 'ok' : 'fail';
    const embeddingStatus: Status = embedding_fallback.configured
        ? 'ok'
        : 'warn';
    const cloudflareStatus: Status = cloudflare.configured ? 'ok' : 'warn';
    const queueStatus: Status = (() => {
        if (queue.liveness === 'dead') {
            return 'fail';
        }

        if (queue.liveness === 'stale' || queue.liveness === 'unknown') {
            return 'warn';
        }

        if (queue.failed > 0) {
            return 'warn';
        }

        if (queue.driver === 'sync') {
            return 'warn';
        }

        return 'ok';
    })();

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('System health')} />
            <div className="flex flex-1 flex-col gap-4 p-4">
                <div>
                    <h1 className="text-2xl font-semibold tracking-tight">
                        {t('System health')}
                    </h1>
                    <p className="mt-1 text-sm text-muted-foreground">
                        {t(
                            'Read-only check of provider creds, queue worker, and recent crawl failures. Surfaces gaps that block indexing or replies before you have to open the failed-jobs table.',
                        )}
                    </p>
                </div>

                <div className="grid gap-3 lg:grid-cols-2">
                    <div className="grid gap-2">
                        <CheckCard
                            icon={<Cog className="size-3.5" />}
                            title={t('LLM provider')}
                            status={llmStatus}
                            detail={t('Resolved: :provider', {
                                provider: llm.provider,
                            })}
                            hint={llm.hint}
                        />
                        <LlmProbe />
                    </div>
                    <CheckCard
                        icon={<Cloud className="size-3.5" />}
                        title={t('Cloudflare creds')}
                        status={cloudflareStatus}
                        detail={
                            cloudflare.configured
                                ? t('Account + token present')
                                : t('Missing creds')
                        }
                        hint={cloudflare.hint}
                    />
                    <CheckCard
                        icon={<Workflow className="size-3.5" />}
                        title={t('Embedding fallback')}
                        status={embeddingStatus}
                        detail={
                            embedding_fallback.configured
                                ? t('OpenAI key configured')
                                : t('No fallback set')
                        }
                        hint={embedding_fallback.hint}
                    />
                    <CheckCard
                        icon={<Database className="size-3.5" />}
                        title={t('Queue worker')}
                        status={queueStatus}
                        detail={[
                            t(
                                'Driver: :driver · pending :pending · failed :failed',
                                {
                                    driver: queue.driver,
                                    pending: queue.pending,
                                    failed: queue.failed,
                                },
                            ),
                            queue.liveness === 'inline'
                                ? t('Liveness: inline (sync driver)')
                                : queue.seconds_since_tick === null
                                  ? t('Liveness: :state · no ticks recorded', {
                                        state: queue.liveness,
                                    })
                                  : t(
                                        'Liveness: :state · last tick :seconds s ago',
                                        {
                                            state: queue.liveness,
                                            seconds: queue.seconds_since_tick,
                                        },
                                    ),
                        ].join(' · ')}
                        hint={queue.hint}
                    />
                </div>

                <Card className="p-4">
                    <h2 className="text-sm font-medium">
                        {t('Recent source problems')}
                    </h2>
                    <p className="mt-1 text-xs text-muted-foreground">
                        {t(
                            'Sources stuck in "crawling" or marked "failed" in this workspace. Most often: rate-limited embedding provider, unreachable URL, or queue worker offline.',
                        )}
                    </p>
                    {sources.length === 0 ? (
                        <p className="mt-3 text-xs text-muted-foreground">
                            {t(
                                'No problematic sources. Indexing pipeline looks healthy.',
                            )}
                        </p>
                    ) : (
                        <ul className="mt-3 grid gap-2 text-xs">
                            {sources.map((s) => (
                                <li
                                    key={s.id}
                                    className="grid gap-1 rounded-md border bg-muted/20 p-2"
                                >
                                    <div className="flex items-center gap-2">
                                        <span
                                            className={
                                                s.status === 'failed'
                                                    ? 'rounded bg-red-100 px-1.5 py-0.5 text-[10px] font-semibold text-red-800'
                                                    : 'rounded bg-amber-100 px-1.5 py-0.5 text-[10px] font-semibold text-amber-800'
                                            }
                                        >
                                            {s.status}
                                        </span>
                                        <Link
                                            href={`/app/agents/${s.agent_id}/sources`}
                                            className="font-mono text-[11px] underline-offset-2 hover:underline"
                                        >
                                            {s.type ?? 'source'} · {s.id}
                                        </Link>
                                    </div>
                                    {s.error && (
                                        <pre className="overflow-x-auto rounded bg-background p-2 font-mono text-[10px] text-muted-foreground">
                                            {s.error}
                                        </pre>
                                    )}
                                </li>
                            ))}
                        </ul>
                    )}
                </Card>

                <Card className="grid gap-2 p-4 text-xs">
                    <h2 className="text-sm font-medium">{t('Environment')}</h2>
                    <dl className="grid gap-1.5">
                        <div className="flex justify-between gap-3">
                            <dt className="text-muted-foreground">APP_ENV</dt>
                            <dd className="font-mono">{env.app_env}</dd>
                        </div>
                        <div className="flex justify-between gap-3">
                            <dt className="text-muted-foreground">APP_URL</dt>
                            <dd className="truncate font-mono">
                                {env.app_url}
                            </dd>
                        </div>
                        <div className="flex justify-between gap-3">
                            <dt className="text-muted-foreground">APP_DEBUG</dt>
                            <dd className="font-mono">
                                {env.app_debug ? 'true' : 'false'}
                            </dd>
                        </div>
                        <div className="flex justify-between gap-3">
                            <dt className="text-muted-foreground">VERSION</dt>
                            <dd className="font-mono">{env.version}</dd>
                        </div>
                    </dl>
                </Card>

                <p className="text-xs text-muted-foreground">
                    <HelpCircle className="me-1 inline size-3" />
                    {t(
                        'This page is read-only. To fix anything flagged here, update the env / settings and re-run the related task.',
                    )}
                </p>
            </div>
        </AppLayout>
    );
}
