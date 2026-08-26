import { Head, Link, router } from '@inertiajs/react';
import {
    Activity,
    ArrowLeft,
    CheckCircle2,
    Clock,
    Copy,
    RefreshCw,
    Scissors,
    TriangleAlert,
    XCircle,
} from 'lucide-react';
import { Fragment, useMemo, useState } from 'react';
import { toast } from 'sonner';
import { EmptyState } from '@/components/empty-state';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { useT } from '@/lib/i18n';
import { relativeTime } from '@/lib/relative-time';
import { cn } from '@/lib/utils';
import { index as agentsIndex, show as showAgent } from '@/routes/agents';
import type { BreadcrumbItem } from '@/types';
import {
    activity as agentMcpActivity,
    index as agentMcpIndex,
} from '@/routes/agents/mcp';

type Row = {
    id: number;
    tool_name: string;
    status: string;
    latency_ms: number;
    request_id: string;
    conversation_id: string | null;
    error_summary: string | null;
    output_truncated: boolean;
    created_at: string;
};

type Props = {
    agent: { id: string; name: string };
    server: { id: string; label: string; status: string };
    rows: Row[];
    filter: { status: string };
};

type StatusKind = 'ok' | 'warn' | 'error' | 'muted';

const statusKindMap: Record<string, StatusKind> = {
    success: 'ok',
    timeout: 'warn',
    transport_error: 'error',
    tool_error: 'warn',
    unauthorized: 'error',
    rate_limited: 'warn',
    circuit_open: 'error',
    denied_not_granted: 'muted',
    denied_unknown_tool: 'muted',
    schema_invalid: 'error',
};

const STATUS_FILTERS = [
    '',
    'success',
    'timeout',
    'transport_error',
    'tool_error',
    'unauthorized',
    'rate_limited',
    'circuit_open',
    'denied_not_granted',
    'schema_invalid',
] as const;

function statusKind(status: string): StatusKind {
    return statusKindMap[status] ?? 'muted';
}

function StatusPill({ status }: { status: string }) {
    const kind = statusKind(status);
    const className = {
        ok: 'bg-emerald-500/15 text-emerald-700 dark:text-emerald-300 border-emerald-300/40',
        warn: 'bg-amber-500/15 text-amber-700 dark:text-amber-300 border-amber-300/40',
        error: 'bg-red-500/15 text-red-700 dark:text-red-300 border-red-300/40',
        muted: 'bg-muted text-muted-foreground border-border',
    }[kind];
    const Icon = {
        ok: CheckCircle2,
        warn: TriangleAlert,
        error: XCircle,
        muted: Clock,
    }[kind];

    return (
        <span
            className={cn(
                'inline-flex items-center gap-1 rounded-full border px-2 py-0.5 text-[10px] font-medium',
                className,
            )}
        >
            <Icon className="size-3" />
            {status}
        </span>
    );
}

function copy(value: string, label: string, t: (k: string) => string) {
    navigator.clipboard
        ?.writeText(value)
        .then(() => toast.success(`${label} ${t('copied')}`))
        .catch(() => toast.error(t('Copy failed')));
}

