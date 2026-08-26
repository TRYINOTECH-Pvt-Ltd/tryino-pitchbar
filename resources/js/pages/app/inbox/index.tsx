import { Head, Link, router } from '@inertiajs/react';
import { Flame, Inbox as InboxIcon, Mail, Phone } from 'lucide-react';
import { useEffect, useState } from 'react';
import {
    BulkActionsBar,
    BulkDeleteButton,
} from '@/components/bulk-actions-bar';
import { LeadScoreBadge } from '@/components/lead-score-badge';
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
import { index as inboxIndex, show as showLead } from '@/routes/inbox';
import type { BreadcrumbItem } from '@/types';

type Lead = {
    id: string;
    email: string;
    name: string | null;
    phone: string | null;
    status: 'new' | 'qualified' | 'contacted' | 'won' | 'lost' | string;
    page_url: string | null;
    created_at: string | null;
    lead_score: number;
    lead_score_bucket: 'low' | 'medium' | 'high';
    lead_score_reasons: string[];
};

type Props = {
    leads: Lead[];
    pagination: PaginationMeta;
    filters: {
        q: string;
        view:
            | 'all'
            | 'new'
            | 'qualified'
            | 'contacted'
            | 'won'
            | 'lost'
            | string;
        sort:
            | 'created_desc'
            | 'created_asc'
            | 'name_asc'
            | 'name_desc'
            | string;
        phone: 'all' | 'with_phone' | 'without_phone' | string;
        hot: boolean;
    };
};

type InboxColumnId =
    | 'status'
    | 'score'
    | 'email'
    | 'phone'
    | 'page'
    | 'created';

const STATUS_STYLES: Record<string, string> = {
    new: 'border-sky-200 bg-sky-50 text-sky-700 dark:border-sky-500/30 dark:bg-sky-500/10 dark:text-sky-300',
    qualified:
        'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-300',
    contacted:
        'border-amber-200 bg-amber-50 text-amber-700 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-300',
    won: 'border-violet-200 bg-violet-50 text-violet-700 dark:border-violet-500/30 dark:bg-violet-500/10 dark:text-violet-300',
    lost: 'border-border bg-muted text-muted-foreground',
};

const INBOX_ONLY = ['leads', 'pagination', 'filters'];

function inboxColumns(
    t: (key: string) => string,
): TableColumnOption<InboxColumnId>[] {
    return [
        { id: 'status', label: t('Status') },
        { id: 'score', label: t('Score') },
        { id: 'email', label: t('Email') },
        { id: 'phone', label: t('Phone') },
        { id: 'page', label: t('Page') },
        { id: 'created', label: t('Created') },
    ];
}

function leadViewOptions(t: (key: string) => string): TableMenuOption[] {
    return [
        { value: 'all', label: t('All leads') },
        { value: 'new', label: t('New') },
        { value: 'qualified', label: t('Qualified') },
        { value: 'contacted', label: t('Contacted') },
        { value: 'won', label: t('Won') },
        { value: 'lost', label: t('Lost') },
    ];
}

function inboxSortOptions(t: (key: string) => string): TableMenuOption[] {
    return [
        { value: 'created_desc', label: t('Newest first') },
        { value: 'created_asc', label: t('Oldest first') },
        { value: 'name_asc', label: t('Lead name A-Z') },
        { value: 'name_desc', label: t('Lead name Z-A') },
    ];
}

function phoneFilterOptions(t: (key: string) => string): TableMenuOption[] {
    return [
        { value: 'all', label: t('Any phone state') },
        { value: 'with_phone', label: t('Has phone number') },
        { value: 'without_phone', label: t('Missing phone number') },
    ];
}

