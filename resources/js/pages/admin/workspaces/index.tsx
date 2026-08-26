import { Head, Link, router } from '@inertiajs/react';
import { BriefcaseBusiness } from 'lucide-react';
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
import {
    index as adminWorkspacesIndex,
    show as adminWorkspaceShow,
} from '@/routes/admin/workspaces';

type Workspace = {
    id: string;
    name: string;
    slug: string;
    plan: { name: string; slug: string } | null;
    owner: { id: number; name: string; email: string } | null;
    agents_count: number;
    members_count: number;
    created_at: string | null;
};

type Props = {
    workspaces: Workspace[];
    pagination: PaginationMeta;
    filters: { q: string };
};

const WORKSPACES_ONLY = ['workspaces', 'pagination', 'filters'];

export default function AdminWorkspaces({
    workspaces,
    pagination,
    filters,
}: Props) {
    const { t } = useT();
    const selection = useBulkSelection();
    const pageIds = workspaces.map((w) => w.id);

    return (
        <AdminLayout
            breadcrumbs={[
                { title: t('Dashboard'), href: adminDashboard() },
                { title: t('Workspaces'), href: adminWorkspacesIndex() },
            ]}
        >
            <Head title={t('Workspaces · Admin')} />
            <AdminSurface>
                <AdminSurfaceBar>
                    <span className="inline-flex h-7 items-center rounded-md border bg-card px-2.5 text-xs font-normal text-foreground">
                        {t('All workspaces')}
                    </span>
                    <span className="inline-flex h-7 items-center rounded-md border bg-card px-2.5 text-xs font-normal text-muted-foreground">
                        {t(':count workspaces', {
                            count: pagination.total.toLocaleString(),
                        })}
                    </span>
                    <div className="ms-auto w-full sm:w-auto">
                        <TableSearch
                            placeholder={t('Search by name, slug, or owner...')}
                            initialValue={filters.q}
                            only={WORKSPACES_ONLY}
                        />
                    </div>
                </AdminSurfaceBar>

                <div className="min-h-0 flex-1 overflow-auto">
                    <table className="w-full min-w-[1080px] border-separate border-spacing-0 text-start text-sm">
                        <thead className="sticky top-0 z-10 bg-card text-xs font-medium text-muted-foreground">
                            <tr>
                                <th className="w-9 border-b px-3 py-2">
                                    <input
                                        type="checkbox"
                                        aria-label={t('Select all workspaces')}
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
                                    {t('Workspace')}
                                </th>
                                <th className="border-e border-b px-3 py-2">
                                    {t('Owner')}
                                </th>
                                <th className="border-e border-b px-3 py-2">
                                    {t('Plan')}
                                </th>
                                <th className="border-e border-b px-3 py-2">
                                    {t('Footprint')}
                                </th>
                                <th className="border-b px-3 py-2">
                                    {t('Created')}
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            {workspaces.length === 0 ? (
                                <tr>
                                    <td
                                        colSpan={6}
                                        className="h-72 border-b px-4 text-center"
                                    >
                                        <div className="mx-auto flex max-w-sm flex-col items-center gap-2 text-muted-foreground">
                                            <BriefcaseBusiness className="size-8" />
                                            <p className="text-sm font-medium text-foreground">
                                                {t('No matching workspaces')}
                                            </p>
                                            <p className="text-xs">
                                                {t(
                                                    'No workspaces match the current admin search.',
                                                )}
                                            </p>
                                        </div>
                                    </td>
                                </tr>
                            ) : (
                                workspaces.map((workspace) => (
                                    <tr
                                        key={workspace.id}
                                        className="group hover:bg-muted/35"
                                    >
                                        <td className="border-b px-3 py-2">
                                            <input
                                                type="checkbox"
                                                aria-label={t('Select :name', {
                                                    name: workspace.name,
                                                })}
                                                checked={selection.isSelected(
                                                    workspace.id,
                                                )}
                                                onChange={(e) => {
                                                    const nativeEvent = (
                                                        e as unknown as {
                                                            nativeEvent: MouseEvent;
                                                        }
                                                    ).nativeEvent;
                                                    selection.toggle(
                                                        workspace.id,
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
                                        <td className="border-e border-b px-3 py-2">
                                            <div className="flex min-w-0 items-center gap-2 text-foreground">
                                                <span className="flex size-5 shrink-0 items-center justify-center rounded bg-muted text-muted-foreground">
                                                    <BriefcaseBusiness className="size-3.5" />
                                                </span>
                                                <div className="min-w-0">
                                                    <Link
                                                        href={adminWorkspaceShow(
                                                            workspace.id,
                                                        )}
                                                        className="block truncate font-medium text-foreground hover:text-primary"
                                                    >
                                                        {workspace.name}
                                                    </Link>
                                                    <p className="truncate text-xs text-muted-foreground">
                                                        {workspace.slug}
                                                    </p>
                                                </div>
                                            </div>
                                        </td>
                                        <td className="border-e border-b px-3 py-2">
                                            {workspace.owner ? (
                                                <div className="min-w-0">
                                                    <p className="truncate font-medium text-foreground">
                                                        {workspace.owner.name}
                                                    </p>
                                                    <p className="truncate text-xs text-muted-foreground">
                                                        {workspace.owner.email}
                                                    </p>
                                                </div>
                                            ) : (
                                                <span className="text-muted-foreground">
                                                    —
                                                </span>
                                            )}
                                        </td>
                                        <td className="border-e border-b px-3 py-2 text-muted-foreground">
                                            {workspace.plan?.name ??
                                                t('No plan')}
                                        </td>
                                        <td className="border-e border-b px-3 py-2 text-muted-foreground">
                                            <p>
                                                {workspace.agents_count === 1
                                                    ? t(':count agent', {
                                                          count: workspace.agents_count.toLocaleString(),
                                                      })
                                                    : t(':count agents', {
                                                          count: workspace.agents_count.toLocaleString(),
                                                      })}
                                            </p>
                                            <p className="text-xs">
                                                {workspace.members_count === 1
                                                    ? t(':count member', {
                                                          count: workspace.members_count.toLocaleString(),
                                                      })
                                                    : t(':count members', {
                                                          count: workspace.members_count.toLocaleString(),
                                                      })}
                                            </p>
                                        </td>
                                        <td className="border-b px-3 py-2 text-muted-foreground">
                                            {relativeTime(
                                                workspace.created_at,
                                                '—',
                                            )}
                                        </td>
                                    </tr>
                                ))
                            )}
                        </tbody>
                    </table>
                </div>

                <TablePagination
                    pagination={pagination}
                    only={WORKSPACES_ONLY}
                />
            </AdminSurface>
            <BulkActionsBar
                count={selection.size}
                onClear={selection.clear}
                noun={t('workspace')}
            >
                <BulkDeleteButton
                    confirmMessage={t(
                        'Soft-delete :count selected workspace(s)? Their agents, conversations, and leads are preserved but hidden from normal queries. Cannot delete the workspace you are currently signed into.',
                        { count: selection.size },
                    )}
                    onConfirm={() => {
                        router.post(
                            '/admin/workspaces/bulk-destroy',
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
