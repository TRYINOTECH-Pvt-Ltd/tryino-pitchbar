import { Head, Link } from '@inertiajs/react';
import {
    ArrowLeft,
    Bug,
    ChevronDown,
    ChevronRight,
    MessageSquare,
} from 'lucide-react';
import { useState } from 'react';
import { AdminSurface, AdminSurfaceBar } from '@/components/admin-surface';
import { Badge } from '@/components/ui/badge';
import { Card } from '@/components/ui/card';
import AdminLayout from '@/layouts/admin-layout';
import { useT } from '@/lib/i18n';

type Message = {
    id: string;
    role: 'user' | 'assistant' | 'system' | string;
    content: string | null;
    created_at: string | null;
};

type TraceChunk = {
    url: string | null;
    score: number;
    rerank_score: number | null;
    snippet: string;
};

type TraceToolCall = {
    name: string;
    args: Record<string, unknown>;
    result?: string;
    error?: string;
    block?: string | null;
};

type TraceHop = {
    hop: number;
    outcome: string;
    calls: TraceToolCall[];
    dropped_duplicates?: string[];
};

type TracePayload = {
    user_message?: string;
    answer?: string;
    note?: string;
    partial_text?: string;
    route?: {
        route: string;
        reason: string;
        score: number | null;
        matched_tools: string[];
    } | null;
    history_count?: number;
    retrieval?: {
        cache_hit: boolean;
        rerank_skipped: boolean;
        chunks: TraceChunk[];
    };
    low_confidence?: boolean;
    confidence?: number;
    tool_loop?: TraceHop[] | null;
    blocks?: string[];
    ctas?: number;
    lead_prompt?: boolean;
    latency_ms?: number;
    tokens_out?: number;
    model?: string;
    error?: { code: string; exception?: string; message: string };
};

type Trace = {
    id: string;
    message_id: string | null;
    kind: string;
    payload: TracePayload;
    created_at: string | null;
};

type Conversation = {
    id: string;
    agent: { id: string; name: string } | null;
    workspace: { id: string; name: string } | null;
    page_url: string | null;
    lang: string | null;
    is_lead: boolean;
    is_playground: boolean;
    started_at: string | null;
    messages: Message[];
};

type Props = { conversation: Conversation; traces: Trace[] };

const ROLE_STYLES: Record<string, string> = {
    user: 'border-sky-200 bg-sky-50 text-sky-700 dark:border-sky-500/30 dark:bg-sky-500/10 dark:text-sky-300',
    assistant:
        'border-violet-200 bg-violet-50 text-violet-700 dark:border-violet-500/30 dark:bg-violet-500/10 dark:text-violet-300',
    system: 'border-border bg-muted text-muted-foreground',
};

const KIND_STYLES: Record<string, string> = {
    llm: 'border-violet-200 bg-violet-50 text-violet-700 dark:border-violet-500/30 dark:bg-violet-500/10 dark:text-violet-300',
    error: 'border-red-200 bg-red-50 text-red-700 dark:border-red-500/30 dark:bg-red-500/10 dark:text-red-300',
    curated:
        'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-300',
    human_shortcut:
        'border-amber-200 bg-amber-50 text-amber-700 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-300',
    human_pending:
        'border-amber-200 bg-amber-50 text-amber-700 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-300',
    takeover:
        'border-amber-200 bg-amber-50 text-amber-700 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-300',
    workflow:
        'border-sky-200 bg-sky-50 text-sky-700 dark:border-sky-500/30 dark:bg-sky-500/10 dark:text-sky-300',
};

function Row({
    label,
    children,
}: {
    label: string;
    children: React.ReactNode;
}) {
    return (
        <div className="flex gap-2 text-xs">
            <span className="w-24 shrink-0 font-semibold text-muted-foreground">
                {label}
            </span>
            <span className="min-w-0 flex-1 break-words">{children}</span>
        </div>
    );
}

