import { Head } from '@inertiajs/react';

import { MarketingShell } from '@/layouts/marketing-shell';
import type { MarketingShellContent } from '@/layouts/marketing-shell';
import { useT } from '@/lib/i18n';

type Props = {
    shell: MarketingShellContent;
    brand: string;
    page: {
        title: string;
        slug: string;
        html: string;
        updated_at: string | null;
    };
};

export default function CustomPage({ shell, brand, page }: Props) {
    const { t } = useT();

    return (
        <>
            <Head title={`${page.title} — ${brand}`} />

            <MarketingShell content={shell}>
                <article className="mx-auto max-w-3xl px-6 pt-16 pb-20 lg:pt-20">
                    <h1 className="text-4xl font-bold tracking-tight text-slate-950 sm:text-5xl">
                        {page.title}
                    </h1>
                    <div
                        className="page-content mt-8 text-base leading-7 text-slate-900"
                        dangerouslySetInnerHTML={{ __html: page.html }}
                    />
                    {page.updated_at && (
                        <p className="mt-12 border-t border-slate-900/10 pt-4 text-xs text-slate-500">
                            {t('Last updated:')}{' '}
                            <time dateTime={page.updated_at}>
                                {new Date(page.updated_at).toLocaleDateString(
                                    undefined,
                                    {
                                        year: 'numeric',
                                        month: 'long',
                                        day: 'numeric',
                                    },
                                )}
                            </time>
                        </p>
                    )}
                </article>
            </MarketingShell>
        </>
    );
}
