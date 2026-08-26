import { Head, router } from '@inertiajs/react';
import {
    ChevronLeft,
    ChevronRight,
    RotateCcw,
    Save,
    Search,
} from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { AdminSurface, AdminSurfaceBar } from '@/components/admin-surface';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import AdminLayout from '@/layouts/admin-layout';
import { useT } from '@/lib/i18n';
import {
    destroy as adminTranslationsDestroy,
    index as adminTranslationsIndex,
    show as adminTranslationsShow,
    update as adminTranslationsUpdate,
} from '@/routes/admin/translations';

type Row = {
    key: string;
    source: string;
    value: string;
    is_missing: boolean;
    is_overridden: boolean;
};

type Props = {
    locale: {
        code: string;
        native: string;
        flag: string;
        rtl: boolean;
        is_source: boolean;
    };
    rows: Row[];
    filters: { search: string; filter: 'all' | 'missing' | 'overridden' };
    pagination: {
        page: number;
        last_page: number;
        per_page: number;
        total: number;
    };
    stats: { total: number; missing: number; overrides: number };
};

const FILTERS: Array<{ key: 'all' | 'missing' | 'overridden'; label: string }> =
    [
        { key: 'all', label: 'All' },
        { key: 'missing', label: 'Missing' },
        { key: 'overridden', label: 'Edited' },
    ];