function TraceDetail({ trace }: { trace: Trace }) {
    const { t } = useT();
    const p = trace.payload;

    return (
        <div className="flex flex-col gap-2 rounded-md border bg-muted/30 p-3">
            {p.note && (
                <p className="text-xs text-muted-foreground italic">{p.note}</p>
            )}
            {p.error && (
                <Row label={t('Error')}>
                    <span className="font-mono text-red-600 dark:text-red-400">
                        {p.error.code}
                        {p.error.exception
                            ? ` · ${p.error.exception}`
                            : ''} — {p.error.message}
                    </span>
                </Row>
            )}
            {p.route && (
                <Row label={t('Route')}>
                    <span className="font-mono">
                        {p.route.route} ({p.route.reason}
                        {p.route.score != null
                            ? ` · score ${p.route.score.toFixed(3)}`
                            : ''}
                        )
                        {p.route.matched_tools.length > 0 &&
                            ` → ${p.route.matched_tools.join(', ')}`}
                    </span>
                </Row>
            )}
            {p.history_count !== undefined && (
                <Row label={t('History')}>
                    {p.history_count} {t('prior messages in the LLM context')}
                </Row>
            )}
            {p.retrieval && (
                <Row label={t('Retrieval')}>
                    <span>
                        {p.retrieval.cache_hit
                            ? t('cache hit')
                            : t('cache miss')}
                        {p.retrieval.rerank_skipped &&
                            ` · ${t('rerank skipped')}`}
                        {p.low_confidence && (
                            <span className="text-amber-600 dark:text-amber-400">
                                {' '}
                                · {t('LOW CONFIDENCE')}
                            </span>
                        )}
                        {p.confidence !== undefined &&
                            ` · ${t('confidence')} ${p.confidence}`}
                        <span className="mt-1 block">
                            {p.retrieval.chunks.length === 0 ? (
                                <span className="text-amber-600 dark:text-amber-400">
                                    {t('0 chunks passed the threshold')}
                                </span>
                            ) : (
                                p.retrieval.chunks.map((c, i) => (
                                    <span
                                        key={i}
                                        className="block font-mono text-[11px]"
                                    >
                                        {c.score.toFixed(3)}
                                        {c.rerank_score != null &&
                                            `/${c.rerank_score.toFixed(3)}`}{' '}
                                        {c.url ?? '—'} · “{c.snippet}…”
                                    </span>
                                ))
                            )}
                        </span>
                    </span>
                </Row>
            )}
            {p.tool_loop !== undefined && (
                <Row label={t('Tool loop')}>
                    {p.tool_loop === null ? (
                        <span className="text-muted-foreground">
                            {t('skipped (fast router: knowledge route)')}
                        </span>
                    ) : p.tool_loop.length === 0 ? (
                        <span className="text-muted-foreground">
                            {t('ran, no hops recorded')}
                        </span>
                    ) : (
                        <span className="flex flex-col gap-1">
                            {p.tool_loop.map((hop) => (
                                <span
                                    key={hop.hop}
                                    className="font-mono text-[11px]"
                                >
                                    {t('hop')} {hop.hop}: {hop.outcome}
                                    {(hop.dropped_duplicates?.length ?? 0) >
                                        0 &&
                                        ` (${t('dropped duplicates')}: ${hop.dropped_duplicates!.join(', ')})`}
                                    {hop.calls.map((c, i) => (
                                        <span key={i} className="block ps-4">
                                            → {c.name}({JSON.stringify(c.args)})
                                            {c.error ? (
                                                <span className="text-red-600 dark:text-red-400">
                                                    {' '}
                                                    ✗ {c.error}
                                                </span>
                                            ) : (
                                                <span className="text-emerald-700 dark:text-emerald-400">
                                                    {' '}
                                                    ⇒ {c.result}
                                                </span>
                                            )}
                                        </span>
                                    ))}
                                </span>
                            ))}
                        </span>
                    )}
                </Row>
            )}
            {(p.blocks?.length ?? 0) > 0 && (
                <Row label={t('Blocks')}>{p.blocks!.join(', ')}</Row>
            )}
            {p.partial_text && (
                <Row label={t('Partial text')}>
                    <span className="font-mono">{p.partial_text}</span>
                </Row>
            )}
            {(p.latency_ms !== undefined || p.model) && (
                <Row label={t('Turn')}>
                    {p.latency_ms !== undefined && `${p.latency_ms}ms`}
                    {p.tokens_out !== undefined &&
                        ` · ~${p.tokens_out} ${t('tokens')}`}
                    {p.lead_prompt && ` · ${t('lead form offered')}`}
                    {(p.ctas ?? 0) > 0 && ` · ${p.ctas} CTA`}
                    {p.model && <span className="font-mono"> · {p.model}</span>}
                </Row>
            )}
        </div>
    );
}