export default function McpActivity({ agent, server, rows, filter }: Props) {
    const { t } = useT();
    const [expanded, setExpanded] = useState<number | null>(null);

    const counts = useMemo(() => {
        const buckets = { ok: 0, warn: 0, error: 0, muted: 0 };

        for (const row of rows) {
            buckets[statusKind(row.status)]++;
        }

        return buckets;
    }, [rows]);

    const setFilter = (status: string) => {
        router.get(
            agentMcpActivity({ agent: agent.id, mcpServer: server.id }).url,
            status ? { status } : {},
            {
                preserveScroll: true,
                preserveState: true,
                only: ['rows', 'filter'],
            },
        );
    };

    const refresh = () => {
        router.reload({ only: ['rows'] });
    };

    const breadcrumbs: BreadcrumbItem[] = [
        { title: t('Agents'), href: agentsIndex.url() },
        { title: agent.name, href: showAgent({ agent: agent.id }).url },
        {
            title: t('MCP integrations'),
            href: agentMcpIndex({ agent: agent.id }).url,
        },
        {
            title: server.label,
            href: agentMcpActivity({ agent: agent.id, mcpServer: server.id })
                .url,
        },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`${server.label} · ${t('Activity')}`} />
            <div className="mx-auto w-full max-w-6xl space-y-5 p-4 sm:p-6">
                <div>
                    <Button
                        variant="ghost"
                        size="sm"
                        asChild
                        className="-ms-2 mb-2"
                    >
                        <Link href={agentMcpIndex({ agent: agent.id }).url}>
                            <ArrowLeft className="me-1.5 size-4" />
                            {t('Back to MCP servers')}
                        </Link>
                    </Button>
                    <div className="flex flex-wrap items-start justify-between gap-3">
                        <div className="flex items-center gap-3">
                            <div className="rounded-md border bg-muted/30 p-2">
                                <Activity className="size-5 text-muted-foreground" />
                            </div>
                            <div className="min-w-0">
                                <h1 className="text-2xl font-semibold">
                                    {server.label} · {t('Activity')}
                                </h1>
                                <p className="text-sm text-muted-foreground">
                                    {t(
                                        'Last 100 tool calls. Use this when a buyer reports their integration is misbehaving.',
                                    )}
                                </p>
                            </div>
                        </div>
                        <Button
                            variant="outline"
                            size="sm"
                            onClick={refresh}
                            title={t('Reload latest')}
                        >
                            <RefreshCw className="me-1.5 size-4" />
                            {t('Refresh')}
                        </Button>
                    </div>
                </div>

                <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                    {[
                        {
                            key: 'ok',
                            label: t('Success'),
                            value: counts.ok,
                            className:
                                'border-emerald-200 dark:border-emerald-500/30 bg-emerald-50/50 dark:bg-emerald-500/5 text-emerald-700 dark:text-emerald-300',
                        },
                        {
                            key: 'warn',
                            label: t('Warnings'),
                            value: counts.warn,
                            className:
                                'border-amber-200 dark:border-amber-500/30 bg-amber-50/50 dark:bg-amber-500/5 text-amber-700 dark:text-amber-300',
                        },
                        {
                            key: 'error',
                            label: t('Errors'),
                            value: counts.error,
                            className:
                                'border-red-200 dark:border-red-500/30 bg-red-50/50 dark:bg-red-500/5 text-red-700 dark:text-red-300',
                        },
                        {
                            key: 'muted',
                            label: t('Denied / other'),
                            value: counts.muted,
                            className: 'border-border bg-muted/30',
                        },
                    ].map((card) => (
                        <Card
                            key={card.key}
                            className={cn('border p-3', card.className)}
                        >
                            <p className="text-[10px] font-medium tracking-wide uppercase opacity-80">
                                {card.label}
                            </p>
                            <p className="mt-1 text-xl font-semibold tabular-nums">
                                {card.value}
                            </p>
                        </Card>
                    ))}
                </div>

                <div className="flex flex-wrap items-center gap-1">
                    {STATUS_FILTERS.map((f) => (
                        <button
                            key={f || 'all'}
                            type="button"
                            onClick={() => setFilter(f)}
                            className={cn(
                                'rounded-full border px-3 py-1 text-xs font-medium transition',
                                filter.status === f
                                    ? 'border-foreground/20 bg-foreground text-background'
                                    : 'border-border bg-card text-muted-foreground hover:text-foreground',
                            )}
                        >
                            {f === '' ? t('All') : f}
                        </button>
                    ))}
                </div>

                <Card className="p-0">
                    {rows.length === 0 ? (
                        <EmptyState
                            icon={Activity}
                            title={
                                filter.status
                                    ? t('No calls match this filter')
                                    : t('No tool calls recorded yet')
                            }
                            description={
                                filter.status
                                    ? t(
                                          'Try clearing the filter or pick a different status.',
                                      )
                                    : t(
                                          'Once a visitor triggers a granted tool, the call shows up here in real time (reload to see new rows).',
                                      )
                            }
                        />
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="w-full text-sm">
                                <thead className="bg-muted/40 text-xs text-muted-foreground">
                                    <tr>
                                        <th className="px-3 py-2 text-left font-medium">
                                            {t('Tool')}
                                        </th>
                                        <th className="px-3 py-2 text-left font-medium">
                                            {t('Status')}
                                        </th>
                                        <th className="px-3 py-2 text-right font-medium">
                                            {t('Latency')}
                                        </th>
                                        <th className="px-3 py-2 text-left font-medium">
                                            {t('Conversation')}
                                        </th>
                                        <th className="px-3 py-2 text-left font-medium">
                                            {t('Error')}
                                        </th>
                                        <th className="px-3 py-2 text-left font-medium">
                                            {t('When')}
                                        </th>
                                        <th className="px-3 py-2 text-right font-medium">
                                            {t('Request')}
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {rows.map((row) => {
                                        const isOpen = expanded === row.id;

                                        return (
                                            <Fragment key={row.id}>
                                                <tr
                                                    key={`row-${row.id}`}
                                                    className={cn(
                                                        'cursor-pointer border-t transition hover:bg-muted/30',
                                                        isOpen && 'bg-muted/30',
                                                    )}
                                                    onClick={() =>
                                                        setExpanded(
                                                            isOpen
                                                                ? null
                                                                : row.id,
                                                        )
                                                    }
                                                >
                                                    <td className="px-3 py-2 font-mono text-xs">
                                                        <div className="flex items-center gap-1.5">
                                                            <span>
                                                                {row.tool_name}
                                                            </span>
                                                            {row.output_truncated && (
                                                                <span
                                                                    title={t(
                                                                        'Tool output was truncated to fit the token budget',
                                                                    )}
                                                                >
                                                                    <Scissors className="size-3 text-amber-600 dark:text-amber-400" />
                                                                </span>
                                                            )}
                                                        </div>
                                                    </td>
                                                    <td className="px-3 py-2">
                                                        <StatusPill
                                                            status={row.status}
                                                        />
                                                    </td>
                                                    <td className="px-3 py-2 text-right text-xs text-muted-foreground tabular-nums">
                                                        {row.latency_ms}ms
                                                    </td>
                                                    <td className="px-3 py-2">
                                                        {row.conversation_id ? (
                                                            <Link
                                                                href={`/app/conversations/${row.conversation_id}`}
                                                                className="font-mono text-xs text-primary hover:underline"
                                                                onClick={(e) =>
                                                                    e.stopPropagation()
                                                                }
                                                            >
                                                                {row.conversation_id.slice(
                                                                    0,
                                                                    8,
                                                                )}
                                                            </Link>
                                                        ) : (
                                                            <span className="text-xs text-muted-foreground">
                                                                —
                                                            </span>
                                                        )}
                                                    </td>
                                                    <td className="max-w-[280px] truncate px-3 py-2 text-xs text-muted-foreground">
                                                        {row.error_summary ??
                                                            '—'}
                                                    </td>
                                                    <td className="px-3 py-2 text-xs whitespace-nowrap text-muted-foreground">
                                                        {relativeTime(
                                                            row.created_at,
                                                        )}
                                                    </td>
                                                    <td
                                                        className="px-3 py-2 text-right"
                                                        onClick={(e) =>
                                                            e.stopPropagation()
                                                        }
                                                    >
                                                        <button
                                                            type="button"
                                                            className="inline-flex items-center gap-1 text-[10px] text-muted-foreground hover:text-foreground"
                                                            onClick={() =>
                                                                copy(
                                                                    row.request_id,
                                                                    t(
                                                                        'Request ID',
                                                                    ),
                                                                    t,
                                                                )
                                                            }
                                                            title={
                                                                row.request_id
                                                            }
                                                        >
                                                            <Copy className="size-3" />
                                                            {row.request_id.slice(
                                                                0,
                                                                6,
                                                            )}
                                                        </button>
                                                    </td>
                                                </tr>
                                                {isOpen && (
                                                    <tr
                                                        key={`detail-${row.id}`}
                                                        className="border-t bg-muted/20"
                                                    >
                                                        <td
                                                            colSpan={7}
                                                            className="px-3 py-3"
                                                        >
                                                            <dl className="grid grid-cols-2 gap-x-6 gap-y-2 text-xs sm:grid-cols-4">
                                                                <div>
                                                                    <dt className="text-[10px] tracking-wide text-muted-foreground uppercase">
                                                                        {t(
                                                                            'Request ID',
                                                                        )}
                                                                    </dt>
                                                                    <dd className="mt-0.5 font-mono break-all">
                                                                        {
                                                                            row.request_id
                                                                        }
                                                                    </dd>
                                                                </div>
                                                                <div>
                                                                    <dt className="text-[10px] tracking-wide text-muted-foreground uppercase">
                                                                        {t(
                                                                            'Conversation',
                                                                        )}
                                                                    </dt>
                                                                    <dd className="mt-0.5 font-mono break-all">
                                                                        {row.conversation_id ??
                                                                            '—'}
                                                                    </dd>
                                                                </div>
                                                                <div>
                                                                    <dt className="text-[10px] tracking-wide text-muted-foreground uppercase">
                                                                        {t(
                                                                            'Recorded',
                                                                        )}
                                                                    </dt>
                                                                    <dd className="mt-0.5">
                                                                        {new Date(
                                                                            row.created_at,
                                                                        ).toLocaleString()}
                                                                    </dd>
                                                                </div>
                                                                <div>
                                                                    <dt className="text-[10px] tracking-wide text-muted-foreground uppercase">
                                                                        {t(
                                                                            'Output truncated',
                                                                        )}
                                                                    </dt>
                                                                    <dd className="mt-0.5">
                                                                        {row.output_truncated
                                                                            ? t(
                                                                                  'Yes',
                                                                              )
                                                                            : t(
                                                                                  'No',
                                                                              )}
                                                                    </dd>
                                                                </div>
                                                                {row.error_summary && (
                                                                    <div className="sm:col-span-4">
                                                                        <dt className="text-[10px] tracking-wide text-muted-foreground uppercase">
                                                                            {t(
                                                                                'Error summary',
                                                                            )}
                                                                        </dt>
                                                                        <dd className="mt-0.5 break-words whitespace-pre-wrap text-foreground">
                                                                            {
                                                                                row.error_summary
                                                                            }
                                                                        </dd>
                                                                    </div>
                                                                )}
                                                            </dl>
                                                        </td>
                                                    </tr>
                                                )}
                                            </Fragment>
                                        );
                                    })}
                                </tbody>
                            </table>
                        </div>
                    )}
                </Card>
                <p className="text-[11px] text-muted-foreground">
                    {t(
                        'Logs retain for 30 days. Click a row to see the full request ID and conversation link.',
                    )}
                </p>
            </div>
        </AppLayout>
    );
}
