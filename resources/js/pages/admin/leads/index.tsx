import { Head, Link, router } from '@inertiajs/react';
import { Inbox, UserRound } from 'lucide-react';
import { AdminSurface, AdminSurfaceBar } from '@/components/admin-surface';
import {
    BulkActionsBar,
    BulkDeleteButton,
} from '@/components/bulk-actions-bar';
import { TablePagination } from '@/components/table-pagination';
import type { PaginationMeta } from '@/components/table-pagination';
import { TableSearch } from '@/components/table-search';
import { useBulkSelection } from '@/hooks/use-bulk-selection';
import AdminLayout from '@/layouts/admin-layout';
import { useT } from '@/lib/i18n';
import { relativeTime } from '@/lib/relative-time';
import { dashboard as adminDashboard } from '@/routes/admin';
import { index as adminLeadsIndex } from '@/routes/admin/leads';
import { show as adminWorkspaceShow } from '@/routes/admin/workspaces';

type Row = {
    id: string;
    email: string | null;
    name: string | null;
    status: string;
    agent: { id: string; name: string } | null;
    workspace: { id: string; name: string } | null;
    created_at: string | null;
};

const LEADS_ONLY = ['leads', 'pagination', 'filters'];

export default function AdminLeads({
    leads,
    pagination,
    filters,
}: {
    leads: Row[];
    pagination: PaginationMeta;
    filters: { q: string };
}) {
    const { t } = useT();
    const selection = useBulkSelection();
    const pageIds = leads.map((l) => l.id);

    return (
        <AdminLayout
            breadcrumbs={[
                { title: t('Dashboard'), href: adminDashboard() },
                { title: t('Leads'), href: adminLeadsIndex() },
            ]}
        >
            <Head title={t('Leads · Admin')} />
            <AdminSurface>
                <AdminSurfaceBar>
                    <span className="inline-flex h-7 items-center rounded-md border bg-card px-2.5 text-xs font-normal text-foreground">
                        {t('All leads')}
                    </span>
                    <span className="inline-flex h-7 items-center rounded-md border bg-card px-2.5 text-xs font-normal text-muted-foreground">
                        {t(':count leads', {
                            count: pagination.total.toLocaleString(),
                        })}
                    </span>
                    <div className="ms-auto w-full sm:w-auto">
                        <TableSearch
                            placeholder={t(
                                'Search by email, name, or phone...',
                            )}
                            initialValue={filters.q}
                            only={LEADS_ONLY}
                        />
                    </div>
                </AdminSurfaceBar>
                <div className="min-h-0 flex-1 overflow-auto">
                    <table className="w-full min-w-[980px] border-separate border-spacing-0 text-start text-sm">
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
                                <th className="border-e border-b px-3 py-2">
                                    {t('Workspace')}
                                </th>
                                <th className="border-e border-b px-3 py-2">
                                    {t('Agent')}
                                </th>
                                <th className="border-e border-b px-3 py-2">
                                    {t('Status')}
                                </th>
                                <th className="border-b px-3 py-2">
                                    {t('Created')}
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            {leads.length === 0 ? (
                                <tr>
                                    <td
                                        colSpan={6}
                                        className="h-72 border-b px-4 text-center"
                                    >
                                        <div className="mx-auto flex max-w-sm flex-col items-center gap-2 text-muted-foreground">
                                            <Inbox className="size-8" />
                                            <p className="text-sm font-medium text-foreground">
                                                {t('No matching leads')}
                                            </p>
                                            <p className="text-xs">
                                                {t(
                                                    'No leads match the current admin search.',
                                                )}
                                            </p>
                                        </div>
                                    </td>
                                </tr>
                            ) : (
                                leads.map((lead) => (
                                    <tr
                                        key={lead.id}
                                        className="group hover:bg-muted/35"
                                    >
                                        <td className="border-b px-3 py-2">
                                            <input
                                                type="checkbox"
                                                aria-label={t('Select :name', {
                                                    name:
                                                        lead.name ??
                                                        lead.email ??
                                                        t('lead'),
                                                })}
                                                checked={selection.isSelected(
                                                    lead.id,
                                                )}
                                                onChange={(e) => {
                                                    const nativeEvent = (
                                                        e as unknown as {
                                                            nativeEvent: MouseEvent;
                                                        }
                                                    ).nativeEvent;
                                                    selection.toggle(lead.id, {
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
                                            <div className="flex min-w-0 items-center gap-2 text-foreground">
                                                <span className="flex size-5 shrink-0 items-center justify-center rounded bg-muted text-muted-foreground">
                                                    {lead.email ? (
                                                        <UserRound className="size-3.5" />
                                                    ) : (
                                                        <Inbox className="size-3.5" />
                                                    )}
                                                </span>
                                                <div className="min-w-0">
                                                    <p className="truncate font-medium">
                                                        {lead.name ??
                                                            lead.email ??
                                                            t('Unknown lead')}
                                                    </p>
                                                    <p className="truncate text-xs text-muted-foreground">
                                                        {lead.email ??
                                                            t(
                                                                'No email captured',
                                                            )}
                                                    </p>
                                                </div>
                                            </div>
                                        </td>
                                        <td className="border-e border-b px-3 py-2">
                                            {lead.workspace ? (
                                                <Link
                                                    href={adminWorkspaceShow(
                                                        lead.workspace.id,
                                                    )}
                                                    className="text-sm font-medium text-foreground hover:text-primary"
                                                >
                                                    {lead.workspace.name}
                                                </Link>
                                            ) : (
                                                <span className="text-muted-foreground">
                                                    —
                                                </span>
                                            )}
                                        </td>
                                        <td className="border-e border-b px-3 py-2 text-muted-foreground">
                                            {lead.agent?.name ?? '—'}
                                        </td>
                                        <td className="border-e border-b px-3 py-2">
                                            <span className="inline-flex rounded-md border bg-muted/40 px-2 py-0.5 text-xs font-medium text-foreground capitalize">
                                                {lead.status}
                                            </span>
                                        </td>
                                        <td className="border-b px-3 py-2 text-muted-foreground">
                                            {relativeTime(lead.created_at, '—')}
                                        </td>
                                    </tr>
                                ))
                            )}
                        </tbody>
                    </table>
                </div>

                <TablePagination pagination={pagination} only={LEADS_ONLY} />
            </AdminSurface>
            <BulkActionsBar
                count={selection.size}
                onClear={selection.clear}
                noun={t('lead')}
            >
                <BulkDeleteButton
                    confirmMessage={t(
                        'Delete :count selected lead(s) across workspaces? Cannot be undone.',
                        { count: selection.size },
                    )}
                    onConfirm={() => {
                        router.post(
                            '/admin/leads/bulk-destroy',
                            { ids: Array.from(selection.selected) },
                            {
                                preserveScroll: true,
                                onSuccess: () => selection.clear(),
                            },
                        );
                    }}
                />
            </BulkActionsBar>
        </AdminLayout>
    );
}
