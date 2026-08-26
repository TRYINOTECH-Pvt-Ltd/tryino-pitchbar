import { Head, Link, router } from '@inertiajs/react';
import { Plus, Trash2 } from 'lucide-react';
import { useConfirm } from '@/components/confirm-dialog-provider';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import AdminLayout from '@/layouts/admin-layout';
import { useT } from '@/lib/i18n';
import {
    create as adminChangelogCreate,
    destroy as adminChangelogDestroy,
    edit as adminChangelogEdit,
    publish as adminChangelogPublish,
} from '@/routes/admin/changelog';
import type { BreadcrumbItem } from '@/types';

type Status = 'draft' | 'published' | 'archived';

type Entry = {
    id: string;
    version: string;
    released_at: string | null;
    status: Status;
    title: string;
    body: string;
    created_at: string;
    updated_at: string;
};

type Props = {
    entries: Entry[];
};

const statusTone: Record<Status, string> = {
    draft: 'bg-amber-50 text-amber-800 border-amber-200',
    published: 'bg-emerald-50 text-emerald-800 border-emerald-200',
    archived: 'bg-slate-100 text-slate-600 border-slate-200',
};

export default function AdminChangelogIndex({ entries }: Props) {
    const { t } = useT();
    const confirm = useConfirm();

    const breadcrumbs: BreadcrumbItem[] = [
        { title: t('Admin'), href: '/admin' },
        { title: t('Changelog'), href: '/admin/changelog' },
    ];

    const statusLabel: Record<Status, string> = {
        draft: t('draft'),
        published: t('published'),
        archived: t('archived'),
    };

    return (
        <AdminLayout breadcrumbs={breadcrumbs}>
            <Head title={t('Changelog · Admin')} />

            <div className="flex flex-1 flex-col gap-4 p-4">
                <header className="flex items-start justify-between gap-3">
                    <div>
                        <h1 className="text-2xl font-semibold tracking-tight">
                            {t('Changelog')}
                        </h1>
                        <p className="mt-1 max-w-2xl text-sm text-muted-foreground">
                            {t('Authoring surface for the public')}{' '}
                            <a
                                className="font-medium text-foreground underline-offset-2 hover:underline"
                                href="/changelog"
                            >
                                /changelog
                            </a>{' '}
                            {t(
                                "page. Drafts stay invisible to buyers; once published, the entry's version becomes immutable.",
                            )}
                        </p>
                    </div>
                    <Button asChild>
                        <Link href={adminChangelogCreate()}>
                            <Plus className="me-1 size-4" /> {t('New entry')}
                        </Link>
                    </Button>
                </header>

                {entries.length === 0 ? (
                    <Card className="p-8 text-center text-sm text-muted-foreground">
                        {t(
                            'No changelog entries yet. Click "New entry" to write the first one.',
                        )}
                    </Card>
                ) : (
                    <div className="grid gap-3">
                        {entries.map((entry) => (
                            <Card key={entry.id}>
                                <CardContent className="flex flex-wrap items-start justify-between gap-3 p-4">
                                    <div className="min-w-0 flex-1">
                                        <div className="flex items-center gap-2">
                                            <Link
                                                href={
                                                    adminChangelogEdit(entry.id)
                                                        .url
                                                }
                                                className="font-mono text-base font-semibold hover:underline"
                                            >
                                                {entry.version}
                                            </Link>
                                            <Badge
                                                className={
                                                    'border ' +
                                                    statusTone[entry.status]
                                                }
                                            >
                                                {statusLabel[entry.status]}
                                            </Badge>
                                            {entry.released_at && (
                                                <span className="text-xs text-muted-foreground">
                                                    {new Date(
                                                        entry.released_at,
                                                    ).toLocaleDateString()}
                                                </span>
                                            )}
                                        </div>
                                        <p className="mt-1 line-clamp-2 text-sm font-medium">
                                            {entry.title}
                                        </p>
                                    </div>
                                    <div className="flex shrink-0 items-center gap-2">
                                        {entry.status === 'draft' && (
                                            <Button
                                                type="button"
                                                size="sm"
                                                onClick={() =>
                                                    router.post(
                                                        adminChangelogPublish(
                                                            entry.id,
                                                        ).url,
                                                        {},
                                                        {
                                                            preserveScroll: true,
                                                        },
                                                    )
                                                }
                                            >
                                                {t('Publish')}
                                            </Button>
                                        )}
                                        <Button
                                            asChild
                                            variant="outline"
                                            size="sm"
                                        >
                                            <Link
                                                href={
                                                    adminChangelogEdit(entry.id)
                                                        .url
                                                }
                                            >
                                                {t('Edit')}
                                            </Link>
                                        </Button>
                                        {entry.status !== 'archived' && (
                                            <Button
                                                type="button"
                                                variant="ghost"
                                                size="sm"
                                                onClick={async () => {
                                                    const ok = await confirm({
                                                        title: t(
                                                            'Archive entry?',
                                                        ),
                                                        message: t(
                                                            'Archive :version? It will stay in the table for linkbacks but disappear from /changelog.',
                                                            {
                                                                version:
                                                                    entry.version,
                                                            },
                                                        ),
                                                        confirmLabel:
                                                            t('Archive'),
                                                        danger: true,
                                                    });

                                                    if (!ok) {
                                                        return;
                                                    }

                                                    router.delete(
                                                        adminChangelogDestroy(
                                                            entry.id,
                                                        ).url,
                                                        {
                                                            preserveScroll: true,
                                                        },
                                                    );
                                                }}
                                            >
                                                <Trash2 className="size-4 text-destructive" />
                                            </Button>
                                        )}
                                    </div>
                                </CardContent>
                            </Card>
                        ))}
                    </div>
                )}
            </div>
        </AdminLayout>
    );
}
