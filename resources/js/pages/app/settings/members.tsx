import { Head, router, useForm } from '@inertiajs/react';
import { PlanLimitBanner, usePlanLimit } from '@/components/plan-limit-banner';
import { TablePagination } from '@/components/table-pagination';
import type { PaginationMeta } from '@/components/table-pagination';
import { TableSearch } from '@/components/table-search';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { useT } from '@/lib/i18n';
import { destroy as destroyInvitation } from '@/routes/invitations';
import {
    destroy as destroyMember,
    store as storeMember,
} from '@/routes/members';
import type { BreadcrumbItem } from '@/types';

type Member = {
    id: string;
    role: string;
    user: { id: number; name: string; email: string };
};

type Pending = { id: string; email: string; role: string; expires_at: string };

type Props = {
    members: Member[];
    pendingInvitations: Pending[];
    pagination: PaginationMeta;
    filters: { q: string };
};

export default function MembersIndex({
    members,
    pendingInvitations,
    pagination,
    filters,
}: Props) {
    const { t } = useT();
    const form = useForm<{ email: string; role: string }>({
        email: '',
        role: 'editor',
    });
    const memberLimit = usePlanLimit('member');
    const canInvite =
        memberLimit === null ||
        memberLimit.limit === null ||
        (memberLimit.allowed && (memberLimit.remaining ?? 1) > 0);

    const breadcrumbs: BreadcrumbItem[] = [
        { title: t('Members'), href: '/app/members' },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('Members')} />
            <div className="flex flex-1 flex-col gap-6 p-4">
                <h1 className="text-2xl font-semibold tracking-tight">
                    {t('Members')}
                </h1>

                <PlanLimitBanner resource="member" />

                <Card className="border-border/80 p-5">
                    <h2 className="mb-3 font-medium">
                        {t('Invite a teammate')}
                    </h2>
                    <form
                        onSubmit={(e) => {
                            e.preventDefault();
                            form.post(storeMember.url(), {
                                onSuccess: () => form.reset(),
                            });
                        }}
                        className="grid gap-3 md:grid-cols-[1fr_180px_auto]"
                    >
                        <div className="grid gap-1.5">
                            <Label htmlFor="email">{t('Email')}</Label>
                            <Input
                                id="email"
                                type="email"
                                autoComplete="off"
                                value={form.data.email}
                                onChange={(e) =>
                                    form.setData('email', e.target.value)
                                }
                            />
                            {form.errors.email && (
                                <p className="mt-1 text-xs text-destructive">
                                    {form.errors.email}
                                </p>
                            )}
                        </div>
                        <div className="grid gap-1.5">
                            <Label htmlFor="role">{t('Role')}</Label>
                            <Select
                                value={form.data.role}
                                onValueChange={(v) => form.setData('role', v)}
                            >
                                <SelectTrigger id="role">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="admin">
                                        {t('Admin')}
                                    </SelectItem>
                                    <SelectItem value="editor">
                                        {t('Editor')}
                                    </SelectItem>
                                    <SelectItem value="viewer">
                                        {t('Viewer')}
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                        </div>
                        <div className="flex items-end">
                            <Button
                                type="submit"
                                disabled={form.processing || !canInvite}
                                title={
                                    canInvite
                                        ? undefined
                                        : t('Plan member limit reached')
                                }
                            >
                                {t('Send invite')}
                            </Button>
                        </div>
                    </form>
                </Card>

                <Card className="p-4">
                    <div className="mb-3 flex items-center justify-between gap-3">
                        <h2 className="font-medium">
                            {t('Current members (:total)', {
                                total: pagination.total,
                            })}
                        </h2>
                        <TableSearch
                            placeholder={t('Search by name or email…')}
                            initialValue={filters.q}
                            only={['members', 'pagination', 'filters']}
                        />
                    </div>
                    <div className="divide-y">
                        {members.map((m) => (
                            <div
                                key={m.id}
                                className="flex items-center justify-between py-2"
                            >
                                <div>
                                    <p className="font-medium">{m.user.name}</p>
                                    <p className="text-xs text-muted-foreground">
                                        {m.user.email}
                                    </p>
                                </div>
                                <div className="flex items-center gap-3">
                                    <span className="text-xs tracking-wide text-muted-foreground uppercase">
                                        {m.role}
                                    </span>
                                    {m.role !== 'owner' && (
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            onClick={() =>
                                                form.delete(
                                                    destroyMember.url({
                                                        member: m.id,
                                                    }),
                                                )
                                            }
                                        >
                                            {t('Remove')}
                                        </Button>
                                    )}
                                </div>
                            </div>
                        ))}
                    </div>
                    <TablePagination
                        pagination={pagination}
                        only={['members', 'pagination', 'filters']}
                    />
                </Card>

                {pendingInvitations.length > 0 && (
                    <Card className="p-4">
                        <h2 className="mb-3 font-medium">
                            {t('Pending invitations')}
                        </h2>
                        <div className="divide-y">
                            {pendingInvitations.map((inv) => (
                                <div
                                    key={inv.id}
                                    className="flex items-center justify-between py-2"
                                >
                                    <p className="font-medium">{inv.email}</p>
                                    <div className="flex items-center gap-3">
                                        <span className="text-xs tracking-wide text-muted-foreground uppercase">
                                            {inv.role}
                                        </span>
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            onClick={() => {
                                                if (
                                                    confirm(
                                                        t(
                                                            'Revoke invitation for :email?',
                                                            {
                                                                email: inv.email,
                                                            },
                                                        ),
                                                    )
                                                ) {
                                                    router.delete(
                                                        destroyInvitation.url({
                                                            invitation: inv.id,
                                                        }),
                                                        {
                                                            preserveScroll: true,
                                                        },
                                                    );
                                                }
                                            }}
                                        >
                                            {t('Revoke')}
                                        </Button>
                                    </div>
                                </div>
                            ))}
                        </div>
                    </Card>
                )}
            </div>
        </AppLayout>
    );
}