export default function AdminTranslationsEdit({
    locale,
    rows,
    filters,
    pagination,
    stats,
}: Props) {
    const { t } = useT();
    const [search, setSearch] = useState(filters.search);
    const [drafts, setDrafts] = useState<Record<string, string>>({});
    const [savingKey, setSavingKey] = useState<string | null>(null);
    const firstRender = useRef(true);

    // Reseed the editable drafts whenever the server sends a new page of
    // rows (navigation, save, filter change).
    useEffect(() => {
        const next: Record<string, string> = {};

        for (const row of rows) {
            next[row.key] = row.value;
        }

        // Reseeding editable drafts from the freshly fetched page is the
        // intended effect here, not a render-loop hazard.
        // eslint-disable-next-line react-hooks/set-state-in-effect
        setDrafts(next);
    }, [rows]);

    const visit = (params: {
        search: string;
        filter: string;
        page: number;
    }) => {
        router.get(adminTranslationsShow(locale.code).url, params, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            only: ['rows', 'filters', 'pagination', 'stats'],
        });
    };

    // Debounced server-side search.
    useEffect(() => {
        if (firstRender.current) {
            firstRender.current = false;

            return;
        }

        const handle = setTimeout(() => {
            visit({ search, filter: filters.filter, page: 1 });
        }, 350);

        return () => clearTimeout(handle);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [search]);

    const save = (row: Row) => {
        const value = drafts[row.key] ?? '';

        if (value.trim() === '') {
            return;
        }

        setSavingKey(row.key);
        router.put(
            adminTranslationsUpdate(locale.code).url,
            { key: row.key, value },
            {
                preserveScroll: true,
                preserveState: true,
                only: ['rows', 'filters', 'pagination', 'stats', 'flash'],
                onFinish: () => setSavingKey(null),
            },
        );
    };

    const reset = (row: Row) => {
        setSavingKey(row.key);
        router.delete(adminTranslationsDestroy(locale.code).url, {
            data: { key: row.key },
            preserveScroll: true,
            preserveState: true,
            only: ['rows', 'filters', 'pagination', 'stats', 'flash'],
            onFinish: () => setSavingKey(null),
        });
    };

    return (
        <AdminLayout
            breadcrumbs={[
                { title: t('Admin'), href: '/admin' },
                { title: t('Translations'), href: adminTranslationsIndex() },
                {
                    title: `${locale.flag} ${locale.native}`,
                    href: adminTranslationsShow(locale.code),
                },
            ]}
        >
            <Head title={`${locale.native} · ${t('Translations')}`} />
            <AdminSurface>
                <AdminSurfaceBar>
                    <span className="inline-flex items-center gap-1.5 text-sm font-medium">
                        <span className="text-lg" aria-hidden>
                            {locale.flag}
                        </span>
                        {locale.native}
                        <span className="text-xs text-muted-foreground uppercase">
                            {locale.code}
                        </span>
                    </span>
                    <span className="inline-flex h-7 items-center rounded-md border border-amber-200 bg-amber-50 px-2.5 text-xs font-medium text-amber-700 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-300">
                        {t(':count missing', {
                            count: stats.missing.toLocaleString(),
                        })}
                    </span>
                    <span className="inline-flex h-7 items-center rounded-md border border-indigo-200 bg-indigo-50 px-2.5 text-xs font-medium text-indigo-700 dark:border-indigo-500/30 dark:bg-indigo-500/10 dark:text-indigo-300">
                        {t(':count edited', {
                            count: stats.overrides.toLocaleString(),
                        })}
                    </span>
                    <div className="ms-auto w-full max-w-xs">
                        <div className="relative">
                            <Search className="pointer-events-none absolute start-2.5 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
                            <Input
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                                placeholder={t('Search strings…')}
                                className="ps-8"
                            />
                        </div>
                    </div>
                </AdminSurfaceBar>

                <div className="flex items-center gap-1 border-b px-4 py-2">
                    {FILTERS.map((f) => (
                        <button
                            key={f.key}
                            type="button"
                            onClick={() =>
                                visit({ search, filter: f.key, page: 1 })
                            }
                            className={`rounded-md px-2.5 py-1 text-xs font-medium transition ${
                                filters.filter === f.key
                                    ? 'bg-foreground/10 text-foreground'
                                    : 'text-muted-foreground hover:bg-foreground/5'
                            }`}
                        >
                            {t(f.label)}
                        </button>
                    ))}
                </div>

                <div className="min-h-0 flex-1 overflow-auto p-4">
                    {rows.length === 0 ? (
                        <div className="rounded-md border bg-card p-12 text-center text-muted-foreground">
                            <p className="font-medium">
                                {t('No strings match.')}
                            </p>
                        </div>
                    ) : (
                        <div className="space-y-3">
                            {rows.map((row) => (
                                <div
                                    key={row.key}
                                    className="rounded-lg border bg-card p-3"
                                >
                                    <div className="mb-2 flex items-start gap-2">
                                        <p className="flex-1 text-xs text-muted-foreground">
                                            {row.source}
                                        </p>
                                        {row.is_missing && (
                                            <span className="rounded-md border border-amber-200 bg-amber-50 px-1.5 py-0.5 text-[10px] font-medium text-amber-700 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-300">
                                                {t('Missing')}
                                            </span>
                                        )}
                                        {row.is_overridden && (
                                            <span className="rounded-md border border-indigo-200 bg-indigo-50 px-1.5 py-0.5 text-[10px] font-medium text-indigo-700 dark:border-indigo-500/30 dark:bg-indigo-500/10 dark:text-indigo-300">
                                                {t('Edited')}
                                            </span>
                                        )}
                                    </div>
                                    <div className="flex items-end gap-2">
                                        <Textarea
                                            dir={locale.rtl ? 'rtl' : 'ltr'}
                                            rows={1}
                                            value={drafts[row.key] ?? ''}
                                            onChange={(e) =>
                                                setDrafts((d) => ({
                                                    ...d,
                                                    [row.key]: e.target.value,
                                                }))
                                            }
                                            placeholder={t(
                                                'Translation in :lang',
                                                { lang: locale.native },
                                            )}
                                            className="min-h-9 flex-1 resize-y"
                                        />
                                        <Button
                                            type="button"
                                            size="sm"
                                            disabled={
                                                savingKey === row.key ||
                                                (drafts[row.key] ?? '') ===
                                                    row.value ||
                                                (
                                                    drafts[row.key] ?? ''
                                                ).trim() === ''
                                            }
                                            onClick={() => save(row)}
                                        >
                                            <Save className="size-4" />
                                            <span className="ms-1">
                                                {t('Save')}
                                            </span>
                                        </Button>
                                        {row.is_overridden && (
                                            <Button
                                                type="button"
                                                size="sm"
                                                variant="outline"
                                                disabled={savingKey === row.key}
                                                onClick={() => reset(row)}
                                                title={t(
                                                    'Reset to the shipped default',
                                                )}
                                            >
                                                <RotateCcw className="size-4" />
                                            </Button>
                                        )}
                                    </div>
                                </div>
                            ))}
                        </div>
                    )}
                </div>

                <div className="flex items-center justify-between border-t px-4 py-2 text-xs text-muted-foreground">
                    <span>
                        {t('Showing :from–:to of :total', {
                            from: (
                                (pagination.page - 1) * pagination.per_page +
                                (rows.length > 0 ? 1 : 0)
                            ).toLocaleString(),
                            to: (
                                (pagination.page - 1) * pagination.per_page +
                                rows.length
                            ).toLocaleString(),
                            total: pagination.total.toLocaleString(),
                        })}
                    </span>
                    <div className="flex items-center gap-1">
                        <Button
                            type="button"
                            size="sm"
                            variant="outline"
                            disabled={pagination.page <= 1}
                            onClick={() =>
                                visit({
                                    search,
                                    filter: filters.filter,
                                    page: pagination.page - 1,
                                })
                            }
                        >
                            <ChevronLeft className="size-4" />
                        </Button>
                        <span>
                            {t(':page / :pages', {
                                page: pagination.page.toLocaleString(),
                                pages: pagination.last_page.toLocaleString(),
                            })}
                        </span>
                        <Button
                            type="button"
                            size="sm"
                            variant="outline"
                            disabled={pagination.page >= pagination.last_page}
                            onClick={() =>
                                visit({
                                    search,
                                    filter: filters.filter,
                                    page: pagination.page + 1,
                                })
                            }
                        >
                            <ChevronRight className="size-4" />
                        </Button>
                    </div>
                </div>
            </AdminSurface>
        </AdminLayout>
    );
}
