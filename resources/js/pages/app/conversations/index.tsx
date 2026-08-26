import { Head, Link, router } from '@inertiajs/react';
import {
    ChevronRight,
    Globe,
    Hand,
    Headset,
    MessagesSquare,
    Sparkles,
    Trash2,
    User,
} from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { useConfirm } from '@/components/confirm-dialog-provider';
import { LeadScoreBadge } from '@/components/lead-score-badge';
import { TablePagination } from '@/components/table-pagination';
import type { PaginationMeta } from '@/components/table-pagination';
import { TableSearch } from '@/components/table-search';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { useT } from '@/lib/i18n';
import {
    bulkDestroy as conversationsBulkDestroy,
    destroy as conversationDestroy,
    index as conversationsIndex,
    show as conversationShow,
} from '@/routes/conversations';
import type { BreadcrumbItem } from '@/types';

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
    agent: { id: string; name: string } | null;
    human_requested_at: string | null;
    claimed_by_user_id: number | null;
    satisfaction: 'positive' | 'negative' | null;
    lead_score: number;
    lead_score_bucket: 'low' | 'medium' | 'high';
    lead_score_reasons: string[];
    tags: { id: string; label: string; color: string }[];
};

type FilterId = '' | 'needs_human' | 'live_now';
type ShowMode = 'engaged' | 'all';

type Props = {
    totals: {
        conversations: number;
        messages: number;
        last_24h: number;
        active_agents: number;
        leads: number;
        needs_human: number;
        live_now: number;
        empty: number;
    };
    conversations: ConversationRow[];
    pagination: PaginationMeta;
    filters: { q: string; filter: FilterId; tag: string; show: ShowMode };
    available_tags: { id: string; label: string; color: string }[];
    can_delete: boolean;
};

function useLivePoll(active: boolean): void {
    useEffect(() => {
        if (!active) {
            return;
        }

        const tick = () => {
            if (document.visibilityState !== 'visible') {
                return;
            }

            router.reload({
                only: ['conversations', 'pagination', 'totals'],
            });
        };
        const id = window.setInterval(tick, 5000);

        return () => window.clearInterval(id);
    }, [active]);
}

function relativeTime(
    iso: string | null,
    t: (key: string, replacements?: Record<string, string | number>) => string,
): string {
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
        return t(':count m ago', { count: min });
    }

    const hour = Math.round(min / 60);

    if (hour < 24) {
        return t(':count h ago', { count: hour });
    }

    const day = Math.round(hour / 24);

    return t(':count d ago', { count: day });
}

