import { Head, Link, router, useForm } from '@inertiajs/react';
import {
    Bot,
    CheckCircle2,
    Library,
    MessageSquare,
    Pencil,
    Trash2,
} from 'lucide-react';
import { useState } from 'react';
import { AdminSurface, AdminSurfaceBar } from '@/components/admin-surface';
import {
    BulkActionsBar,
    BulkDeleteButton,
} from '@/components/bulk-actions-bar';
import { useConfirm } from '@/components/confirm-dialog-provider';
import { TablePagination } from '@/components/table-pagination';
import type { PaginationMeta } from '@/components/table-pagination';
import { TableSearch } from '@/components/table-search';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useBulkSelection } from '@/hooks/use-bulk-selection';
import AdminLayout from '@/layouts/admin-layout';
import { useT } from '@/lib/i18n';
import { relativeTime } from '@/lib/relative-time';
import { dashboard as adminDashboard } from '@/routes/admin';
import { index as adminAgentsIndex } from '@/routes/admin/agents';
import { show as adminWorkspaceShow } from '@/routes/admin/workspaces';

type AgentRow = {
    id: string;
    name: string;
    is_published: boolean;
    language_default: string;
    workspace: { id: string; name: string } | null;
    sources_count: number;
    conversations_count: number;
    updated_at: string | null;
};

type Props = {
    agents: AgentRow[];
    pagination: PaginationMeta;
    filters: { q: string };
};

const AGENTS_ONLY = ['agents', 'pagination', 'filters'];

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

