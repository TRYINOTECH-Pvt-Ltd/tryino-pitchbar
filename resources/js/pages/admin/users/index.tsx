import { Head, router, useForm } from '@inertiajs/react';
import { Pencil, Shield, Trash2, UserRound } from 'lucide-react';
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
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { useBulkSelection } from '@/hooks/use-bulk-selection';
import AdminLayout from '@/layouts/admin-layout';
import { useT } from '@/lib/i18n';
import { relativeTime } from '@/lib/relative-time';
import { dashboard as adminDashboard } from '@/routes/admin';
import { start as adminImpersonateStart } from '@/routes/admin/impersonate';
import {
    destroy as adminUsersDestroy,
    index as adminUsersIndex,
    updateByok as adminUsersUpdateByok,
    updateRole as adminUsersUpdateRole,
} from '@/routes/admin/users';

type ByokMode = 'inherit' | 'enabled' | 'disabled';

type UserRow = {
    id: number;
    name: string;
    email: string;
    role: 'customer' | 'super_admin';
    workspaces_count: number;
    owned_count: number;
    created_at: string | null;
    byok_mode: ByokMode;
};

type Props = {
    users: UserRow[];
    pagination: PaginationMeta;
    filters: { q: string };
    byok_globally_enabled: boolean;
    current_user_id: number | null;
};

const USERS_ONLY = ['users', 'pagination', 'filters'];

