import { Head, router, useForm } from '@inertiajs/react';
import { Plus, RefreshCw, Trash2, X } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { useConfirm } from '@/components/confirm-dialog-provider';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useBoardLiveSync } from '@/hooks/use-board-live-sync';
import AdminLayout from '@/layouts/admin-layout';
import { useT } from '@/lib/i18n';
import type { BreadcrumbItem } from '@/types';

type Status = 'backlog' | 'doing' | 'review' | 'done';

type Task = {
    id: string;
    number: number;
    title: string;
    body: string | null;
    status: Status;
    labels: string[];
    position: number;
    created_at: string;
    updated_at: string;
    /**
     * Optional release version this card shipped in (e.g. "v1.1.0").
     * Stamped via `board:done --version=…` or `board:stamp-version`.
     */
    version?: string | null;
};

type Props = {
    tasks: Task[];
    statuses: Status[];
};

const statusTone: Record<Status, string> = {
    backlog: 'border-slate-300 bg-slate-50 dark:bg-slate-900/40',
    doing: 'border-amber-300 bg-amber-50 dark:bg-amber-900/20',
    review: 'border-sky-300 bg-sky-50 dark:bg-sky-900/20',
    done: 'border-emerald-300 bg-emerald-50 dark:bg-emerald-900/20',
};

export default function AdminBoardPage({ tasks, statuses }: Props) {
    const { t } = useT();
    const [editing, setEditing] = useState<Task | null>(null);
    const [creating, setCreating] = useState<Status | null>(null);

    const breadcrumbs: BreadcrumbItem[] = [
        { title: t('Admin'), href: '/admin' },
        { title: t('Board'), href: '/admin/board' },
    ];

    const statusLabels: Record<Status, string> = {
        backlog: t('Backlog'),
        doing: t('In progress'),
        review: t('Review'),
        done: t('Done'),
    };

    // Real-time updates via Inertia partial reload polling. The hook
    // pauses while a dialog is open so the form data the user is
    // filling in isn't replaced under their cursor by an incoming poll.
    const { lastSyncedAt, isSyncing } = useBoardLiveSync({
        enabled: editing === null && creating === null,
    });

    const grouped = useMemo(() => {
        const map: Record<Status, Task[]> = {
            backlog: [],
            doing: [],
            review: [],
            done: [],
        };

        for (const task of tasks) {
            map[task.status]?.push(task);
        }

        return map;
    }, [tasks]);

    return (
        <AdminLayout breadcrumbs={breadcrumbs}>
            <Head title={t('Board · Admin')} />

            <div className="flex flex-1 flex-col gap-4 p-4">
                <header className="flex items-start justify-between gap-3">
                    <div>
                        <h1 className="text-2xl font-semibold tracking-tight">
                            {t('Internal board')}
                        </h1>
                        <p className="mt-1 max-w-2xl text-sm text-muted-foreground">
                            {t(
                                'Backlog · Doing · Review · Done. Stored as a JSON file at storage/app/private/kanban-tasks.json — survives migrate:fresh. Super-admins only.',
                            )}
                        </p>
                    </div>
                    <LiveIndicator
                        lastSyncedAt={lastSyncedAt}
                        isSyncing={isSyncing}
                        paused={editing !== null || creating !== null}
                    />
                </header>

                <div className="grid flex-1 gap-3 md:grid-cols-2 xl:grid-cols-4">
                    {statuses.map((status) => (
                        <div
                            key={status}
                            className={
                                'flex h-full flex-col rounded-lg border ' +
                                statusTone[status]
                            }
                        >
                            <div className="flex items-center justify-between border-b border-current/10 px-3 py-2">
                                <div className="flex items-center gap-2">
                                    <span className="text-sm font-semibold tracking-wide uppercase">
                                        {statusLabels[status]}
                                    </span>
                                    <span className="rounded-full bg-background/60 px-2 py-0.5 text-xs font-medium text-muted-foreground">
                                        {grouped[status]?.length ?? 0}
                                    </span>
                                </div>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    onClick={() => setCreating(status)}
                                    aria-label={t('Add task to :status', {
                                        status: statusLabels[status],
                                    })}
                                >
                                    <Plus className="size-4" />
                                </Button>
                            </div>

                            <div className="flex flex-1 flex-col gap-2 p-2">
                                {(grouped[status] ?? []).length === 0 && (
                                    <p className="px-2 py-6 text-center text-xs text-muted-foreground">
                                        {t('Nothing here yet.')}
                                    </p>
                                )}
                                {(grouped[status] ?? []).map((task) => (
                                    <Card
                                        key={task.id}
                                        className="cursor-pointer p-0 transition hover:shadow-md"
                                        onClick={() => setEditing(task)}
                                    >
                                        <CardContent className="p-3">
                                            <div className="flex items-start gap-2">
                                                <span className="rounded bg-background/70 px-1.5 py-0.5 font-mono text-[10px] font-semibold tracking-tight text-muted-foreground">
                                                    #{task.number}
                                                </span>
                                                <p className="text-sm leading-snug font-medium">
                                                    {task.title}
                                                </p>
                                                {task.version && (
                                                    <span
                                                        className="ms-auto shrink-0 rounded-full border border-emerald-200 bg-emerald-50 px-2 py-0.5 font-mono text-[10px] font-semibold text-emerald-700 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-300"
                                                        title={t(
                                                            'Shipped in :version',
                                                            {
                                                                version:
                                                                    task.version,
                                                            },
                                                        )}
                                                    >
                                                        {task.version}
                                                    </span>
                                                )}
                                            </div>
                                            {task.body && (
                                                <p className="mt-1 line-clamp-2 text-xs text-muted-foreground">
                                                    {task.body}
                                                </p>
                                            )}
                                            {task.labels.length > 0 && (
                                                <div className="mt-2 flex flex-wrap gap-1">
                                                    {task.labels.map(
                                                        (label) => (
                                                            <span
                                                                key={label}
                                                                className="rounded-full border bg-background/70 px-2 py-0.5 text-[10px] font-medium tracking-wide text-muted-foreground uppercase"
                                                            >
                                                                {label}
                                                            </span>
                                                        ),
                                                    )}
                                                </div>
                                            )}
                                        </CardContent>
                                    </Card>
                                ))}
                            </div>
                        </div>
                    ))}
                </div>
            </div>

            {creating !== null && (
                <CreateTaskDialog
                    initialStatus={creating}
                    statuses={statuses}
                    statusLabels={statusLabels}
                    onClose={() => setCreating(null)}
                />
            )}

            {editing !== null && (
                <EditTaskDialog
                    task={editing}
                    statuses={statuses}
                    statusLabels={statusLabels}
                    onClose={() => setEditing(null)}
                />
            )}
        </AdminLayout>
    );
}

