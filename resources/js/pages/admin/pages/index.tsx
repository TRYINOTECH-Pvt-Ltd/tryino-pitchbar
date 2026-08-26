import { Head, Link, router } from '@inertiajs/react';
import { ExternalLink, FileText, Pencil, Plus, Trash2 } from 'lucide-react';
import { AdminSurface, AdminSurfaceBar } from '@/components/admin-surface';
import { useConfirm } from '@/components/confirm-dialog-provider';
import { TablePagination } from '@/components/table-pagination';
import type { PaginationMeta } from '@/components/table-pagination';
import { TableSearch } from '@/components/table-search';
import { Button } from '@/components/ui/button';
import AdminLayout from '@/layouts/admin-layout';
import { useT } from '@/lib/i18n';
import { relativeTime } from '@/lib/relative-time';
import {
    create as adminPagesCreate,
    destroy as adminPagesDestroy,
    edit as adminPagesEdit,
    index as adminPagesIndex,
} from '@/routes/admin/pages';

type Page = {
    id: string;
    slug: string;
    title: string;
    is_published: boolean;
    sort_order: number;
    updated_at: string | null;
    public_url: string | null;
};

type Props = {
    pages: Page[];
    pagination: PaginationMeta;
    filters: { q: string };
};

const PAGES_ONLY = ['pages', 'pagination', 'filters'];

export default function AdminPagesIndex({ pages, pagination, filters }: Props) {
    const { t } = useT();
    const confirm = useConfirm();

    const remove = async (page: Page) => {
        const ok = await confirm({
            title: t('Delete page?'),
            message: t('Delete ":title"? This cannot be undone.', {
                title: page.title,
            }),
            confirmLabel: t('Delete'),
            danger: true,
        });

        if (!ok) {
            return;
        }

        router.delete(adminPagesDestroy(page.id).url, { preserveScroll: true });
    };

    return (
        <AdminLayout
            breadcrumbs={[
                { title: t('Admin'), href: '/admin' },
                { title: t('Pages'), href: adminPagesIndex() },
            ]}
        >
            <Head title={t('Pages · Admin')} />
            <AdminSurface>
                <AdminSurfaceBar>
                    <span className="inline-flex h-7 items-center rounded-md border bg-card px-2.5 text-xs font-normal">
                        {t(':count pages', {
                            count: pagination.total.toLocaleString(),
                        })}
                    </span>
                    <TableSearch
                        placeholder={t('Search by title or slug…')}
                        initialValue={filters.q}
                        only={PAGES_ONLY}
                    />
                    <div className="ms-auto">
                        <Button asChild size="sm">
                            <Link href={adminPagesCreate()}>
                                <Plus className="size-4" />
                                <span className="ms-1">{t('New page')}</span>
                            </Link>
                        </Button>
                    </div>
                </AdminSurfaceBar>

                <div className="min-h-0 flex-1 overflow-auto">
                    <table className="w-full border-separate border-spacing-0 text-sm">
                        <thead className="sticky top-0 z-10 bg-card text-xs font-medium text-muted-foreground">
                            <tr>
                                <th className="border-e border-b px-3 py-2 text-start">
                                    {t('Title')}
                                </th>
                                <th className="border-e border-b px-3 py-2 text-start">
                                    {t('Slug')}
                                </th>
                                <th className="border-e border-b px-3 py-2">
                                    {t('Status')}
                                </th>
                                <th className="border-e border-b px-3 py-2">
                                    {t('Order')}
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
                            {pages.length === 0 ? (
                                <tr>
                                    <td
                                        colSpan={6}
                                        className="border-b px-3 py-12 text-center text-muted-foreground"
                                    >
                                        <FileText className="mx-auto size-6 text-muted-foreground" />
                                        <p className="mt-2 font-medium">
                                            {t('No pages yet')}
                                        </p>
                                        <p className="mt-1 text-xs">
                                            {t(
                                                'Create your first custom page (About, Company, Product…).',
                                            )}
                                        </p>
                                    </td>
                                </tr>
                            ) : (
                                pages.map((page) => (
                                    <tr
                                        key={page.id}
                                        className="hover:bg-muted/35"
                                    >
                                        <td className="border-e border-b px-3 py-2 font-medium">
                                            {page.title}
                                        </td>
                                        <td className="border-e border-b px-3 py-2 font-mono text-xs text-muted-foreground">
                                            /p/{page.slug}
                                        </td>
                                        <td className="border-e border-b px-3 py-2 text-center">
                                            {page.is_published ? (
                                                <span className="inline-flex items-center rounded-md border border-emerald-200 bg-emerald-50 px-2 py-0.5 text-xs font-medium text-emerald-700 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-300">
                                                    {t('Published')}
                                                </span>
                                            ) : (
                                                <span className="inline-flex items-center rounded-md border border-amber-200 bg-amber-50 px-2 py-0.5 text-xs font-medium text-amber-700 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-300">
                                                    {t('Draft')}
                                                </span>
                                            )}
                                        </td>
                                        <td className="border-e border-b px-3 py-2 text-center tabular-nums">
                                            {page.sort_order}
                                        </td>
                                        <td className="border-e border-b px-3 py-2 text-center text-muted-foreground">
                                            {relativeTime(page.updated_at, '—')}
                                        </td>
                                        <td className="border-b px-3 py-2">
                                            <div className="flex justify-end gap-1">
                                                {page.public_url && (
                                                    <Button
                                                        asChild
                                                        size="sm"
                                                        variant="ghost"
                                                    >
                                                        <a
                                                            href={
                                                                page.public_url
                                                            }
                                                            target="_blank"
                                                            rel="noopener noreferrer"
                                                            aria-label={t(
                                                                'View public page',
                                                            )}
                                                        >
                                                            <ExternalLink className="size-3.5" />
                                                        </a>
                                                    </Button>
                                                )}
                                                <Button
                                                    asChild
                                                    size="sm"
                                                    variant="ghost"
                                                >
                                                    <Link
                                                        href={
                                                            adminPagesEdit(
                                                                page.id,
                                                            ).url
                                                        }
                                                        aria-label={t('Edit')}
                                                    >
                                                        <Pencil className="size-3.5" />
                                                    </Link>
                                                </Button>
                                                <Button
                                                    size="sm"
                                                    variant="ghost"
                                                    onClick={() => remove(page)}
                                                    aria-label={t('Delete')}
                                                >
                                                    <Trash2 className="size-3.5 text-rose-600 dark:text-rose-400" />
                                                </Button>
                                            </div>
                                        </td>
                                    </tr>
                                ))
                            )}
                        </tbody>
                    </table>
                </div>

                <TablePagination pagination={pagination} only={PAGES_ONLY} />
            </AdminSurface>
        </AdminLayout>
    );
}
