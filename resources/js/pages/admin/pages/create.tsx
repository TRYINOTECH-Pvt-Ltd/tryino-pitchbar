import { Head } from '@inertiajs/react';
import AdminLayout from '@/layouts/admin-layout';
import { useT } from '@/lib/i18n';
import { PageForm } from './page-form';

export default function CreatePage() {
    const { t } = useT();

    return (
        <AdminLayout
            breadcrumbs={[
                { title: t('Admin'), href: '/admin' },
                { title: t('Pages'), href: '/admin/pages' },
                { title: t('New page'), href: '/admin/pages/create' },
            ]}
        >
            <Head title={t('New page · Admin')} />
            <div className="flex flex-col gap-4 p-4">
                <h1 className="text-2xl font-semibold tracking-tight">
                    {t('New page')}
                </h1>
                <PageForm
                    initial={{
                        title: '',
                        slug: '',
                        content_markdown: '',
                        is_published: false,
                        sort_order: 0,
                    }}
                    method="post"
                    action="/admin/pages"
                    submitLabel={t('Create page')}
                />
            </div>
        </AdminLayout>
    );
}