/**
 * Tiny "Live · last synced Xs ago" pill in the header. Watches a 1s
 * tick so the relative-time string stays accurate without re-rendering
 * the whole board. The interval lives in the same component as the
 * label it drives, so unmounting the indicator cleans up the timer.
 */
function LiveIndicator({
    lastSyncedAt,
    isSyncing,
    paused,
}: {
    lastSyncedAt: Date | null;
    isSyncing: boolean;
    paused: boolean;
}) {
    const { t } = useT();
    const [, forceTick] = useState(0);
    useEffect(() => {
        const id = window.setInterval(() => forceTick((n) => n + 1), 1000);

        return () => window.clearInterval(id);
    }, []);

    const label = (() => {
        if (paused) {
            return t('Paused');
        }

        if (isSyncing) {
            return t('Syncing…');
        }

        if (!lastSyncedAt) {
            return t('Live');
        }

        // eslint-disable-next-line react-hooks/purity -- relative-time label intentionally reads the clock at render time
        const now = Date.now();
        const seconds = Math.floor((now - lastSyncedAt.getTime()) / 1000);

        if (seconds < 5) {
            return t('Live · just now');
        }

        if (seconds < 60) {
            return t('Live · :seconds s ago', { seconds });
        }

        const minutes = Math.floor(seconds / 60);

        return t('Live · :minutes m ago', { minutes });
    })();

    const tone = paused
        ? 'border-slate-300 bg-slate-50 text-slate-600'
        : isSyncing
          ? 'border-amber-300 bg-amber-50 text-amber-700'
          : 'border-emerald-300 bg-emerald-50 text-emerald-700';

    return (
        <span
            className={`inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-[11px] font-medium ${tone}`}
            title={
                lastSyncedAt
                    ? t('Last synced :time', {
                          time: lastSyncedAt.toLocaleTimeString(),
                      })
                    : t('Polling every 8s, paused while a dialog is open')
            }
        >
            <RefreshCw
                className={`size-3 ${isSyncing ? 'animate-spin' : ''}`}
            />
            {label}
        </span>
    );
}