function relativeTime(
    iso: string | null,
    t: (key: string, replacements?: Record<string, string | number>) => string,
): string {
    if (!iso) {
        return t('No activity');
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

    return t(':count d ago', { count: day });
}

/**
 * Inline status editor that PATCHes /app/inbox/{lead} with the picked
 * status. Lives on the row so an admin can triage 10 new leads without
 * opening each one. Pre-fix the Status column rendered a read-only
 * badge that always read "new" because the Lead model defaults to that
 * value and nothing ever transitioned it.
 */
function StatusSelect({ leadId, status }: { leadId: string; status: string }) {
    const { t } = useT();
    const STATUSES = ['new', 'qualified', 'contacted', 'won', 'lost'] as const;
    const change = (next: string) => {
        if (next === status) {
            return;
        }

        router.patch(
            `/app/inbox/${leadId}`,
            { status: next },
            { preserveScroll: true, preserveState: true, only: INBOX_ONLY },
        );
    };

    return (
        <select
            value={STATUSES.includes(status as never) ? status : 'new'}
            onChange={(e) => change(e.target.value)}
            className={`appearance-none rounded-md border px-2 py-0.5 text-xs font-medium capitalize outline-none ${
                STATUS_STYLES[status] ?? STATUS_STYLES.new
            }`}
            onClick={(e) => e.stopPropagation()}
            aria-label={t('Lead status')}
        >
            {STATUSES.map((s) => (
                <option key={s} value={s}>
                    {t(s.charAt(0).toUpperCase() + s.slice(1))}
                </option>
            ))}
        </select>
    );
}

export default function InboxIndex({ leads, pagination, filters }: Props) {
    const { t } = useT();
    const selection = useBulkSelection();
    const pageIds = leads.map((l) => l.id);
    const breadcrumbs: BreadcrumbItem[] = [
        { title: t('Inbox'), href: inboxIndex() },
    ];

    // Client-reported (2026-05-22): clicking Inbox from the sidebar
    // froze the whole page. Root cause: an eager `router.reload()`
    // fired on every mount. With Inertia v3 partial reloads, this could
    // race with rapid focus events from the OS and amplify into an
    // infinite re-render loop. The mount-time refetch was redundant —
    // a fresh Inertia visit already brings current data. We keep only
    // the on-focus and visibility listeners for the tab-switch case
    // (stale data after a long-running tab), and de-bounce them with
    // a one-shot guard so they can't fire back-to-back.
    useEffect(() => {
        let pending = false;
        const refetch = () => {
            if (pending) {
                return;
            }

            pending = true;
            router.reload({
                only: INBOX_ONLY,
                onFinish: () => {
                    pending = false;
                },
            });
        };
        const onVisible = () => {
            if (document.visibilityState === 'visible') {
                refetch();
            }
        };

        window.addEventListener('focus', refetch);
        document.addEventListener('visibilitychange', onVisible);

        return () => {
            window.removeEventListener('focus', refetch);
            document.removeEventListener('visibilitychange', onVisible);
        };
    }, []);
    const INBOX_COLUMNS = inboxColumns(t);
    const LEAD_VIEW_OPTIONS = leadViewOptions(t);
    const INBOX_SORT_OPTIONS = inboxSortOptions(t);
    const PHONE_FILTER_OPTIONS = phoneFilterOptions(t);
    const {
        hiddenColumnCount,
        isColumnVisible,
        resetColumns,
        setColumnVisibility,
        visibleColumns,
    } = usePersistentTableColumns('table-columns:inbox', INBOX_COLUMNS);

    // Density toggle — operator preference for vertical row spacing.
    // Persisted per-browser so the choice survives navigation. Buyer
    // ask 2026-05-19 (inbox UX): "compact mode for power users
    // triaging 50+ leads at once".
    const [density, setDensityState] = useState<'compact' | 'comfortable'>(
        () => {
            try {
                const stored = window.localStorage.getItem('pb:inbox:density');

                return stored === 'comfortable' ? 'comfortable' : 'compact';
            } catch {
                return 'compact';
            }
        },
    );
    const setDensity = (next: 'compact' | 'comfortable') => {
        setDensityState(next);

        try {
            window.localStorage.setItem('pb:inbox:density', next);
        } catch {
            // best-effort
        }
    };
    const rowPyClass = density === 'compact' ? 'py-1.5' : 'py-3';

    const hasRows = leads.length > 0;
    const hasActiveFilters =
        filters.q.trim() !== '' ||
        filters.view !== 'all' ||
        filters.phone !== 'all';
    const emptyTitle = hasActiveFilters
        ? t('No matching leads')
        : t('No leads yet');
    const visibleColumnCount = INBOX_COLUMNS.filter((column) =>
        isColumnVisible(column.id),
    ).length;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('Inbox')} />
            <div className="flex min-h-0 flex-1 flex-col bg-card">
                <div className="flex min-h-10 flex-wrap items-center gap-2 border-b px-3 py-1.5">
                    <TableScopeMenu
                        options={LEAD_VIEW_OPTIONS}
                        value={filters.view}
                        paramName="view"
                        only={INBOX_ONLY}
                    />
                    <TableSortMenu
                        options={INBOX_SORT_OPTIONS}
                        value={filters.sort}
                        paramName="sort"
                        only={INBOX_ONLY}
                    />
                    <TableFiltersMenu
                        groups={[
                            {
                                label: t('Phone'),
                                paramName: 'phone',
                                value: filters.phone,
                                defaultValue: 'all',
                                options: PHONE_FILTER_OPTIONS,
                            },
                        ]}
                        only={INBOX_ONLY}
                    />
                    <TableSearch
                        placeholder={t('Search by email, name, or phone...')}
                        initialValue={filters.q}
                        only={INBOX_ONLY}
                    />
                    <Button
                        type="button"
                        variant={filters.hot ? 'default' : 'outline'}
                        size="sm"
                        onClick={() => {
                            router.get(
                                inboxIndex().url,
                                {
                                    q: filters.q || undefined,
                                    view:
                                        filters.view === 'all'
                                            ? undefined
                                            : filters.view,
                                    sort:
                                        filters.sort === 'created_desc'
                                            ? undefined
                                            : filters.sort,
                                    phone:
                                        filters.phone === 'all'
                                            ? undefined
                                            : filters.phone,
                                    hot: filters.hot ? undefined : 1,
                                },
                                {
                                    preserveScroll: true,
                                    preserveState: true,
                                    only: INBOX_ONLY,
                                },
                            );
                        }}
                        title={t('Show only leads with score ≥ 70')}
                    >
                        <Flame className="size-4" />
                        <span className="ms-1">{t('Hot only')}</span>
                    </Button>
                    <div className="ms-auto flex items-center gap-2">
                        <div className="inline-flex overflow-hidden rounded-md border text-xs">
                            <button
                                type="button"
                                onClick={() => setDensity('compact')}
                                className={
                                    density === 'compact'
                                        ? 'bg-foreground px-2.5 py-1 text-background'
                                        : 'px-2.5 py-1 hover:bg-muted'
                                }
                                title={t('Compact rows')}
                            >
                                {t('Compact')}
                            </button>
                            <button
                                type="button"
                                onClick={() => setDensity('comfortable')}
                                className={
                                    density === 'comfortable'
                                        ? 'border-s bg-foreground px-2.5 py-1 text-background'
                                        : 'border-s px-2.5 py-1 hover:bg-muted'
                                }
                                title={t('Comfortable rows')}
                            >
                                {t('Comfortable')}
                            </button>
                        </div>
                        <TableColumnMenu
                            columns={INBOX_COLUMNS}
                            visibleColumns={visibleColumns}
                            hiddenColumnCount={hiddenColumnCount}
                            onResetColumns={resetColumns}
                            onSetColumnVisibility={setColumnVisibility}
                        />
                    </div>
                </div>

                <div className="min-h-0 flex-1 overflow-auto">
                    <table className="w-full min-w-[920px] border-separate border-spacing-0 text-start text-sm">
                        <thead className="sticky top-0 z-10 bg-card text-xs font-medium text-muted-foreground">
                            <tr>
                                <th className="w-9 border-b px-3 py-2">
                                    <input
                                        type="checkbox"
                                        aria-label={t('Select all leads')}
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
                                    {t('Lead')}
                                </th>
                                {isColumnVisible('status') && (
                                    <th className="border-e border-b px-3 py-2">
                                        {t('Status')}
                                    </th>
                                )}
                                {isColumnVisible('score') && (
                                    <th className="border-e border-b px-3 py-2">
                                        {t('Score')}
                                    </th>
                                )}
                                {isColumnVisible('email') && (
                                    <th className="border-e border-b px-3 py-2">
                                        {t('Email')}
                                    </th>
                                )}
                                {isColumnVisible('phone') && (
                                    <th className="border-e border-b px-3 py-2">
                                        {t('Phone')}
                                    </th>
                                )}
                                {isColumnVisible('page') && (
                                    <th className="border-e border-b px-3 py-2">
                                        {t('Page')}
                                    </th>
                                )}
                                {isColumnVisible('created') && (
                                    <th className="border-b px-3 py-2">
                                        {t('Created')}
                                    </th>
                                )}
                            </tr>
                        </thead>
                        <tbody>
                            {hasRows ? (
                                leads.map((lead) => {
                                    // Some legacy leads have NULL email
                                    // and NULL name (older widget runs
                                    // without lead capture, or leads
                                    // that came in via an API with
                                    // partial payloads). Both `name ??
                                    // email` could resolve to null and
                                    // crash `label.slice(0, 1)` below,
                                    // which was bringing the whole inbox
                                    // page down. Fall back to a stable
                                    // 'Unnamed lead' string so the row
                                    // still renders.
                                    const label =
                                        lead.name ??
                                        lead.email ??
                                        t('Unnamed lead');

                                    return (
                                        <tr
                                            key={lead.id}
                                            className="group hover:bg-muted/35"
                                        >
                                            <td
                                                className={`border-b px-3 ${rowPyClass}`}
                                            >
                                                <input
                                                    type="checkbox"
                                                    aria-label={t(
                                                        'Select :label',
                                                        { label },
                                                    )}
                                                    checked={selection.isSelected(
                                                        lead.id,
                                                    )}
                                                    onChange={(e) => {
                                                        const nativeEvent = (
                                                            e as unknown as {
                                                                nativeEvent: MouseEvent;
                                                            }
                                                        ).nativeEvent;
                                                        selection.toggle(
                                                            lead.id,
                                                            {
                                                                shiftKey:
                                                                    nativeEvent?.shiftKey ??
                                                                    false,
                                                                pageIds,
                                                            },
                                                        );
                                                    }}
                                                    onClick={(e) =>
                                                        e.stopPropagation()
                                                    }
                                                    className="size-3.5 cursor-pointer rounded border-input"
                                                />
                                            </td>
                                            <td
                                                className={`border-e border-b px-3 ${rowPyClass}`}
                                            >
                                                <Link
                                                    href={showLead(lead.id)}
                                                    className="flex min-w-0 items-center gap-2 text-foreground"
                                                >
                                                    <span className="flex size-5 shrink-0 items-center justify-center rounded-full bg-muted text-[10px] font-semibold uppercase">
                                                        {label.slice(0, 1)}
                                                    </span>
                                                    <span className="truncate font-medium">
                                                        {label}
                                                    </span>
                                                </Link>
                                            </td>
                                            {isColumnVisible('status') && (
                                                <td
                                                    className={`border-e border-b px-3 ${rowPyClass}`}
                                                >
                                                    <StatusSelect
                                                        leadId={lead.id}
                                                        status={lead.status}
                                                    />
                                                </td>
                                            )}
                                            {isColumnVisible('score') && (
                                                <td
                                                    className={`border-e border-b px-3 ${rowPyClass}`}
                                                >
                                                    {lead.lead_score > 0 ? (
                                                        <LeadScoreBadge
                                                            score={
                                                                lead.lead_score
                                                            }
                                                            bucket={
                                                                lead.lead_score_bucket
                                                            }
                                                            reasons={
                                                                lead.lead_score_reasons
                                                            }
                                                        />
                                                    ) : (
                                                        <span className="text-xs text-muted-foreground">
                                                            —
                                                        </span>
                                                    )}
                                                </td>
                                            )}
                                            {isColumnVisible('email') && (
                                                <td
                                                    className={`border-e border-b px-3 ${rowPyClass} text-muted-foreground`}
                                                >
                                                    <span className="inline-flex min-w-0 items-center gap-1.5">
                                                        <Mail className="size-3.5 shrink-0" />
                                                        <span className="truncate">
                                                            {lead.email}
                                                        </span>
                                                    </span>
                                                </td>
                                            )}
                                            {isColumnVisible('phone') && (
                                                <td
                                                    className={`border-e border-b px-3 ${rowPyClass} text-muted-foreground`}
                                                >
                                                    {lead.phone ? (
                                                        <span className="inline-flex items-center gap-1.5">
                                                            <Phone className="size-3.5" />
                                                            {lead.phone}
                                                        </span>
                                                    ) : (
                                                        t('No phone')
                                                    )}
                                                </td>
                                            )}
                                            {isColumnVisible('page') && (
                                                <td
                                                    className={`max-w-[280px] border-e border-b px-3 ${rowPyClass} text-muted-foreground`}
                                                >
                                                    <span className="block truncate">
                                                        {lead.page_url ??
                                                            t('No page')}
                                                    </span>
                                                </td>
                                            )}
                                            {isColumnVisible('created') && (
                                                <td
                                                    className={`border-b px-3 ${rowPyClass} text-muted-foreground`}
                                                >
                                                    {relativeTime(
                                                        lead.created_at,
                                                        t,
                                                    )}
                                                </td>
                                            )}
                                        </tr>
                                    );
                                })
                            ) : (
                                <tr>
                                    <td
                                        colSpan={2 + visibleColumnCount}
                                        className="h-72 border-b px-4 text-center"
                                    >
                                        <div className="mx-auto flex max-w-sm flex-col items-center gap-2 text-muted-foreground">
                                            <InboxIcon className="size-8" />
                                            <p className="text-sm font-medium text-foreground">
                                                {emptyTitle}
                                            </p>
                                            <p className="text-xs">
                                                {t(
                                                    'Leads captured by the widget appear here, newest first.',
                                                )}
                                            </p>
                                        </div>
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>

                <TablePagination pagination={pagination} only={INBOX_ONLY} />
            </div>
            <BulkActionsBar
                count={selection.size}
                onClear={selection.clear}
                noun={t('lead')}
            >
                <BulkDeleteButton
                    confirmMessage={t(
                        'Delete :count selected lead(s)? This removes them from the inbox.',
                        { count: selection.size },
                    )}
                    onConfirm={() => {
                        router.post(
                            '/app/inbox/bulk-destroy',
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
