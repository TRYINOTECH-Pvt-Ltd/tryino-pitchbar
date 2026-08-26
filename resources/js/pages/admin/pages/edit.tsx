import { Head } from '@inertiajs/react';
import AdminLayout from '@/layouts/admin-layout';
import { useT } from '@/lib/i18n';
import { PageForm } from './page-form';

type Page = {
    id: string;
    slug: string;
    title: string;
    content_markdown: string;
    is_published: boolean;
    sort_order: number;
    public_url: string | null;
};

type Props = { page: Page };

export default function EditPage({ page }: Props) {
    const { t } = useT();

    return (
        <AdminLayout
            breadcrumbs={[
                { title: t('Admin'), href: '/admin' },
                { title: t('Pages'), href: '/admin/pages' },
                { title: page.title, href: `/admin/pages/${page.id}/edit` },
            ]}
        >
            <Head title={t(':title · Admin', { title: page.title })} />
            <div className="flex flex-col gap-4 p-4">
                <div>
                    <h1 className="text-2xl font-semibold tracking-tight">
                        {page.title}
                    </h1>
                    {page.public_url && (
                        <p className="mt-1 text-xs text-muted-foreground">
                            {t('Live at')}{' '}
                            <a
                                href={page.public_url}
                                target="_blank"
                                rel="noopener noreferrer"
                                className="font-mono underline-offset-4 hover:underline"
                            >
                                {page.public_url}
                            </a>
                        </p>
                    )}
                </div>
                <PageForm
                    initial={{
                        title: page.title,
                        slug: page.slug,
                        content_markdown: page.content_markdown,
                        is_published: page.is_published,
                        sort_order: page.sort_order,
                    }}
                    method="patch"
                    action={`/admin/pages/${page.id}`}
                    submitLabel={t('Save page')}
                />
            </div>
        </AdminLayout>
    );
}
