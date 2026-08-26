import { Head } from '@inertiajs/react';
import { MarketingShell } from '@/layouts/marketing-shell';
import type { MarketingShellContent } from '@/layouts/marketing-shell';
import { useT } from '@/lib/i18n';

type Section = {
    title: string;
    body?: string[];
    bullets?: string[];
};

type Props = {
    shell: MarketingShellContent;
    brand: string;
    effective_date: string;
    contact_email: string;
    intro: string;
    sections: Section[];
};

export default function Terms({
    shell,
    brand,
    effective_date,
    contact_email,
    intro,
    sections,
}: Props) {
    const { t } = useT();

    return (
        <>
            <Head title={t('Terms of service — :brand', { brand })} />

            <MarketingShell content={shell}>
                <article className="mx-auto max-w-3xl px-6 pt-16 pb-20">
                    <p className="text-sm font-medium tracking-wider text-emerald-700 uppercase">
                        {t('Legal')}
                    </p>
                    <h1 className="mt-4 text-4xl font-bold tracking-tight text-slate-950 sm:text-5xl">
                        {t('Terms of service')}
                    </h1>
                    <p className="mt-5 text-base leading-7 text-slate-600 sm:text-lg">
                        {intro}
                    </p>
                    <p className="mt-2 text-sm text-slate-500">
                        <strong className="text-slate-700">
                            {t('Effective date:')}
                        </strong>{' '}
                        {effective_date}
                    </p>

                    <div className="mt-10 space-y-10">
                        {sections.map((section) => (
                            <section key={section.title}>
                                <h2 className="text-xl font-semibold tracking-tight text-slate-950">
                                    {section.title}
                                </h2>
                                {section.body?.map((para) => (
                                    <p
                                        key={para}
                                        className="mt-3 text-sm leading-6 text-slate-600"
                                    >
                                        {para}
                                    </p>
                                ))}
                                {section.bullets &&
                                    section.bullets.length > 0 && (
                                        <ul className="mt-3 list-disc space-y-1 ps-6 text-sm leading-6 text-slate-600">
                                            {section.bullets.map((b) => (
                                                <li key={b}>{b}</li>
                                            ))}
                                        </ul>
                                    )}
                            </section>
                        ))}

                        <section>
                            <h2 className="text-xl font-semibold tracking-tight text-slate-950">
                                {t('Contact')}
                            </h2>
                            <p className="mt-3 text-sm leading-6 text-slate-600">
                                {t('Questions about these terms? Email')}{' '}
                                <a
                                    href={`mailto:${contact_email}`}
                                    className="text-emerald-700 underline underline-offset-4"
                                >
                                    {contact_email}
                                </a>
                                .
                            </p>
                        </section>
                    </div>

                    <hr className="my-12 border-slate-200" />
                    <p className="text-xs text-slate-500 italic">
                        {t(
                            'Operators deploying :brand: this template is reasonable boilerplate but is not legal advice. Adjust to reflect your jurisdiction, business model, and consult counsel before relying on it in production.',
                            { brand },
                        )}
                    </p>
                </article>
            </MarketingShell>
        </>
    );
}
