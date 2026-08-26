import { Head } from '@inertiajs/react';
import { MarkdownLite } from '@/components/markdown-lite';
import { MarketingShell } from '@/layouts/marketing-shell';
import type { MarketingShellContent } from '@/layouts/marketing-shell';
import { useT } from '@/lib/i18n';

type Entry = {
    version: string;
    released_at: string | null;
    released_at_human: string | null;
    title: string;
    body: string;
};

type Props = {
    shell: MarketingShellContent;
    brand: string;
    entries: Entry[];
};

export default function MarketingChangelog({ shell, brand, entries }: Props) {
    const { t } = useT();

    return (
        <>
            <Head title={t('Changelog — :brand', { brand })}>
                <meta
                    name="description"
                    content={t(
                        'Release notes for :brand — every shipped change, organised by version.',
                        { brand },
                    )}
                />
            </Head>

            <MarketingShell content={shell}>
                <section className="mx-auto max-w-3xl px-6 pt-16 pb-10 lg:pt-20">
                    <div className="flex items-end justify-between gap-4">
                        <div>
                            <span className="inline-flex items-center gap-2 rounded-full border border-slate-900/10 bg-white/85 px-3 py-1 text-xs font-semibold tracking-[0.2em] text-slate-600 uppercase">
                                {t('Changelog')}
                            </span>
                            <h1 className="mt-5 text-4xl font-bold tracking-tight text-slate-950 sm:text-5xl">
                                {t("What's new in :brand", { brand })}
                            </h1>
                            <p className="mt-4 max-w-2xl text-base leading-7 text-slate-600">
                                {t(
                                    'Every shipped change, newest first. Linkable per version — anchor on',
                                )}{' '}
                                <code className="rounded bg-slate-100 px-1 font-mono text-sm">
                                    #vX.Y.Z
                                </code>
                                .
                            </p>
                        </div>
                    </div>
                </section>

                <section className="mx-auto max-w-3xl px-6 pb-24">
                    {entries.length === 0 ? (
                        <p className="text-sm text-slate-500">
                            {t('No published entries yet.')}
                        </p>
                    ) : (
                        <div className="grid gap-12">
                            {entries.map((entry) => (
                                <article
                                    key={entry.version}
                                    id={entry.version}
                                    className="scroll-mt-24"
                                >
                                    <header className="flex flex-wrap items-baseline gap-3 border-b border-slate-900/10 pb-3">
                                        <h2 className="font-mono text-2xl font-bold tracking-tight text-slate-950">
                                            {entry.version}
                                        </h2>
                                        {entry.released_at_human && (
                                            <time
                                                className="text-sm text-slate-500"
                                                dateTime={
                                                    entry.released_at ??
                                                    undefined
                                                }
                                            >
                                                {entry.released_at_human}
                                            </time>
                                        )}
                                    </header>
                                    <h3 className="mt-4 text-lg font-semibold text-slate-900">
                                        {entry.title}
                                    </h3>
                                    <div className="mt-3 text-[15px] leading-7 text-slate-700">
                                        <MarkdownLite
                                            markdown={entry.body}
                                            variant="changelog"
                                        />
                                    </div>
                                </article>
                            ))}
                        </div>
                    )}
                </section>
            </MarketingShell>
        </>
    );
}
