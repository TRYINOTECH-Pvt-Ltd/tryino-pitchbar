import { Head } from '@inertiajs/react';
import AdminLayout from '@/layouts/admin-layout';
import { useT } from '@/lib/i18n';
import type { BreadcrumbItem } from '@/types';
import { ChangelogForm } from './changelog-form';

export default function CreateChangelog() {
    const { t } = useT();

    const breadcrumbs: BreadcrumbItem[] = [
        { title: t('Admin'), href: '/admin' },
        { title: t('Changelog'), href: '/admin/changelog' },
        { title: t('New'), href: '/admin/changelog/create' },
    ];

    return (
        <AdminLayout breadcrumbs={breadcrumbs}>
            <Head title={t('New changelog entry')} />
            <div className="flex flex-1 flex-col gap-4 p-4">
                <div>
                    <h1 className="text-2xl font-semibold tracking-tight">
                        {t('New entry')}
                    </h1>
                    <p className="mt-1 text-sm text-muted-foreground">
                        {t(
                            'Save as Draft to keep iterating, or Published to push it to /changelog right away.',
                        )}
                    </p>
                </div>

                <ChangelogForm
                    initial={{
                        version: '',
                        released_at: '',
                        status: 'draft',
                        title: '',
                        body: '',
                    }}
                    method="post"
                    action="/admin/changelog"
                    submitLabel={t('Create entry')}
                />
            </div>
        </AdminLayout>
    );
}
