import { Head } from '@inertiajs/react';
import { MarketingShell } from '@/layouts/marketing-shell';
import type { MarketingShellContent } from '@/layouts/marketing-shell';
import { useT } from '@/lib/i18n';

type ListSection = {
    summary?: string;
    items?: string[];
};

type PrivacyContent = {
    eyebrow?: string;
    title?: string;
    summary?: string;
    effective_date?: string;
    contact?: { team_name?: string; email?: string; response_sla?: string };
    collection?: ListSection;
    usage?: ListSection;
    retention?: ListSection;
    rights?: ListSection;
    gdpr?: {
        summary?: string;
        request_email?: string;
        request_instructions?: string;
    };
};

type Props = {
    shell: MarketingShellContent;
    brand: string;
    content: PrivacyContent;
};

function Section({
    title,
    section,
}: {
    title: string;
    section: ListSection | undefined;
}) {
    if (!section) {
        return null;
    }

    return (
        <section className="mt-10">
            <h2 className="text-xl font-semibold tracking-tight text-slate-950">
                {title}
            </h2>
            {section.summary && (
                <p className="mt-2 text-sm leading-6 text-slate-600">
                    {section.summary}
                </p>
            )}
            {section.items && section.items.length > 0 && (
                <ul className="mt-3 list-disc space-y-1 ps-6 text-sm leading-6 text-slate-600">
                    {section.items.map((item) => (
                        <li key={item}>{item}</li>
                    ))}
                </ul>
            )}
        </section>
    );
}

export default function Privacy({ shell, brand, content }: Props) {
    const { t } = useT();
    const title = content.title ?? t('Privacy policy');

    return (
        <>
            <Head title={`${title} — ${brand}`} />

            <MarketingShell content={shell}>
                <article className="mx-auto max-w-3xl px-6 pt-16 pb-20">
                    {content.eyebrow && (
                        <p className="text-sm font-medium tracking-wider text-emerald-700 uppercase">
                            {content.eyebrow}
                        </p>
                    )}
                    <h1 className="mt-4 text-4xl font-bold tracking-tight text-slate-950 sm:text-5xl">
                        {title}
                    </h1>
                    {content.summary && (
                        <p className="mt-5 text-base leading-7 text-slate-600 sm:text-lg">
                            {content.summary}
                        </p>
                    )}
                    {content.effective_date && (
                        <p className="mt-2 text-sm text-slate-500">
                            <strong className="text-slate-700">
                                {t('Effective date:')}
                            </strong>{' '}
                            {content.effective_date}
                        </p>
                    )}

                    {content.contact && (
                        <section className="mt-10">
                            <h2 className="text-xl font-semibold tracking-tight text-slate-950">
                                {t('Contact')}
                            </h2>
                            <p className="mt-2 text-sm leading-6 text-slate-600">
                                {content.contact.team_name}
                                {content.contact.email && (
                                    <>
                                        <br />
                                        <a
                                            href={`mailto:${content.contact.email}`}
                                            className="text-emerald-700 underline underline-offset-4"
                                        >
                                            {content.contact.email}
                                        </a>
                                    </>
                                )}
                                {content.contact.response_sla && (
                                    <>
                                        <br />
                                        {content.contact.response_sla}
                                    </>
                                )}
                            </p>
                        </section>
                    )}

                    <Section
                        title={t('What we collect')}
                        section={content.collection}
                    />
                    <Section
                        title={t('How we use data')}
                        section={content.usage}
                    />
                    <Section
                        title={t('Retention')}
                        section={content.retention}
                    />
                    <Section
                        title={t('Your rights')}
                        section={content.rights}
                    />

                    {content.gdpr && (
                        <section className="mt-10">
                            <h2 className="text-xl font-semibold tracking-tight text-slate-950">
                                {t('Data export & deletion (GDPR)')}
                            </h2>
                            {content.gdpr.summary && (
                                <p className="mt-2 text-sm leading-6 text-slate-600">
                                    {content.gdpr.summary}
                                </p>
                            )}
                            {content.gdpr.request_email && (
                                <p className="mt-2 text-sm text-slate-600">
                                    <strong className="text-slate-700">
                                        {t('Request contact:')}
                                    </strong>{' '}
                                    <a
                                        href={`mailto:${content.gdpr.request_email}`}
                                        className="text-emerald-700 underline underline-offset-4"
                                    >
                                        {content.gdpr.request_email}
                                    </a>
                                </p>
                            )}
                            {content.gdpr.request_instructions && (
                                <p className="mt-2 text-sm leading-6 text-slate-600">
                                    {content.gdpr.request_instructions}
                                </p>
                            )}
                        </section>
                    )}
                </article>
            </MarketingShell>
        </>
    );
}
