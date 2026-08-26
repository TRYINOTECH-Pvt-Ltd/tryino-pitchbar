import { Head, Link, router } from '@inertiajs/react';
import { ChevronRight, MessagesSquare, Trash2, User } from 'lucide-react';
import { useConfirm } from '@/components/confirm-dialog-provider';
import { TablePagination } from '@/components/table-pagination';
import type { PaginationMeta } from '@/components/table-pagination';
import { TableSearch } from '@/components/table-search';
import { Card } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { useT } from '@/lib/i18n';
import { index as agentsIndex, show as showAgent } from '@/routes/agents';
import { index as agentConversationsIndex } from '@/routes/agents/conversations';
import { destroy as conversationDestroy } from '@/routes/conversations';
import type { BreadcrumbItem } from '@/types';

type ShowMode = 'engaged' | 'all';

type ConversationRow = {
    id: string;
    started_at: string | null;
    last_activity_at: string | null;
    page_url: string | null;
    lang: string | null;
    visitor_anon: string | null;
    is_returning: boolean;
    message_count: number;
    preview: string;
};

type Props = {
    agent: { id: string; name: string };
    totals: {
        conversations: number;
        messages: number;
        last_24h: number;
        empty: number;
    };
    conversations: ConversationRow[];
    pagination: PaginationMeta;
    filters: { q: string; show: ShowMode };
    can_delete: boolean;
};

function useRelativeTime(): (iso: string | null) => string {
    const { t } = useT();

    return (iso: string | null): string => {
        if (!iso) {
            return '—';
        }

        const ms = Date.now() - new Date(iso).getTime();
        const sec = Math.max(1, Math.round(ms / 1000));

        if (sec < 60) {
            return t('just now');
        }

        const min = Math.round(sec / 60);

        if (min < 60) {
            return t(':min m ago', { min });
        }

        const h = Math.round(min / 60);

        if (h < 24) {
            return t(':h h ago', { h });
        }

        const d = Math.round(h / 24);

        return t(':d d ago', { d });
    };
}

