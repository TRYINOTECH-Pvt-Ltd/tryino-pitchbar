import { Head, Link, router } from '@inertiajs/react';
import {
    Bot,
    CheckCircle2,
    Inbox,
    Library,
    MessagesSquare,
    Plus,
    Settings,
    Trash2,
} from 'lucide-react';
import {
    BulkActionsBar,
    BulkDeleteButton,
} from '@/components/bulk-actions-bar';
import { useConfirm } from '@/components/confirm-dialog-provider';
import { PlanLimitBanner } from '@/components/plan-limit-banner';
import { TablePagination } from '@/components/table-pagination';
import type { PaginationMeta } from '@/components/table-pagination';
import { TableSearch } from '@/components/table-search';
import {
    TableColumnMenu,
    TableFiltersMenu,
    TableScopeMenu,
    TableSortMenu,
} from '@/components/table-toolbar-controls';
import type { TableMenuOption } from '@/components/table-toolbar-controls';
import { Button } from '@/components/ui/button';
import { useBulkSelection } from '@/hooks/use-bulk-selection';
import { usePersistentTableColumns } from '@/hooks/use-persistent-table-columns';
import type { TableColumnOption } from '@/hooks/use-persistent-table-columns';
import AppLayout from '@/layouts/app-layout';
import { useT } from '@/lib/i18n';
import {
    bulkDestroy as bulkDestroyAgents,
    create as createAgent,
    destroy as destroyAgent,
    edit as editAgent,
    index as agentsIndex,
    show as showAgent,
} from '@/routes/agents';
import type { BreadcrumbItem } from '@/types';

type AgentRow = {
    id: string;
    name: string;
    language_default: string;
    is_published: boolean;
    updated_at: string;
    sources: { total: number; indexed: number };
    conversations_7d: number;
    leads_7d: number;
};

type Props = {
    agents: AgentRow[];
    pagination: PaginationMeta;
    filters: {
        q: string;
        view: 'all' | 'published' | 'draft' | string;
        sort:
            | 'updated_desc'
            | 'updated_asc'
            | 'name_asc'
            | 'name_desc'
            | string;
        language: string;
    };
    filterOptions: {
        languages: string[];
    };
    sourceQuota: {
        limit: number | null;
        current: number;
    };
};

type AgentColumnId =
    | 'status'
    | 'language'
    | 'knowledge'
    | 'conversations'
    | 'leads'
    | 'updated'
    | 'settings';

const AGENTS_ONLY = ['agents', 'pagination', 'filters'];

function useRelativeTime(): (iso: string | null) => string {
    const { t } = useT();

    return (iso: string | null): string => {
        if (!iso) {
            return t('No activity');
        }

        const ms = Date.now() - new Date(iso).getTime();
        const min = Math.max(1, Math.round(ms / 60000));

        if (min < 60) {
            return t(':min m ago', { min });
        }

        const hr = Math.round(min / 60);

        if (hr < 24) {
            return t(':hr h ago', { hr });
        }

        const day = Math.round(hr / 24);

        if (day < 7) {
            return t(':day d ago', { day });
        }

        return new Date(iso).toLocaleDateString();
    };
}

function PublishBadge({ published }: { published: boolean }) {
    const { t } = useT();

    if (published) {
        return (
            <span className="inline-flex items-center gap-1 rounded-md border border-emerald-200 bg-emerald-50 px-2 py-0.5 text-xs font-medium text-emerald-700 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-300">
                <CheckCircle2 className="size-3" />
                {t('Published')}
            </span>
        );
    }

    return (
        <span className="inline-flex items-center rounded-md border border-amber-200 bg-amber-50 px-2 py-0.5 text-xs font-medium text-amber-700 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-300">
            {t('Draft')}
        </span>
    );
}