export default function WorkspaceConversationsPage({
    totals,
    conversations,
    pagination,
    filters,
    available_tags,
    can_delete,
}: Props) {
    const { t } = useT();
    const confirm = useConfirm();
    const breadcrumbs: BreadcrumbItem[] = [
        { title: t('Conversations'), href: conversationsIndex() },
    ];
    const isEmpty = conversations.length === 0;
    const hasQuery = filters.q.trim() !== '';
    const activeFilter = (filters.filter ?? '') as FilterId;
    const activeTag = filters.tag ?? '';
    const showMode: ShowMode = filters.show === 'all' ? 'all' : 'engaged';

    const [selected, setSelected] = useState<Set<string>>(new Set());

    const buildQuery = (
        overrides: Record<string, string>,
    ): Record<string, string> => {
        const base: Record<string, string> = {};

        if (filters.q) {
            base.q = filters.q;
        }

        if (activeFilter) {
            base.filter = activeFilter;
        }

        if (activeTag) {
            base.tag = activeTag;
        }

        if (showMode === 'all') {
            base.show = 'all';
        }

        return { ...base, ...overrides };
    };

    const setFilter = (next: FilterId) => {
        const query = buildQuery({});

        if (next) {
            query.filter = next;
        } else {
            delete query.filter;
        }

        setSelected(new Set());
        router.get(conversationsIndex(), query, {
            preserveScroll: true,
            preserveState: true,
            only: ['conversations', 'pagination', 'filters', 'totals'],
        });
    };

    const setShowMode = (next: ShowMode) => {
        const query = buildQuery({});

        if (next === 'all') {
            query.show = 'all';
        } else {
            delete query.show;
        }

        setSelected(new Set());
        router.get(conversationsIndex(), query, {
            preserveScroll: true,
            preserveState: true,
            only: ['conversations', 'pagination', 'filters', 'totals'],
        });
    };

    const toggleRow = (id: string) => {
        setSelected((prev) => {
            const next = new Set(prev);

            if (next.has(id)) {
                next.delete(id);
            } else {
                next.add(id);
            }

            return next;
        });
    };

    const allOnPageSelected = useMemo(
        () =>
            conversations.length > 0 &&
            conversations.every((c) => selected.has(c.id)),
        [conversations, selected],
    );
    const toggleAllOnPage = () => {
        setSelected((prev) => {
            if (allOnPageSelected) {
                const next = new Set(prev);
                conversations.forEach((c) => next.delete(c.id));

                return next;
            }

            const next = new Set(prev);
            conversations.forEach((c) => next.add(c.id));

            return next;
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

        router.delete(conversationDestroy(id), {
            preserveScroll: true,
        });
    };

    const deleteSelected = async () => {
        if (!can_delete || selected.size === 0) {
            return;
        }

        const count = selected.size;

        const ok = await confirm({
            title: t('Delete conversations?'),
            message: t('Delete :count conversations? This cannot be undone.', {
                count,
            }),
            confirmLabel: t('Delete'),
            danger: true,
        });

        if (!ok) {
            return;
        }

        router.post(
            conversationsBulkDestroy(),
            { mode: 'ids', ids: Array.from(selected) },
            {
                preserveScroll: true,
                onSuccess: () => setSelected(new Set()),
            },
        );
    };

    const deleteAllEmpty = async () => {
        if (!can_delete || totals.empty === 0) {
            return;
        }

        const ok = await confirm({
            title: t('Delete empty conversations?'),
            message: t(
                'Delete all :count empty conversations across this workspace? Sessions with at least one visitor message are kept.',
                { count: totals.empty },
            ),
            confirmLabel: t('Delete'),
            danger: true,
        });

        if (!ok) {
            return;
        }

        router.post(
            conversationsBulkDestroy(),
            { mode: 'empty' },
            { preserveScroll: true },
        );
    };

    // Auto-poll when watching a live filter so the queue stays fresh
    // without the operator having to refresh.
    useLivePoll(activeFilter !== '');

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('Conversations')} />
            <div className="flex flex-1 flex-col gap-4 p-4">
                <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                    <div className="min-w-0">
                        <h1 className="text-2xl font-semibold tracking-tight">
                            {t('Conversations')}
                        </h1>
                        <p className="mt-1 text-sm text-muted-foreground">
                            {t(
                                'Visitor sessions across every agent in this workspace. Open a thread to see exactly what people asked and how your agent replied.',
                            )}
                        </p>
                    </div>
                    <div className="w-full sm:w-64 sm:shrink-0">
                        <TableSearch
                            placeholder={t(
                                'Search by agent, question, page URL, or anon id…',
                            )}
                            initialValue={filters.q}
                            only={['conversations', 'pagination', 'filters']}
                        />
                    </div>
                </div>

                <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-5">
                    <Card className="p-4">
                        <p className="text-xs tracking-wide text-muted-foreground uppercase">
                            {t('Total conversations')}
                        </p>
                        <p className="mt-1 text-2xl font-semibold tabular-nums">
                            {totals.conversations.toLocaleString()}
                        </p>
                        <p className="mt-0.5 text-xs text-muted-foreground">
                            {t('all tracked visitor sessions')}
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
                            {t('customer and agent replies')}
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
                            {t('new conversations started')}
                        </p>
                    </Card>
                    <Card className="p-4">
                        <p className="text-xs tracking-wide text-muted-foreground uppercase">
                            {t('Leads captured')}
                        </p>
                        <p className="mt-1 text-2xl font-semibold tabular-nums">
                            {totals.leads.toLocaleString()}
                        </p>
                        <p className="mt-0.5 text-xs text-muted-foreground">
                            {t('handoffs from these conversations')}
                        </p>
                    </Card>
                    <Card className="p-4">
                        <p className="text-xs tracking-wide text-muted-foreground uppercase">
                            {t('Active agents')}
                        </p>
                        <p className="mt-1 text-2xl font-semibold tabular-nums">
                            {totals.active_agents.toLocaleString()}
                        </p>
                        <p className="mt-0.5 text-xs text-muted-foreground">
                            {t('agents with live traffic')}
                        </p>
                    </Card>
                </div>

                <div className="flex flex-wrap items-center gap-2">
                    <FilterPill
                        label={t('All')}
                        active={activeFilter === ''}
                        onClick={() => setFilter('')}
                    />
                    <FilterPill
                        label={t('Needs human')}
                        count={totals.needs_human}
                        active={activeFilter === 'needs_human'}
                        onClick={() => setFilter('needs_human')}
                        accent="amber"
                        icon={<Hand className="size-3" />}
                    />
                    <FilterPill
                        label={t('Live now')}
                        count={totals.live_now}
                        active={activeFilter === 'live_now'}
                        onClick={() => setFilter('live_now')}
                        accent="emerald"
                        icon={<Headset className="size-3" />}
                    />
                    <div className="ml-2 inline-flex overflow-hidden rounded-full border text-xs font-medium">
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
                    {available_tags.length > 0 && (
                        <select
                            value={activeTag}
                            onChange={(e) => {
                                const query = buildQuery({});

                                if (e.target.value) {
                                    query.tag = e.target.value;
                                } else {
                                    delete query.tag;
                                }

                                router.get(conversationsIndex(), query, {
                                    preserveScroll: true,
                                    preserveState: true,
                                    only: [
                                        'conversations',
                                        'pagination',
                                        'filters',
                                        'totals',
                                    ],
                                });
                            }}
                            className="rounded-full border bg-background px-3 py-1 text-xs font-medium hover:bg-muted"
                        >
                            <option value="">{t('All tags')}</option>
                            {available_tags.map((tag) => (
                                <option key={tag.id} value={tag.id}>
                                    {tag.label}
                                </option>
                            ))}
                        </select>
                    )}
                    {can_delete && (
                        <div className="ml-auto flex items-center gap-2">
                            {selected.size > 0 && (
                                <Button
                                    type="button"
                                    variant="destructive"
                                    size="sm"
                                    onClick={deleteSelected}
                                    className="h-7 text-xs"
                                >
                                    <Trash2 className="mr-1 size-3" />
                                    {t('Delete :count selected', {
                                        count: selected.size,
                                    })}
                                </Button>
                            )}
                            {totals.empty > 0 && (
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    onClick={deleteAllEmpty}
                                    className="h-7 text-xs"
                                    title={t(
                                        'Wipes every conversation in this workspace that never received a visitor message.',
                                    )}
                                >
                                    <Trash2 className="mr-1 size-3" />
                                    {t('Delete :count empty', {
                                        count: totals.empty,
                                    })}
                                </Button>
                            )}
                        </div>
                    )}
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
                                ? t('Try a different keyword or agent name.')
                                : showMode === 'engaged'
                                  ? t(
                                        'Drive-by widget loads are hidden here. Toggle "Show all" to see every session.',
                                    )
                                  : t(
                                        'Once visitors start chatting with any agent in this workspace, those sessions will appear here.',
                                    )}
                        </p>
                    </Card>
                ) : (
                    <Card className="p-0">
                        {can_delete && (
                            <div className="flex items-center gap-2 border-b px-4 py-2 text-xs">
                                <input
                                    type="checkbox"
                                    checked={allOnPageSelected}
                                    onChange={toggleAllOnPage}
                                    className="size-3.5"
                                    aria-label={t('Select all on page')}
                                />
                                <span className="text-muted-foreground">
                                    {selected.size > 0
                                        ? t(':count selected', {
                                              count: selected.size,
                                          })
                                        : t('Select all on page')}
                                </span>
                            </div>
                        )}
                        <div className="divide-y">
                            {conversations.map((conversation) => {
                                const isChecked = selected.has(conversation.id);

                                return (
                                    <div
                                        key={conversation.id}
                                        className="group flex items-start gap-3 px-4 py-3 transition hover:bg-muted/50"
                                    >
                                        {can_delete && (
                                            <input
                                                type="checkbox"
                                                checked={isChecked}
                                                onChange={() =>
                                                    toggleRow(conversation.id)
                                                }
                                                className="mt-2 size-3.5"
                                                aria-label={t(
                                                    'Select conversation',
                                                )}
                                            />
                                        )}
                                        <Link
                                            href={conversationShow(
                                                conversation.id,
                                            )}
                                            className="flex min-w-0 flex-1 items-start gap-3"
                                        >
                                            <div className="flex size-8 shrink-0 items-center justify-center rounded-full bg-muted">
                                                <Globe className="size-4 text-muted-foreground" />
                                            </div>
                                            <div className="min-w-0 flex-1">
                                                <div className="flex flex-wrap items-center gap-2">
                                                    {conversation.agent ? (
                                                        <span className="inline-flex items-center gap-1 rounded-full bg-sky-500/10 px-2 py-0.5 text-[11px] font-medium text-sky-700 dark:text-sky-300">
                                                            <Sparkles className="size-3" />
                                                            {
                                                                conversation
                                                                    .agent.name
                                                            }
                                                        </span>
                                                    ) : null}
                                                    {conversation.is_returning && (
                                                        <span className="rounded bg-violet-500/15 px-1.5 py-0.5 text-[10px] font-medium text-violet-700 dark:text-violet-400">
                                                            {t('returning')}
                                                        </span>
                                                    )}
                                                    {conversation.lang &&
                                                        conversation.lang !==
                                                            'en' && (
                                                            <span className="rounded bg-sky-500/15 px-1.5 py-0.5 text-[10px] font-medium text-sky-700 uppercase dark:text-sky-400">
                                                                {
                                                                    conversation.lang
                                                                }
                                                            </span>
                                                        )}
                                                    {conversation.human_requested_at &&
                                                        !conversation.claimed_by_user_id && (
                                                            <span className="inline-flex items-center gap-1 rounded-full bg-amber-500/15 px-2 py-0.5 text-[10px] font-semibold tracking-wider text-amber-700 uppercase">
                                                                <Hand className="size-3" />
                                                                {t(
                                                                    'Needs human',
                                                                )}
                                                            </span>
                                                        )}
                                                    {conversation.claimed_by_user_id && (
                                                        <span className="inline-flex items-center gap-1 rounded-full bg-emerald-500/15 px-2 py-0.5 text-[10px] font-semibold tracking-wider text-emerald-700 uppercase">
                                                            <Headset className="size-3" />
                                                            {t('Live')}
                                                        </span>
                                                    )}
                                                    {conversation.satisfaction ===
                                                        'positive' && (
                                                        <span className="inline-flex items-center gap-1 rounded-full bg-emerald-100 px-2 py-0.5 text-[10px] font-semibold text-emerald-800">
                                                            👍
                                                        </span>
                                                    )}
                                                    {conversation.satisfaction ===
                                                        'negative' && (
                                                        <span className="inline-flex items-center gap-1 rounded-full bg-red-100 px-2 py-0.5 text-[10px] font-semibold text-red-800">
                                                            👎
                                                        </span>
                                                    )}
                                                    {conversation.tags?.map(
                                                        (tag) => (
                                                            <span
                                                                key={tag.id}
                                                                className="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[10px] font-semibold text-white"
                                                                style={{
                                                                    background:
                                                                        tag.color,
                                                                }}
                                                            >
                                                                {tag.label}
                                                            </span>
                                                        ),
                                                    )}
                                                </div>
                                                <p className="mt-2 truncate text-sm font-medium">
                                                    {conversation.preview}
                                                </p>
                                                <p className="mt-0.5 truncate text-xs text-muted-foreground">
                                                    {conversation.page_url ??
                                                        t('(no page url)')}
                                                </p>
                                                <p className="mt-1 inline-flex items-center gap-1 text-[11px] text-muted-foreground/80">
                                                    <User className="size-3" />
                                                    {conversation.visitor_anon
                                                        ? conversation.visitor_anon.slice(
                                                              0,
                                                              16,
                                                          )
                                                        : t(
                                                              '(unknown visitor)',
                                                          )}
                                                </p>
                                            </div>
                                            <div className="flex shrink-0 flex-col items-end gap-1 text-end">
                                                {conversation.lead_score >
                                                    0 && (
                                                    <LeadScoreBadge
                                                        score={
                                                            conversation.lead_score
                                                        }
                                                        bucket={
                                                            conversation.lead_score_bucket
                                                        }
                                                        reasons={
                                                            conversation.lead_score_reasons
                                                        }
                                                    />
                                                )}
                                                <span className="text-xs font-medium tabular-nums">
                                                    {t(':count msgs', {
                                                        count: conversation.message_count,
                                                    })}
                                                </span>
                                                <span className="text-[10px] text-muted-foreground">
                                                    {relativeTime(
                                                        conversation.last_activity_at,
                                                        t,
                                                    )}
                                                </span>
                                            </div>
                                            <ChevronRight className="mt-2 size-4 shrink-0 text-muted-foreground" />
                                        </Link>
                                        {can_delete && (
                                            <button
                                                type="button"
                                                onClick={() =>
                                                    deleteOne(conversation.id)
                                                }
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
                                );
                            })}
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

function FilterPill({
    label,
    count,
    active,
    onClick,
    accent,
    icon,
}: {
    label: string;
    count?: number;
    active: boolean;
    onClick: () => void;
    accent?: 'amber' | 'emerald';
    icon?: React.ReactNode;
}) {
    const accentClass =
        accent === 'amber'
            ? active
                ? 'bg-amber-500 text-white border-amber-500'
                : 'border-amber-300 bg-amber-50 text-amber-800 hover:bg-amber-100'
            : accent === 'emerald'
              ? active
                  ? 'bg-emerald-600 text-white border-emerald-600'
                  : 'border-emerald-300 bg-emerald-50 text-emerald-800 hover:bg-emerald-100'
              : active
                ? 'bg-foreground text-background border-foreground'
                : 'border-border hover:bg-muted';

    return (
        <button
            type="button"
            onClick={onClick}
            className={`inline-flex items-center gap-1.5 rounded-full border px-3 py-1 text-xs font-medium transition ${accentClass}`}
        >
            {icon}
            {label}
            {count !== undefined && count > 0 && (
                <span
                    className={`rounded-full px-1.5 text-[10px] font-semibold tabular-nums ${active ? 'bg-white/20' : 'bg-foreground/10'}`}
                >
                    {count}
                </span>
            )}
        </button>
    );
}