export default function ConversationsPage({
    agent,
    totals,
    conversations,
    pagination,
    filters,
    can_delete,
}: Props) {
    const { t, tChoice } = useT();
    const relativeTime = useRelativeTime();
    const confirm = useConfirm();

    const breadcrumbs: BreadcrumbItem[] = [
        { title: t('Agents'), href: agentsIndex.url() },
        { title: agent.name, href: showAgent({ agent: agent.id }).url },
        {
            title: t('Conversations'),
            href: agentConversationsIndex({ agent: agent.id }).url,
        },
    ];

    const isEmpty = conversations.length === 0;
    const hasQuery = filters.q.trim() !== '';
    const showMode: ShowMode = filters.show === 'all' ? 'all' : 'engaged';

    const setShowMode = (next: ShowMode) => {
        const query: Record<string, string> = {};

        if (filters.q) {
            query.q = filters.q;
        }

        if (next === 'all') {
            query.show = 'all';
        }

        router.get(`/app/agents/${agent.id}/conversations`, query, {
            preserveScroll: true,
            preserveState: true,
            only: ['conversations', 'pagination', 'filters', 'totals'],
        });
    };

    const deleteOne = async (id: string) => {
        if (!can_delete) {
            return;
        }

        const ok = await confirm({
            title: t('Delete conversation?'),
            message: t('Delete this conversation? This cannot be undone.'),
            confirmLabel: t('Delete'),
            danger: true,
        });

        if (!ok) {
            return;
        }

        router.delete(conversationDestroy(id), { preserveScroll: true });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`${agent.name} · conversations`} />
            <div className="flex flex-1 flex-col gap-4 p-4">
                <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                    <div className="min-w-0">
                        <h1 className="text-2xl font-semibold tracking-tight">
                            {t('Conversations')}
                        </h1>
                        <p className="mt-1 text-sm text-muted-foreground">
                            {t(
                                'Every visitor session this agent has handled. Click a row to read the full thread.',
                            )}
                        </p>
                    </div>
                    <div className="w-full sm:w-64 sm:shrink-0">
                        <TableSearch
                            placeholder={t(
                                'Search by question, page URL, or anon id…',
                            )}
                            initialValue={filters.q}
                            only={['conversations', 'pagination', 'filters']}
                        />
                    </div>
                </div>

                <div className="grid grid-cols-2 gap-3 md:grid-cols-3">
                    <Card className="p-4">
                        <p className="text-xs tracking-wide text-muted-foreground uppercase">
                            {t('Total')}
                        </p>
                        <p className="mt-1 text-2xl font-semibold tabular-nums">
                            {totals.conversations.toLocaleString()}
                        </p>
                        <p className="mt-0.5 text-xs text-muted-foreground">
                            {t('all time')}
                        </p>
                    </Card>
                    <Card className="p-4">
                        <p className="text-xs tracking-wide text-muted-foreground uppercase">
                            {t('Messages')}
                        </p>
                        <p className="mt-1 text-2xl font-semibold tabular-nums">
                            {totals.messages.toLocaleString()}
                        </p>
                        <p className="mt-0.5 text-xs text-muted-foreground">
                            {t('across all conversations')}
                        </p>
                    </Card>
                    <Card className="p-4">
                        <p className="text-xs tracking-wide text-muted-foreground uppercase">
                            {t('Last 24h')}
                        </p>
                        <p className="mt-1 text-2xl font-semibold tabular-nums">
                            {totals.last_24h.toLocaleString()}
                        </p>
                        <p className="mt-0.5 text-xs text-muted-foreground">
                            {t('new conversations')}
                        </p>
                    </Card>
                </div>

                <div className="flex items-center gap-2">
                    <div className="inline-flex overflow-hidden rounded-full border text-xs font-medium">
                        <button
                            type="button"
                            onClick={() => setShowMode('engaged')}
                            className={`px-3 py-1 ${showMode === 'engaged' ? 'bg-foreground text-background' : 'hover:bg-muted'}`}
                        >
                            {t('Engaged only')}
                        </button>
                        <button
                            type="button"
                            onClick={() => setShowMode('all')}
                            className={`border-l px-3 py-1 ${showMode === 'all' ? 'bg-foreground text-background' : 'hover:bg-muted'}`}
                        >
                            {t('Show all')}
                            {totals.empty > 0 && (
                                <span className="ml-1 rounded-full bg-foreground/10 px-1.5 text-[10px] tabular-nums">
                                    +{totals.empty}
                                </span>
                            )}
                        </button>
                    </div>
                </div>

                {isEmpty ? (
                    <Card className="p-8 text-center">
                        <MessagesSquare className="mx-auto size-10 text-muted-foreground/40" />
                        <p className="mt-3 text-sm font-medium">
                            {hasQuery
                                ? t('No conversations match that search.')
                                : showMode === 'engaged'
                                  ? t('No engaged conversations yet.')
                                  : t('No conversations yet.')}
                        </p>
                        <p className="mt-1 text-xs text-muted-foreground">
                            {hasQuery
                                ? t('Try a different keyword.')
                                : showMode === 'engaged'
                                  ? t(
                                        'Drive-by widget loads are hidden here. Toggle "Show all" to see every session.',
                                    )
                                  : t(
                                        'Once visitors start chatting, every session shows up here.',
                                    )}
                        </p>
                    </Card>
                ) : (
                    <Card className="p-0">
                        <div className="divide-y">
                            {conversations.map((c) => (
                                <div
                                    key={c.id}
                                    className="group flex items-start gap-3 px-4 py-3 transition hover:bg-muted/50"
                                >
                                    <Link
                                        href={`/app/conversations/${c.id}`}
                                        className="flex min-w-0 flex-1 items-start gap-3"
                                    >
                                        <div className="flex size-8 shrink-0 items-center justify-center rounded-full bg-muted">
                                            <User className="size-4 text-muted-foreground" />
                                        </div>
                                        <div className="min-w-0 flex-1">
                                            <div className="flex flex-wrap items-center gap-2">
                                                <p className="truncate text-sm font-medium">
                                                    {c.preview}
                                                </p>
                                                {c.is_returning && (
                                                    <span className="rounded bg-violet-500/15 px-1.5 py-0.5 text-[10px] font-medium text-violet-700 dark:text-violet-400">
                                                        {t('returning')}
                                                    </span>
                                                )}
                                                {c.lang && c.lang !== 'en' && (
                                                    <span className="rounded bg-sky-500/15 px-1.5 py-0.5 text-[10px] font-medium text-sky-700 uppercase dark:text-sky-400">
                                                        {c.lang}
                                                    </span>
                                                )}
                                            </div>
                                            <p className="mt-0.5 truncate text-xs text-muted-foreground">
                                                {c.page_url ??
                                                    t('(no page url)')}
                                            </p>
                                            <p className="mt-1 text-[11px] text-muted-foreground/80">
                                                {c.visitor_anon
                                                    ? c.visitor_anon.slice(
                                                          0,
                                                          16,
                                                      )
                                                    : t('(unknown visitor)')}
                                            </p>
                                        </div>
                                        <div className="flex shrink-0 flex-col items-end gap-1 text-end">
                                            <span className="text-xs font-medium tabular-nums">
                                                {tChoice(
                                                    ':count msg|:count msgs',
                                                    c.message_count,
                                                    { count: c.message_count },
                                                )}
                                            </span>
                                            <span className="text-[10px] text-muted-foreground">
                                                {relativeTime(
                                                    c.last_activity_at,
                                                )}
                                            </span>
                                        </div>
                                        <ChevronRight className="mt-2 size-4 shrink-0 text-muted-foreground" />
                                    </Link>
                                    {can_delete && (
                                        <button
                                            type="button"
                                            onClick={() => deleteOne(c.id)}
                                            className="mt-1 ml-1 rounded-md p-1 text-muted-foreground opacity-0 transition group-hover:opacity-100 hover:bg-red-500/10 hover:text-red-600 focus:opacity-100"
                                            title={t('Delete conversation')}
                                            aria-label={t(
                                                'Delete conversation',
                                            )}
                                        >
                                            <Trash2 className="size-4" />
                                        </button>
                                    )}
                                </div>
                            ))}
                        </div>
                        <TablePagination
                            pagination={pagination}
                            only={['conversations', 'pagination', 'filters']}
                        />
                    </Card>
                )}
            </div>
        </AppLayout>
    );
}
