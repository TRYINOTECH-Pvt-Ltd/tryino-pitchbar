import { Head, Link } from '@inertiajs/react';
import { Languages, Search } from 'lucide-react';
import { useMemo, useState } from 'react';
import { AdminSurface, AdminSurfaceBar } from '@/components/admin-surface';
import { Input } from '@/components/ui/input';
import AdminLayout from '@/layouts/admin-layout';
import { useT } from '@/lib/i18n';
import {
    index as adminTranslationsIndex,
    show as adminTranslationsShow,
} from '@/routes/admin/translations';

type LocaleRow = {
    code: string;
    native: string;
    flag: string;
    rtl: boolean;
    translated: number;
    total: number;
    missing: number;
    overrides: number;
    is_source: boolean;
};

type Props = {
    locales: LocaleRow[];
    total_keys: number;
};

export default function AdminTranslationsIndex({ locales, total_keys }: Props) {
    const { t } = useT();
    const [query, setQuery] = useState('');

    const filtered = useMemo(() => {
        const term = query.trim().toLowerCase();

        if (term === '') {
            return locales;
        }

        return locales.filter(
            (l) =>
                l.code.toLowerCase().includes(term) ||
                l.native.toLowerCase().includes(term),
        );
    }, [locales, query]);

    return (
        <AdminLayout
            breadcrumbs={[
                { title: t('Admin'), href: '/admin' },
                { title: t('Translations'), href: adminTranslationsIndex() },
            ]}
        >
            <Head title={t('Translations · Admin')} />
            <AdminSurface>
                <AdminSurfaceBar>
                    <span className="inline-flex h-7 items-center rounded-md border bg-card px-2.5 text-xs font-normal">
                        {t(':count languages', {
                            count: locales.length.toLocaleString(),
                        })}
                    </span>
                    <span className="inline-flex h-7 items-center rounded-md border bg-card px-2.5 text-xs font-normal">
                        {t(':count keys', {
                            count: total_keys.toLocaleString(),
                        })}
                    </span>
                    <div className="ms-auto w-full max-w-xs">
                        <div className="relative">
                            <Search className="pointer-events-none absolute start-2.5 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
                            <Input
                                value={query}
                                onChange={(e) => setQuery(e.target.value)}
                                placeholder={t('Search languages…')}
                                className="ps-8"
                            />
                        </div>
                    </div>
                </AdminSurfaceBar>

                <div className="min-h-0 flex-1 overflow-auto p-4">
                    <p className="mb-4 text-sm text-muted-foreground">
                        {t(
                            'Edit the value of any string in any language, or fill in a missing one. Changes are saved as overrides on top of the shipped files and go live immediately — the original files are never touched.',
                        )}
                    </p>
                    <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                        {filtered.map((l) => {
                            const pct =
                                l.total === 0
                                    ? 100
                                    : Math.round(
                                          (l.translated / l.total) * 100,
                                      );

                            return (
                                <Link
                                    key={l.code}
                                    href={adminTranslationsShow(l.code)}
                                    className="flex flex-col gap-3 rounded-lg border bg-card p-4 transition hover:border-foreground/30 hover:bg-foreground/5"
                                >
                                    <div className="flex items-center gap-2">
                                        <span className="text-xl" aria-hidden>
                                            {l.flag}
                                        </span>
                                        <span className="font-medium">
                                            {l.native}
                                        </span>
                                        <span className="text-xs text-muted-foreground uppercase">
                                            {l.code}
                                        </span>
                                        {l.is_source && (
                                            <span className="ms-auto rounded-md border border-sky-200 bg-sky-50 px-1.5 py-0.5 text-[10px] font-medium text-sky-700 dark:border-sky-500/30 dark:bg-sky-500/10 dark:text-sky-300">
                                                {t('Source')}
                                            </span>
                                        )}
                                    </div>

                                    <div>
                                        <div className="h-1.5 w-full overflow-hidden rounded-full bg-muted">
                                            <div
                                                className={
                                                    pct === 100
                                                        ? 'h-full rounded-full bg-emerald-500'
                                                        : 'h-full rounded-full bg-amber-500'
                                                }
                                                style={{ width: `${pct}%` }}
                                            />
                                        </div>
                                        <div className="mt-1.5 flex items-center justify-between text-xs text-muted-foreground">
                                            <span>
                                                {t(
                                                    ':done / :total translated',
                                                    {
                                                        done: l.translated.toLocaleString(),
                                                        total: l.total.toLocaleString(),
                                                    },
                                                )}
                                            </span>
                                            <span>{pct}%</span>
                                        </div>
                                    </div>

                                    <div className="flex gap-2 text-xs">
                                        {l.missing > 0 && (
                                            <span className="rounded-md border border-amber-200 bg-amber-50 px-1.5 py-0.5 font-medium text-amber-700 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-300">
                                                {t(':count missing', {
                                                    count: l.missing.toLocaleString(),
                                                })}
                                            </span>
                                        )}
                                        {l.overrides > 0 && (
                                            <span className="rounded-md border border-indigo-200 bg-indigo-50 px-1.5 py-0.5 font-medium text-indigo-700 dark:border-indigo-500/30 dark:bg-indigo-500/10 dark:text-indigo-300">
                                                {t(':count edited', {
                                                    count: l.overrides.toLocaleString(),
                                                })}
                                            </span>
                                        )}
                                    </div>
                                </Link>
                            );
                        })}
                    </div>

                    {filtered.length === 0 && (
                        <div className="rounded-md border bg-card p-12 text-center text-muted-foreground">
                            <Languages className="mx-auto size-6" />
                            <p className="mt-2 font-medium">
                                {t('No languages match your search.')}
                            </p>
                        </div>
                    )}
                </div>
            </AdminSurface>
        </AdminLayout>
    );
}
