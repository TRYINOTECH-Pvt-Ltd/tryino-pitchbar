import { Head } from '@inertiajs/react';
import AdminLayout from '@/layouts/admin-layout';
import { useT } from '@/lib/i18n';
import type { BreadcrumbItem } from '@/types';
import { ChangelogForm } from './changelog-form';

type Entry = {
    id: string;
    version: string;
    released_at: string | null;
    status: 'draft' | 'published' | 'archived';
    title: string;
    body: string;
};

type Props = {
    entry: Entry;
};

export default function EditChangelog({ entry }: Props) {
    const { t } = useT();
    const breadcrumbs: BreadcrumbItem[] = [
        { title: t('Admin'), href: '/admin' },
        { title: t('Changelog'), href: '/admin/changelog' },
        {
            title: entry.version,
            href: `/admin/changelog/${entry.id}/edit`,
        },
    ];

    return (
        <AdminLayout breadcrumbs={breadcrumbs}>
            <Head title={t('Edit :version', { version: entry.version })} />
            <div className="flex flex-1 flex-col gap-4 p-4">
                <div>
                    <h1 className="text-2xl font-semibold tracking-tight">
                        {t('Edit')}{' '}
                        <span className="font-mono">{entry.version}</span>
                    </h1>
                </div>

                <ChangelogForm
                    initial={{
                        version: entry.version,
                        released_at: entry.released_at
                            ? entry.released_at.slice(0, 10)
                            : '',
                        status: entry.status,
                        title: entry.title,
                        body: entry.body,
                    }}
                    method="patch"
                    action={`/admin/changelog/${entry.id}`}
                    submitLabel={t('Save entry')}
                    versionLocked={entry.status === 'published'}
                />
            </div>
        </AdminLayout>
    );
}