export default function AdminUsers({
    users,
    pagination,
    filters,
    byok_globally_enabled,
    current_user_id,
}: Props) {
    const { t } = useT();
    const confirm = useConfirm();
    const [editing, setEditing] = useState<UserRow | null>(null);
    const selection = useBulkSelection();
    const pageIds = users.map((u) => String(u.id));

    const promote = (id: number, to: 'customer' | 'super_admin') => {
        router.patch(
            adminUsersUpdateRole.url(id),
            { role: to },
            { preserveScroll: true },
        );
    };

    const setByok = (id: number, mode: ByokMode) => {
        router.patch(
            adminUsersUpdateByok.url(id),
            { mode },
            { preserveScroll: true },
        );
    };

    const destroy = async (user: UserRow) => {
        const ok = await confirm({
            title: t('Delete user?'),
            message:
                user.owned_count > 0
                    ? t(
                          ':email owns :count workspace(s). Delete will fail unless ownership is transferred first. Continue anyway?',
                          {
                              email: user.email,
                              count: user.owned_count,
                          },
                      )
                    : t(
                          'Delete :email? They lose access to every workspace. Soft-delete preserves the row for audit.',
                          { email: user.email },
                      ),
            confirmLabel: t('Delete'),
            danger: true,
        });

        if (!ok) {
            return;
        }

        router.delete(adminUsersDestroy.url(user.id), {
            preserveScroll: true,
        });
    };

    return (
        <AdminLayout
            breadcrumbs={[
                { title: t('Dashboard'), href: adminDashboard() },
                { title: t('Users'), href: adminUsersIndex() },
            ]}
        >
            <Head title={t('Users · Admin')} />
            <AdminSurface>
                <AdminSurfaceBar>
                    <span className="inline-flex h-7 items-center rounded-md border bg-card px-2.5 text-xs font-normal text-foreground">
                        {t('All users')}
                    </span>
                    <span className="inline-flex h-7 items-center rounded-md border bg-card px-2.5 text-xs font-normal text-muted-foreground">
                        {t(':count users', {
                            count: pagination.total.toLocaleString(),
                        })}
                    </span>
                    <span
                        className={`inline-flex h-7 items-center rounded-md border px-2.5 text-xs font-normal ${
                            byok_globally_enabled
                                ? 'border-amber-200 bg-amber-50 text-amber-800 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-200'
                                : 'border-border bg-card text-muted-foreground'
                        }`}
                        title={t(
                            'When BYOK is on globally, every workspace must supply its own AI keys. Per-user overrides below still apply.',
                        )}
                    >
                        {t('BYOK global: :state', {
                            state: byok_globally_enabled ? t('on') : t('off'),
                        })}
                    </span>
                    <div className="ms-auto w-full sm:w-auto">
                        <TableSearch
                            placeholder={t('Search by name or email...')}
                            initialValue={filters.q}
                            only={USERS_ONLY}
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
                                        aria-label={t('Select all users')}
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
                                    {t('User')}
                                </th>
                                <th className="border-e border-b px-3 py-2">
                                    {t('Role')}
                                </th>
                                <th className="border-e border-b px-3 py-2">
                                    {t('Workspace access')}
                                </th>
                                <th className="border-e border-b px-3 py-2">
                                    {t('Joined')}
                                </th>
                                <th className="border-e border-b px-3 py-2">
                                    {t('BYOK')}
                                </th>
                                <th className="border-b px-3 py-2 text-end">
                                    {t('Actions')}
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            {users.length === 0 ? (
                                <tr>
                                    <td
                                        colSpan={7}
                                        className="h-72 border-b px-4 text-center"
                                    >
                                        <div className="mx-auto flex max-w-sm flex-col items-center gap-2 text-muted-foreground">
                                            <UserRound className="size-8" />
                                            <p className="text-sm font-medium text-foreground">
                                                {t('No matching users')}
                                            </p>
                                            <p className="text-xs">
                                                {t(
                                                    'No users match the current admin search.',
                                                )}
                                            </p>
                                        </div>
                                    </td>
                                </tr>
                            ) : (
                                users.map((user) => (
                                    <tr
                                        key={user.id}
                                        className="group hover:bg-muted/35"
                                    >
                                        <td className="border-b px-3 py-2">
                                            <input
                                                type="checkbox"
                                                aria-label={t('Select :email', {
                                                    email: user.email,
                                                })}
                                                checked={selection.isSelected(
                                                    String(user.id),
                                                )}
                                                onChange={(e) => {
                                                    const nativeEvent = (
                                                        e as unknown as {
                                                            nativeEvent: MouseEvent;
                                                        }
                                                    ).nativeEvent;
                                                    selection.toggle(
                                                        String(user.id),
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
                                                    <UserRound className="size-3.5" />
                                                </span>
                                                <div className="min-w-0">
                                                    <p className="truncate font-medium">
                                                        {user.name}
                                                    </p>
                                                    <p className="truncate text-xs text-muted-foreground">
                                                        {user.email}
                                                    </p>
                                                </div>
                                            </div>
                                        </td>
                                        <td className="border-e border-b px-3 py-2">
                                            <span
                                                className={`inline-flex items-center gap-1 rounded-md border px-2 py-0.5 text-xs font-medium ${
                                                    user.role === 'super_admin'
                                                        ? 'border-rose-200 bg-rose-50 text-rose-700 dark:border-rose-500/30 dark:bg-rose-500/10 dark:text-rose-300'
                                                        : 'border-border bg-muted/40 text-muted-foreground'
                                                }`}
                                            >
                                                {user.role === 'super_admin' ? (
                                                    <Shield className="size-3.5" />
                                                ) : null}
                                                {user.role === 'super_admin'
                                                    ? t('super admin')
                                                    : t('customer')}
                                            </span>
                                        </td>
                                        <td className="border-e border-b px-3 py-2 text-muted-foreground">
                                            <p>
                                                {user.workspaces_count === 1
                                                    ? t(
                                                          ':count total workspace',
                                                          {
                                                              count: user.workspaces_count.toLocaleString(),
                                                          },
                                                      )
                                                    : t(
                                                          ':count total workspaces',
                                                          {
                                                              count: user.workspaces_count.toLocaleString(),
                                                          },
                                                      )}
                                            </p>
                                            <p className="text-xs">
                                                {t(':count owned', {
                                                    count: user.owned_count.toLocaleString(),
                                                })}
                                            </p>
                                        </td>
                                        <td className="border-e border-b px-3 py-2 text-muted-foreground">
                                            {relativeTime(user.created_at, '—')}
                                        </td>
                                        <td className="border-e border-b px-3 py-2">
                                            <Select
                                                value={user.byok_mode}
                                                onValueChange={(v) =>
                                                    setByok(
                                                        user.id,
                                                        v as ByokMode,
                                                    )
                                                }
                                            >
                                                <SelectTrigger
                                                    aria-label={t(
                                                        'BYOK mode for :email',
                                                        { email: user.email },
                                                    )}
                                                    className="h-7 w-[148px] text-xs"
                                                >
                                                    <SelectValue />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    <SelectItem value="inherit">
                                                        {t('Inherit global')}
                                                    </SelectItem>
                                                    <SelectItem value="enabled">
                                                        {t('Force on')}
                                                    </SelectItem>
                                                    <SelectItem value="disabled">
                                                        {t('Force off')}
                                                    </SelectItem>
                                                </SelectContent>
                                            </Select>
                                        </td>
                                        <td className="border-b px-3 py-2">
                                            <div className="flex justify-end gap-2">
                                                <Button
                                                    size="sm"
                                                    variant="ghost"
                                                    onClick={() =>
                                                        setEditing(user)
                                                    }
                                                    aria-label={t('Edit user')}
                                                >
                                                    <Pencil className="size-3.5" />
                                                    <span className="ms-1">
                                                        {t('Edit')}
                                                    </span>
                                                </Button>
                                                {user.role === 'customer' ? (
                                                    <>
                                                        <Button
                                                            size="sm"
                                                            variant="outline"
                                                            onClick={() =>
                                                                router.post(
                                                                    adminImpersonateStart.url(
                                                                        user.id,
                                                                    ),
                                                                )
                                                            }
                                                        >
                                                            {t('Impersonate')}
                                                        </Button>
                                                        <Button
                                                            size="sm"
                                                            variant="ghost"
                                                            onClick={async () => {
                                                                const ok =
                                                                    await confirm(
                                                                        {
                                                                            title: t(
                                                                                'Promote user?',
                                                                            ),
                                                                            message:
                                                                                t(
                                                                                    'Promote :email to super_admin?',
                                                                                    {
                                                                                        email: user.email,
                                                                                    },
                                                                                ),
                                                                            confirmLabel:
                                                                                t(
                                                                                    'Promote',
                                                                                ),
                                                                        },
                                                                    );

                                                                if (!ok) {
                                                                    return;
                                                                }

                                                                promote(
                                                                    user.id,
                                                                    'super_admin',
                                                                );
                                                            }}
                                                        >
                                                            {t('Promote')}
                                                        </Button>
                                                    </>
                                                ) : (
                                                    <Button
                                                        size="sm"
                                                        variant="ghost"
                                                        onClick={async () => {
                                                            const ok =
                                                                await confirm({
                                                                    title: t(
                                                                        'Demote user?',
                                                                    ),
                                                                    message: t(
                                                                        'Demote :email to customer?',
                                                                        {
                                                                            email: user.email,
                                                                        },
                                                                    ),
                                                                    confirmLabel:
                                                                        t(
                                                                            'Demote',
                                                                        ),
                                                                    danger: true,
                                                                });

                                                            if (!ok) {
                                                                return;
                                                            }

                                                            promote(
                                                                user.id,
                                                                'customer',
                                                            );
                                                        }}
                                                    >
                                                        {t('Demote')}
                                                    </Button>
                                                )}
                                                {user.id !==
                                                    current_user_id && (
                                                    <Button
                                                        size="sm"
                                                        variant="ghost"
                                                        className="text-destructive hover:bg-destructive/10 hover:text-destructive"
                                                        onClick={() =>
                                                            destroy(user)
                                                        }
                                                        aria-label={t(
                                                            'Delete user',
                                                        )}
                                                    >
                                                        <Trash2 className="size-3.5" />
                                                        <span className="ms-1">
                                                            {t('Delete')}
                                                        </span>
                                                    </Button>
                                                )}
                                            </div>
                                        </td>
                                    </tr>
                                ))
                            )}
                        </tbody>
                    </table>
                </div>

                <TablePagination pagination={pagination} only={USERS_ONLY} />
            </AdminSurface>
            {editing !== null && (
                <EditUserDialog
                    user={editing}
                    onClose={() => setEditing(null)}
                />
            )}
            <BulkActionsBar
                count={selection.size}
                onClear={selection.clear}
                noun={t('user')}
            >
                <BulkDeleteButton
                    confirmMessage={t(
                        'Delete :count selected user(s)? The action skips: your own account, the last super_admin, and anyone who still owns workspaces (transfer ownership first).',
                        { count: selection.size },
                    )}
                    onConfirm={() => {
                        router.post(
                            '/admin/users/bulk-destroy',
                            {
                                ids: Array.from(selection.selected).map((id) =>
                                    Number(id),
                                ),
                            },
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

function EditUserDialog({
    user,
    onClose,
}: {
    user: UserRow;
    onClose: () => void;
}) {
    const { t } = useT();
    const form = useForm({ name: user.name, email: user.email });

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
                    <DialogTitle>{t('Edit user')}</DialogTitle>
                </DialogHeader>
                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.patch(`/admin/users/${user.id}`, {
                            preserveScroll: true,
                            onSuccess: onClose,
                        });
                    }}
                    className="grid gap-3"
                >
                    <div className="grid gap-1.5">
                        <Label htmlFor="edit-name">{t('Name')}</Label>
                        <Input
                            id="edit-name"
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
                    <div className="grid gap-1.5">
                        <Label htmlFor="edit-email">{t('Email')}</Label>
                        <Input
                            id="edit-email"
                            type="email"
                            value={form.data.email}
                            onChange={(e) =>
                                form.setData('email', e.target.value)
                            }
                            required
                        />
                        {form.errors.email && (
                            <p className="text-xs text-destructive">
                                {form.errors.email}
                            </p>
                        )}
                    </div>
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