function CreateTaskDialog({
    initialStatus,
    statuses,
    statusLabels,
    onClose,
}: {
    initialStatus: Status;
    statuses: Status[];
    statusLabels: Record<Status, string>;
    onClose: () => void;
}) {
    const { t } = useT();
    const form = useForm({
        title: '',
        body: '',
        status: initialStatus as Status,
        labels: '',
    });

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        form.transform((data) => ({
            ...data,
            labels: (data.labels as string)
                .split(',')
                .map((s) => s.trim())
                .filter(Boolean),
        }));
        form.post('/admin/board', {
            preserveScroll: true,
            onSuccess: () => onClose(),
        });
    };

    return (
        <Dialog open onOpenChange={(open) => (!open ? onClose() : undefined)}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>{t('New task')}</DialogTitle>
                    <DialogDescription>
                        {t(
                            'Cards persist on the AppSetting singleton. Pick a status; you can move it later from the card detail dialog.',
                        )}
                    </DialogDescription>
                </DialogHeader>

                <form onSubmit={submit} className="grid gap-3">
                    <div className="grid gap-1.5">
                        <Label htmlFor="kb-title">{t('Title')}</Label>
                        <Input
                            id="kb-title"
                            value={form.data.title}
                            onChange={(e) =>
                                form.setData('title', e.target.value)
                            }
                            placeholder={t('Add Paddle gateway')}
                            required
                            autoFocus
                        />
                        {form.errors.title && (
                            <p className="text-xs text-destructive">
                                {form.errors.title}
                            </p>
                        )}
                    </div>

                    <div className="grid gap-1.5">
                        <Label htmlFor="kb-body">{t('Notes (optional)')}</Label>
                        <textarea
                            id="kb-body"
                            value={form.data.body}
                            onChange={(e) =>
                                form.setData('body', e.target.value)
                            }
                            rows={4}
                            className="rounded-md border bg-background p-2 text-sm"
                            placeholder={t(
                                'Why this matters, links, design constraints…',
                            )}
                        />
                    </div>

                    <div className="grid gap-1.5 sm:grid-cols-2">
                        <div className="grid gap-1.5">
                            <Label htmlFor="kb-status">{t('Column')}</Label>
                            <select
                                id="kb-status"
                                value={form.data.status}
                                onChange={(e) =>
                                    form.setData(
                                        'status',
                                        e.target.value as Status,
                                    )
                                }
                                className="h-9 rounded-md border bg-background px-3 text-sm"
                            >
                                {statuses.map((s) => (
                                    <option key={s} value={s}>
                                        {statusLabels[s]}
                                    </option>
                                ))}
                            </select>
                        </div>

                        <div className="grid gap-1.5">
                            <Label htmlFor="kb-labels">{t('Labels')}</Label>
                            <Input
                                id="kb-labels"
                                value={form.data.labels}
                                onChange={(e) =>
                                    form.setData('labels', e.target.value)
                                }
                                placeholder={t('bug, frontend')}
                            />
                            <p className="text-[11px] text-muted-foreground">
                                {t('Comma-separated. Up to 8.')}
                            </p>
                        </div>
                    </div>

                    <DialogFooter className="mt-2">
                        <Button type="button" variant="ghost" onClick={onClose}>
                            {t('Cancel')}
                        </Button>
                        <Button type="submit" disabled={form.processing}>
                            {form.processing ? t('Saving…') : t('Add task')}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

function EditTaskDialog({
    task,
    statuses,
    statusLabels,
    onClose,
}: {
    task: Task;
    statuses: Status[];
    statusLabels: Record<Status, string>;
    onClose: () => void;
}) {
    const { t } = useT();
    const confirm = useConfirm();
    const form = useForm({
        title: task.title,
        body: task.body ?? '',
        status: task.status,
        labels: task.labels.join(', '),
    });

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        form.transform((data) => ({
            ...data,
            labels: (data.labels as string)
                .split(',')
                .map((s) => s.trim())
                .filter(Boolean),
        }));
        form.patch(`/admin/board/${task.id}`, {
            preserveScroll: true,
            onSuccess: () => onClose(),
        });
    };

    const archive = async () => {
        const ok = await confirm({
            title: t('Archive task?'),
            message: t('Archive ":title"? This cannot be undone.', {
                title: task.title,
            }),
            confirmLabel: t('Archive'),
            danger: true,
        });

        if (!ok) {
            return;
        }

        router.delete(`/admin/board/${task.id}`, {
            preserveScroll: true,
            onSuccess: () => onClose(),
        });
    };

    return (
        <Dialog open onOpenChange={(open) => (!open ? onClose() : undefined)}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>
                        <span className="me-2 rounded bg-muted px-2 py-0.5 font-mono text-xs font-semibold text-muted-foreground">
                            #{task.number}
                        </span>
                        {t('Edit task')}
                    </DialogTitle>
                    <DialogDescription>
                        {t(
                            'Move between columns by changing the status dropdown.',
                        )}
                    </DialogDescription>
                </DialogHeader>

                <form onSubmit={submit} className="grid gap-3">
                    <div className="grid gap-1.5">
                        <Label htmlFor="kb-edit-title">{t('Title')}</Label>
                        <Input
                            id="kb-edit-title"
                            value={form.data.title}
                            onChange={(e) =>
                                form.setData('title', e.target.value)
                            }
                            required
                        />
                    </div>

                    <div className="grid gap-1.5">
                        <Label htmlFor="kb-edit-body">{t('Notes')}</Label>
                        <textarea
                            id="kb-edit-body"
                            value={form.data.body}
                            onChange={(e) =>
                                form.setData('body', e.target.value)
                            }
                            rows={5}
                            className="rounded-md border bg-background p-2 text-sm"
                        />
                    </div>

                    <div className="grid gap-1.5 sm:grid-cols-2">
                        <div className="grid gap-1.5">
                            <Label htmlFor="kb-edit-status">
                                {t('Column')}
                            </Label>
                            <select
                                id="kb-edit-status"
                                value={form.data.status}
                                onChange={(e) =>
                                    form.setData(
                                        'status',
                                        e.target.value as Status,
                                    )
                                }
                                className="h-9 rounded-md border bg-background px-3 text-sm"
                            >
                                {statuses.map((s) => (
                                    <option key={s} value={s}>
                                        {statusLabels[s]}
                                    </option>
                                ))}
                            </select>
                        </div>

                        <div className="grid gap-1.5">
                            <Label htmlFor="kb-edit-labels">
                                {t('Labels')}
                            </Label>
                            <Input
                                id="kb-edit-labels"
                                value={form.data.labels}
                                onChange={(e) =>
                                    form.setData('labels', e.target.value)
                                }
                            />
                        </div>
                    </div>

                    <DialogFooter className="mt-2 gap-2 sm:justify-between">
                        <Button
                            type="button"
                            variant="ghost"
                            onClick={archive}
                            className="text-destructive"
                        >
                            <Trash2 className="me-1 size-4" /> {t('Archive')}
                        </Button>
                        <div className="flex gap-2">
                            <Button
                                type="button"
                                variant="ghost"
                                onClick={onClose}
                            >
                                <X className="me-1 size-4" /> {t('Cancel')}
                            </Button>
                            <Button type="submit" disabled={form.processing}>
                                {form.processing ? t('Saving…') : t('Save')}
                            </Button>
                        </div>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