export default function AgentsIndex({
    agents,
    pagination,
    filters,
    filterOptions,
    sourceQuota,
}: Props) {
    const { t } = useT();
    const relativeTime = useRelativeTime();
    const confirm = useConfirm();
    const selection = useBulkSelection();
    const pageIds = agents.map((a) => a.id);

    const breadcrumbs: BreadcrumbItem[] = [
        { title: t('Agents'), href: agentsIndex() },
    ];

    const AGENT_COLUMNS: TableColumnOption<AgentColumnId>[] = [
        { id: 'status', label: t('Status') },
        { id: 'language', label: t('Language') },
        { id: 'knowledge', label: t('Knowledge') },
        { id: 'conversations', label: t('Conversations') },
        { id: 'leads', label: t('Leads') },
        { id: 'updated', label: t('Updated') },
        { id: 'settings', label: t('Settings') },
    ];

    const AGENT_VIEW_OPTIONS: TableMenuOption[] = [
        { value: 'all', label: t('All agents') },
        { value: 'published', label: t('Published agents') },
        { value: 'draft', label: t('Draft agents') },
    ];

    const AGENT_SORT_OPTIONS: TableMenuOption[] = [
        { value: 'updated_desc', label: t('Recently updated') },
        { value: 'updated_asc', label: t('Oldest updated') },
        { value: 'name_asc', label: t('Name A-Z') },
        { value: 'name_desc', label: t('Name Z-A') },
    ];

    const {
        hiddenColumnCount,
        isColumnVisible,
        resetColumns,
        setColumnVisibility,
        visibleColumns,
    } = usePersistentTableColumns('table-columns:agents', AGENT_COLUMNS);
    const hasRows = agents.length > 0;
    const hasActiveFilters =
        filters.q.trim() !== '' ||
        filters.view !== 'all' ||
        filters.language !== 'all';
    const emptyTitle = hasActiveFilters
        ? t('No matching agents')
        : t('No agents yet');
    const visibleColumnCount = AGENT_COLUMNS.filter((column) =>
        isColumnVisible(column.id),
    ).length;
    const languageOptions: TableMenuOption[] = [
        { value: 'all', label: t('All languages') },
        ...filterOptions.languages.map((language) => ({
            value: language,
            label: language.toUpperCase(),
        })),
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Agents" />
            <div className="flex min-h-0 flex-1 flex-col bg-card">
                <div className="px-3 pt-2">
                    <PlanLimitBanner resource="agent" />
                </div>
                <div className="flex min-h-10 flex-wrap items-center gap-2 border-b px-3 py-1.5">
                    <TableScopeMenu
                        options={AGENT_VIEW_OPTIONS}
                        value={filters.view}
                        paramName="view"
                        only={AGENTS_ONLY}
                    />
                    <TableSortMenu
                        options={AGENT_SORT_OPTIONS}
                        value={filters.sort}
                        paramName="sort"
                        only={AGENTS_ONLY}
                    />
                    <TableFiltersMenu
                        groups={[
                            {
                                label: t('Language'),
                                paramName: 'language',
                                value: filters.language,
                                defaultValue: 'all',
                                options: languageOptions,
                            },
                        ]}
                        only={AGENTS_ONLY}
                    />
                    <TableSearch
                        placeholder={t('Search agents...')}
                        initialValue={filters.q}
                        only={AGENTS_ONLY}
                    />
                    <div className="ms-auto flex items-center gap-2">
                        <TableColumnMenu
                            columns={AGENT_COLUMNS}
                            visibleColumns={visibleColumns}
                            hiddenColumnCount={hiddenColumnCount}
                            onResetColumns={resetColumns}
                            onSetColumnVisibility={setColumnVisibility}
                        />
                        <Button asChild size="sm">
                            <Link href={createAgent()} prefetch>
                                <Plus className="size-3.5" />
                                {t('New agent')}
                            </Link>
                        </Button>
                    </div>
                </div>

                <div className="min-h-0 flex-1 overflow-auto">
                    <table className="w-full min-w-[980px] border-separate border-spacing-0 text-start text-sm">
                        <thead className="sticky top-0 z-10 bg-card text-xs font-medium text-muted-foreground">
                            <tr>
                                <th className="w-9 border-b px-3 py-2">
                                    <input
                                        type="checkbox"
                                        aria-label={t('Select all agents')}
                                        checked={selection.allOnPageSelected(
                                            pageIds,
                                        )}
                                        ref={(el) => {
                                            if (el) {
                                                el.indeterminate =
                                                    !selection.allOnPageSelected(
                                                        pageIds,
                                                    ) &&
                                                    selection.someOnPageSelected(
                                                        pageIds,
                                                    );
                                            }
                                        }}
                                        onChange={() =>
                                            selection.togglePage(pageIds)
                                        }
                                        className="size-3.5 cursor-pointer rounded border-input"
                                    />
                                </th>
                                <th className="border-e border-b px-3 py-2">
                                    {t('Agent')}
                                </th>
                                {isColumnVisible('status') && (
                                    <th className="border-e border-b px-3 py-2">
                                        {t('Status')}
                                    </th>
                                )}
                                {isColumnVisible('language') && (
                                    <th className="border-e border-b px-3 py-2">
                                        {t('Language')}
                                    </th>
                                )}
                                {isColumnVisible('knowledge') && (
                                    <th className="border-e border-b px-3 py-2">
                                        {t('Knowledge')}
                                    </th>
                                )}
                                {isColumnVisible('conversations') && (
                                    <th className="border-e border-b px-3 py-2">
                                        {t('Conversations')}
                                    </th>
                                )}
                                {isColumnVisible('leads') && (
                                    <th className="border-e border-b px-3 py-2">
                                        {t('Leads')}
                                    </th>
                                )}
                                {isColumnVisible('updated') && (
                                    <th className="border-e border-b px-3 py-2">
                                        {t('Updated')}
                                    </th>
                                )}
                                {isColumnVisible('settings') && (
                                    <th className="border-b px-3 py-2">
                                        {t('Settings')}
                                    </th>
                                )}
                            </tr>
                        </thead>
                        <tbody>
                            {hasRows ? (
                                agents.map((agent) => (
                                    <tr
                                        key={agent.id}
                                        className="group hover:bg-muted/35"
                                    >
                                        <td className="border-b px-3 py-2">
                                            <input
                                                type="checkbox"
                                                aria-label={t('Select :name', {
                                                    name: agent.name,
                                                })}
                                                checked={selection.isSelected(
                                                    agent.id,
                                                )}
                                                onChange={(e) => {
                                                    const nativeEvent = (
                                                        e as unknown as {
                                                            nativeEvent: MouseEvent;
                                                        }
                                                    ).nativeEvent;
                                                    selection.toggle(agent.id, {
                                                        shiftKey:
                                                            nativeEvent?.shiftKey ??
                                                            false,
                                                        pageIds,
                                                    });
                                                }}
                                                onClick={(e) =>
                                                    e.stopPropagation()
                                                }
                                                className="size-3.5 cursor-pointer rounded border-input"
                                            />
                                        </td>
                                        <td className="border-e border-b px-3 py-2">
                                            <Link
                                                href={showAgent(agent.id)}
                                                className="flex min-w-0 items-center gap-2 text-foreground"
                                            >
                                                <span className="flex size-5 shrink-0 items-center justify-center rounded bg-muted text-muted-foreground">
                                                    <Bot className="size-3.5" />
                                                </span>
                                                <span className="truncate font-medium">
                                                    {agent.name}
                                                </span>
                                            </Link>
                                        </td>
                                        {isColumnVisible('status') && (
                                            <td className="border-e border-b px-3 py-2">
                                                <PublishBadge
                                                    published={
                                                        agent.is_published
                                                    }
                                                />
                                            </td>
                                        )}
                                        {isColumnVisible('language') && (
                                            <td className="border-e border-b px-3 py-2 text-muted-foreground uppercase">
                                                {agent.language_default}
                                            </td>
                                        )}
                                        {isColumnVisible('knowledge') && (
                                            <td className="border-e border-b px-3 py-2 text-muted-foreground">
                                                <span
                                                    className="inline-flex items-center gap-1.5"
                                                    title={t(
                                                        ':indexed indexed · :total total',
                                                        {
                                                            indexed:
                                                                agent.sources
                                                                    .indexed,
                                                            total: agent.sources
                                                                .total,
                                                        },
                                                    )}
                                                >
                                                    <Library className="size-3.5" />
                                                    {agent.sources.total}
                                                    {sourceQuota.limit !== null
                                                        ? `/${sourceQuota.limit}`
                                                        : ''}
                                                </span>
                                            </td>
                                        )}
                                        {isColumnVisible('conversations') && (
                                            <td className="border-e border-b px-3 py-2 text-muted-foreground">
                                                <span className="inline-flex items-center gap-1.5 tabular-nums">
                                                    <MessagesSquare className="size-3.5" />
                                                    {agent.conversations_7d.toLocaleString()}
                                                </span>
                                            </td>
                                        )}
                                        {isColumnVisible('leads') && (
                                            <td className="border-e border-b px-3 py-2 text-muted-foreground">
                                                <span className="inline-flex items-center gap-1.5 tabular-nums">
                                                    <Inbox className="size-3.5" />
                                                    {agent.leads_7d.toLocaleString()}
                                                </span>
                                            </td>
                                        )}
                                        {isColumnVisible('updated') && (
                                            <td className="border-e border-b px-3 py-2 text-muted-foreground">
                                                {relativeTime(agent.updated_at)}
                                            </td>
                                        )}
                                        {isColumnVisible('settings') && (
                                            <td className="border-b px-3 py-2">
                                                <div className="flex items-center gap-1">
                                                    <Link
                                                        href={editAgent(
                                                            agent.id,
                                                        )}
                                                        className="inline-flex size-7 items-center justify-center rounded-md text-muted-foreground transition hover:bg-muted hover:text-foreground"
                                                        aria-label={t(
                                                            'Open :name settings',
                                                            {
                                                                name: agent.name,
                                                            },
                                                        )}
                                                    >
                                                        <Settings className="size-3.5" />
                                                    </Link>
                                                    <button
                                                        type="button"
                                                        onClick={async () => {
                                                            const ok =
                                                                await confirm({
                                                                    title: t(
                                                                        'Delete agent?',
                                                                    ),
                                                                    message: t(
                                                                        'Delete agent ":name"? This removes its sources, conversations, leads, and embedded widget. This action cannot be undone.',
                                                                        {
                                                                            name: agent.name,
                                                                        },
                                                                    ),
                                                                    confirmLabel:
                                                                        t(
                                                                            'Delete',
                                                                        ),
                                                                    danger: true,
                                                                });

                                                            if (!ok) {
                                                                return;
                                                            }

                                                            router.delete(
                                                                destroyAgent.url(
                                                                    {
                                                                        agent: agent.id,
                                                                    },
                                                                ),
                                                                {
                                                                    preserveScroll: true,
                                                                },
                                                            );
                                                        }}
                                                        className="inline-flex size-7 items-center justify-center rounded-md text-muted-foreground transition hover:bg-destructive/10 hover:text-destructive"
                                                        aria-label={t(
                                                            'Delete :name',
                                                            {
                                                                name: agent.name,
                                                            },
                                                        )}
                                                    >
                                                        <Trash2 className="size-3.5" />
                                                    </button>
                                                </div>
                                            </td>
                                        )}
                                    </tr>
                                ))
                            ) : (
                                <tr>
                                    <td
                                        colSpan={2 + visibleColumnCount}
                                        className="h-72 border-b px-4 text-center"
                                    >
                                        <div className="mx-auto flex max-w-sm flex-col items-center gap-2 text-muted-foreground">
                                            <Bot className="size-8" />
                                            <p className="text-sm font-medium text-foreground">
                                                {emptyTitle}
                                            </p>
                                            <p className="text-xs">
                                                {t(
                                                    'Create an agent to start answering visitors and capturing leads.',
                                                )}
                                            </p>
                                            {!hasActiveFilters && (
                                                <Button
                                                    asChild
                                                    size="sm"
                                                    className="mt-2"
                                                >
                                                    <Link href={createAgent()}>
                                                        <Plus className="size-3.5" />
                                                        {t('New agent')}
                                                    </Link>
                                                </Button>
                                            )}
                                        </div>
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>

                <TablePagination pagination={pagination} only={AGENTS_ONLY} />
            </div>
            <BulkActionsBar
                count={selection.size}
                onClear={selection.clear}
                noun={t('agent')}
            >
                <BulkDeleteButton
                    confirmMessage={t(
                        'Delete :count selected agent(s)? This removes their sources, conversations, leads, and embedded widget.',
                        { count: selection.size },
                    )}
                    onConfirm={() => {
                        router.post(
                            bulkDestroyAgents.url(),
                            { ids: Array.from(selection.selected) },
                            {
                                preserveScroll: true,
                                onSuccess: () => selection.clear(),
                            },
                        );
                    }}
                />
            </BulkActionsBar>
        </AppLayout>
    );
}