export default function AdminAgents({ agents, pagination, filters }: Props) {
    const { t } = useT();
    const [editing, setEditing] = useState<AgentRow | null>(null);
    const confirm = useConfirm();
    const selection = useBulkSelection();
    const pageIds = agents.map((a) => a.id);

    const onDelete = async (agent: AgentRow) => {
        const ok = await confirm({
            title: t('Delete agent?'),
            message: t(
                'Permanently delete ":name" from :workspace? This removes the agent, its knowledge sources, conversations, and captured leads. Cannot be undone.',
                {
                    name: agent.name,
                    workspace: agent.workspace?.name ?? t('its workspace'),
                },
            ),
            confirmLabel: t('Delete'),
            danger: true,
        });

        if (!ok) {
            return;
        }

        router.delete(`/admin/agents/${agent.id}`, {
            preserveScroll: true,
        });
    };

    return (
        <AdminLayout
            breadcrumbs={[
                { title: t('Dashboard'), href: adminDashboard() },
                { title: t('Agents'), href: adminAgentsIndex() },
            ]}
        >
            <Head title={t('Agents · Admin')} />
            <AdminSurface>
                <AdminSurfaceBar>
                    <span className="inline-flex h-7 items-center rounded-md border bg-card px-2.5 text-xs font-normal text-foreground">
                        {t('All agents')}
                    </span>
                    <span className="inline-flex h-7 items-center rounded-md border bg-card px-2.5 text-xs font-normal text-muted-foreground">
                        {t(':count agents', {
                            count: pagination.total.toLocaleString(),
                        })}
                    </span>
                    <div className="ms-auto w-full sm:w-auto">
                        <TableSearch
                            placeholder={t(
                                'Search by agent or workspace name...',
                            )}
                            initialValue={filters.q}
                            only={AGENTS_ONLY}
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
                                <th className="border-e border-b px-3 py-2">
                                    {t('Workspace')}
                                </th>
                                <th className="border-e border-b px-3 py-2">
                                    {t('Status')}
                                </th>
                                <th className="border-e border-b px-3 py-2">
                                    {t('Reach')}
                                </th>
                                <th className="border-e border-b px-3 py-2">
                                    {t('Updated')}
                                </th>
                                <th className="border-b px-3 py-2 text-end">
                                    {t('Actions')}
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            {agents.length === 0 ? (
                                <tr>
                                    <td
                                        colSpan={7}
                                        className="h-72 border-b px-4 text-center"
                                    >
                                        <div className="mx-auto flex max-w-sm flex-col items-center gap-2 text-muted-foreground">
                                            <Bot className="size-8" />
                                            <p className="text-sm font-medium text-foreground">
                                                {t('No matching agents')}
                                            </p>
                                            <p className="text-xs">
                                                {t(
                                                    'No agents match the current admin search.',
                                                )}
                                            </p>
                                        </div>
                                    </td>
                                </tr>
                            ) : (
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
                                            <div className="flex min-w-0 items-center gap-2 text-foreground">
                                                <span className="flex size-5 shrink-0 items-center justify-center rounded bg-muted text-muted-foreground">
                                                    <Bot className="size-3.5" />
                                                </span>
                                                <div className="min-w-0">
                                                    <p className="truncate font-medium">
                                                        {agent.name}
                                                    </p>
                                                    <p className="truncate text-xs text-muted-foreground uppercase">
                                                        {agent.language_default}
                                                    </p>
                                                </div>
                                            </div>
                                        </td>
                                        <td className="border-e border-b px-3 py-2">
                                            {agent.workspace ? (
                                                <Link
                                                    href={adminWorkspaceShow(
                                                        agent.workspace.id,
                                                    )}
                                                    className="truncate font-medium text-foreground hover:text-primary"
                                                >
                                                    {agent.workspace.name}
                                                </Link>
                                            ) : (
                                                <span className="text-muted-foreground">
                                                    —
                                                </span>
                                            )}
                                        </td>
                                        <td className="border-e border-b px-3 py-2">
                                            <PublishBadge
                                                published={agent.is_published}
                                            />
                                        </td>
                                        <td className="border-e border-b px-3 py-2 text-muted-foreground">
                                            <div className="space-y-1">
                                                <p className="inline-flex items-center gap-1.5">
                                                    <Library className="size-3.5" />
                                                    {agent.sources_count === 1
                                                        ? t(':count source', {
                                                              count: agent.sources_count.toLocaleString(),
                                                          })
                                                        : t(':count sources', {
                                                              count: agent.sources_count.toLocaleString(),
                                                          })}
                                                </p>
                                                <p className="inline-flex items-center gap-1.5 text-xs">
                                                    <MessageSquare className="size-3.5" />
                                                    {agent.conversations_count ===
                                                    1
                                                        ? t(
                                                              ':count conversation',
                                                              {
                                                                  count: agent.conversations_count.toLocaleString(),
                                                              },
                                                          )
                                                        : t(
                                                              ':count conversations',
                                                              {
                                                                  count: agent.conversations_count.toLocaleString(),
                                                              },
                                                          )}
                                                </p>
                                            </div>
                                        </td>
                                        <td className="border-e border-b px-3 py-2 text-muted-foreground">
                                            {relativeTime(
                                                agent.updated_at,
                                                '—',
                                            )}
                                        </td>
                                        <td className="border-b px-3 py-2 text-end">
                                            <div className="inline-flex items-center gap-1">
                                                <Button
                                                    size="sm"
                                                    variant="ghost"
                                                    onClick={() =>
                                                        setEditing(agent)
                                                    }
                                                    aria-label={t('Edit agent')}
                                                >
                                                    <Pencil className="size-3.5" />
                                                    <span className="ms-1">
                                                        {t('Edit')}
                                                    </span>
                                                </Button>
                                                <Button
                                                    size="sm"
                                                    variant="ghost"
                                                    onClick={() =>
                                                        onDelete(agent)
                                                    }
                                                    aria-label={t(
                                                        'Delete agent',
                                                    )}
                                                    className="text-destructive hover:bg-destructive/10 hover:text-destructive"
                                                >
                                                    <Trash2 className="size-3.5" />
                                                </Button>
                                            </div>
                                        </td>
                                    </tr>
                                ))
                            )}
                        </tbody>
                    </table>
                </div>

                <TablePagination pagination={pagination} only={AGENTS_ONLY} />
            </AdminSurface>
            {editing !== null && (
                <EditAgentDialog
                    agent={editing}
                    onClose={() => setEditing(null)}
                />
            )}
            <BulkActionsBar
                count={selection.size}
                onClear={selection.clear}
                noun={t('agent')}
            >
                <BulkDeleteButton
                    confirmMessage={t(
                        'Delete :count selected agent(s) across workspaces? This removes their sources, conversations, leads, and embedded widget.',
                        { count: selection.size },
                    )}
                    onConfirm={() => {
                        router.post(
                            '/admin/agents/bulk-destroy',
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

function EditAgentDialog({
    agent,
    onClose,
}: {
    agent: AgentRow;
    onClose: () => void;
}) {
    const { t } = useT();
    const form = useForm({
        name: agent.name,
        is_published: agent.is_published,
    });

    return (
        <Dialog
            open={true}
            onOpenChange={(open) => {
                if (!open) {
                    onClose();
                }
            }}
        >
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>{t('Edit agent')}</DialogTitle>
                </DialogHeader>
                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.patch(`/admin/agents/${agent.id}`, {
                            preserveScroll: true,
                            onSuccess: onClose,
                        });
                    }}
                    className="grid gap-3"
                >
                    <div className="grid gap-1.5">
                        <Label htmlFor="agent-edit-name">{t('Name')}</Label>
                        <Input
                            id="agent-edit-name"
                            value={form.data.name}
                            onChange={(e) =>
                                form.setData('name', e.target.value)
                            }
                            required
                        />
                        {form.errors.name && (
                            <p className="text-xs text-destructive">
                                {form.errors.name}
                            </p>
                        )}
                    </div>
                    <Label className="flex items-center gap-2 text-sm">
                        <input
                            type="checkbox"
                            checked={form.data.is_published}
                            onChange={(e) =>
                                form.setData('is_published', e.target.checked)
                            }
                            className="size-4"
                        />
                        <span>{t('Published (visitor-facing)')}</span>
                    </Label>
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={onClose}
                        >
                            {t('Cancel')}
                        </Button>
                        <Button type="submit" disabled={form.processing}>
                            {form.processing ? t('Saving…') : t('Save')}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