function TraceCard({ trace }: { trace: Trace }) {
    const [open, setOpen] = useState(trace.kind === 'error');
    const p = trace.payload;

    return (
        <div
            className={`rounded-md border text-sm ${KIND_STYLES[trace.kind] ?? KIND_STYLES.llm}`}
        >
            <button
                type="button"
                onClick={() => setOpen((v) => !v)}
                className="flex w-full items-center gap-2 p-2 text-start"
            >
                {open ? (
                    <ChevronDown className="size-3.5 shrink-0" />
                ) : (
                    <ChevronRight className="size-3.5 shrink-0" />
                )}
                <Bug className="size-3.5 shrink-0" />
                <span className="text-[11px] font-semibold tracking-wider uppercase">
                    {trace.kind}
                </span>
                <span className="min-w-0 flex-1 truncate text-xs opacity-80">
                    {p.user_message ?? ''}
                </span>
                {trace.created_at && (
                    <span className="shrink-0 text-[10px] opacity-60">
                        {new Date(trace.created_at).toLocaleTimeString()}
                    </span>
                )}
            </button>
            {open && (
                <div className="px-2 pb-2 text-foreground">
                    <TraceDetail trace={trace} />
                </div>
            )}
        </div>
    );
}

export default function AdminConversationShow({ conversation, traces }: Props) {
    const { t } = useT();
    const traceByMessage = new Map<string, Trace[]>();
    const orphanTraces: Trace[] = [];

    for (const tr of traces) {
        const persisted =
            tr.message_id !== null &&
            conversation.messages.some((m) => m.id === tr.message_id);

        if (persisted) {
            const list = traceByMessage.get(tr.message_id!) ?? [];
            list.push(tr);
            traceByMessage.set(tr.message_id!, list);
        } else {
            // Error turns (and takeover/pending short-circuits) never
            // persist an assistant message — show them in the timeline
            // anyway, that's exactly when forensics matter.
            orphanTraces.push(tr);
        }
    }

    return (
        <AdminLayout
            breadcrumbs={[
                { title: t('Admin'), href: '/admin' },
                { title: t('Conversations'), href: '/admin/conversations' },
                {
                    title: conversation.id.slice(0, 8),
                    href: `/admin/conversations/${conversation.id}`,
                },
            ]}
        >
            <Head title={t('Conversation · Admin')} />
            <AdminSurface>
                <AdminSurfaceBar>
                    <Link
                        href="/admin/conversations"
                        className="inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground"
                    >
                        <ArrowLeft className="size-4" />
                        {t('Back to conversations')}
                    </Link>
                </AdminSurfaceBar>

                <div className="grid gap-4 p-4 md:grid-cols-[1fr_280px]">
                    <Card className="p-0">
                        <div className="flex flex-col gap-3 border-b p-4">
                            <div className="flex items-center gap-2">
                                <MessageSquare className="size-4 text-muted-foreground" />
                                <h1 className="text-base font-semibold">
                                    {conversation.agent?.name ??
                                        t('Conversation')}
                                </h1>
                                {conversation.is_playground && (
                                    <Badge
                                        variant="outline"
                                        className="border-amber-200 bg-amber-50 text-amber-700 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-300"
                                    >
                                        {t('Playground')}
                                    </Badge>
                                )}
                                {conversation.is_lead && (
                                    <Badge
                                        variant="outline"
                                        className="border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-300"
                                    >
                                        {t('Lead captured')}
                                    </Badge>
                                )}
                                {traces.length > 0 && (
                                    <Badge
                                        variant="outline"
                                        className="border-violet-200 bg-violet-50 text-violet-700 dark:border-violet-500/30 dark:bg-violet-500/10 dark:text-violet-300"
                                    >
                                        <Bug className="me-1 size-3" />
                                        {traces.length} {t('traces')}
                                    </Badge>
                                )}
                            </div>
                            {conversation.page_url && (
                                <p className="font-mono text-xs break-all text-muted-foreground">
                                    {conversation.page_url}
                                </p>
                            )}
                        </div>

                        <div className="flex flex-col gap-3 p-4">
                            {conversation.messages.length === 0 &&
                            orphanTraces.length === 0 ? (
                                <p className="rounded-md border border-dashed bg-muted/30 p-6 text-center text-sm text-muted-foreground">
                                    {t(
                                        'No persisted messages for this conversation (e.g. playground runs intentionally skip the messages table).',
                                    )}
                                </p>
                            ) : (
                                <>
                                    {conversation.messages.map((m) => (
                                        <div
                                            key={m.id}
                                            className="flex flex-col gap-2"
                                        >
                                            <div
                                                className={`rounded-md border p-3 text-sm whitespace-pre-wrap ${ROLE_STYLES[m.role] ?? ROLE_STYLES.system}`}
                                            >
                                                <div className="mb-1 text-[10px] font-semibold tracking-wider uppercase opacity-70">
                                                    {m.role}
                                                    {m.created_at && (
                                                        <span className="ms-2 opacity-50">
                                                            {new Date(
                                                                m.created_at,
                                                            ).toLocaleString()}
                                                        </span>
                                                    )}
                                                </div>
                                                {m.content ?? ''}
                                            </div>
                                            {(
                                                traceByMessage.get(m.id) ?? []
                                            ).map((tr) => (
                                                <TraceCard
                                                    key={tr.id}
                                                    trace={tr}
                                                />
                                            ))}
                                        </div>
                                    ))}
                                    {orphanTraces.map((tr) => (
                                        <TraceCard key={tr.id} trace={tr} />
                                    ))}
                                </>
                            )}
                        </div>
                    </Card>

                    <Card className="h-fit space-y-3 p-4 text-sm">
                        <div>
                            <p className="text-[11px] font-semibold tracking-wider text-muted-foreground uppercase">
                                {t('Workspace')}
                            </p>
                            <p className="mt-0.5 font-medium">
                                {conversation.workspace?.name ?? '—'}
                            </p>
                        </div>
                        <div>
                            <p className="text-[11px] font-semibold tracking-wider text-muted-foreground uppercase">
                                {t('Agent')}
                            </p>
                            <p className="mt-0.5 font-medium">
                                {conversation.agent?.name ?? '—'}
                            </p>
                        </div>
                        <div>
                            <p className="text-[11px] font-semibold tracking-wider text-muted-foreground uppercase">
                                {t('Started')}
                            </p>
                            <p className="mt-0.5 font-medium">
                                {conversation.started_at
                                    ? new Date(
                                          conversation.started_at,
                                      ).toLocaleString()
                                    : '—'}
                            </p>
                        </div>
                        <div>
                            <p className="text-[11px] font-semibold tracking-wider text-muted-foreground uppercase">
                                {t('Language')}
                            </p>
                            <p className="mt-0.5 font-medium uppercase">
                                {conversation.lang ?? '—'}
                            </p>
                        </div>
                        <div>
                            <p className="text-[11px] font-semibold tracking-wider text-muted-foreground uppercase">
                                {t('Turn traces')}
                            </p>
                            <p className="mt-0.5 text-xs text-muted-foreground">
                                {traces.length > 0
                                    ? t(
                                          'Expand a trace under any assistant reply to see the route decision, retrieved sources, tool calls, and errors for that turn.',
                                      )
                                    : t(
                                          'No traces recorded yet — traces capture turns made after the debugger shipped, and are pruned after the retention window.',
                                      )}
                            </p>
                        </div>
                    </Card>
                </div>
            </AdminSurface>
        </AdminLayout>
    );
}
