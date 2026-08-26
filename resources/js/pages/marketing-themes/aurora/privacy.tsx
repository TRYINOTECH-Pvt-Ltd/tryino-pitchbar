import AuroraLayout from './_layout';

type ContentLink = { label: string; href: string };

type ShellContent = {
    nav_items: ContentLink[];
    footer: {
        brand_description: string;
        socials: ContentLink[];
        groups: Array<{ title: string; links: ContentLink[] }>;
        legal_title: string;
        legal_links: ContentLink[];
        copyright: string;
    };
};

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
    shell: ShellContent;
    brand: string;
    content: PrivacyContent;
};

function Section({
    title,
    section,
    index,
}: {
    title: string;
    section: ListSection | undefined;
    index: string;
}) {
    if (!section) {
        return null;
    }

    return (
        <section
            style={{ marginTop: 56, borderTop: 'var(--hair)', paddingTop: 28 }}
        >
            <span className="eyebrow">
                <span className="dot" />
                <span className="idx">{index}</span>
                {title.toUpperCase()}
            </span>
            <h2
                className="h-section"
                style={{ marginTop: 14, fontSize: 'clamp(28px, 3vw, 38px)' }}
            >
                {title}
            </h2>
            {section.summary && (
                <p
                    style={{
                        marginTop: 12,
                        fontSize: 16,
                        lineHeight: 1.6,
                        color: 'var(--muted)',
                    }}
                >
                    {section.summary}
                </p>
            )}
            {section.items && section.items.length > 0 && (
                <ul className="bullets" style={{ marginTop: 14 }}>
                    {section.items.map((item) => (
                        <li key={item}>
                            <span className="pip" />
                            <span>{item}</span>
                        </li>
                    ))}
                </ul>
            )}
        </section>
    );
}

export default function AuroraPrivacy({ shell, brand, content }: Props) {
    const title = content.title ?? 'Privacy policy';

    return (
        <AuroraLayout
            brand={brand}
            navItems={shell.nav_items}
            footer={shell.footer}
            title={`${title} — ${brand}`}
            description={content.summary}
        >
            <section className="section" data-screen-label="Privacy">
                <div className="wrap">
                    <article style={{ maxWidth: 760 }}>
                        <span className="eyebrow">
                            <span className="dot" />
                            <span className="idx">P/01</span>
                            {(content.eyebrow ?? 'Legal').toUpperCase()}
                        </span>
                        <h1
                            className="h-display"
                            style={{
                                marginTop: 28,
                                fontSize: 'clamp(40px, 6vw, 88px)',
                            }}
                        >
                            {title.split(' ')[0]}{' '}
                            <span className="signal-mark">
                                {title.split(' ').slice(1).join(' ') ||
                                    'policy'}
                            </span>
                        </h1>
                        {content.summary && (
                            <p
                                className="lead"
                                style={{ marginTop: 22, color: 'var(--muted)' }}
                            >
                                {content.summary}
                            </p>
                        )}
                        {content.effective_date && (
                            <p
                                style={{
                                    marginTop: 16,
                                    fontFamily: 'var(--mono)',
                                    fontSize: 12,
                                    letterSpacing: '0.06em',
                                    textTransform: 'uppercase',
                                    color: 'var(--muted)',
                                }}
                            >
                                Effective date: {content.effective_date}
                            </p>
                        )}

                        {content.contact && (
                            <section
                                style={{
                                    marginTop: 56,
                                    borderTop: 'var(--hair)',
                                    paddingTop: 28,
                                }}
                            >
                                <span className="eyebrow">
                                    <span className="dot" />
                                    <span className="idx">P/02</span>
                                    CONTACT
                                </span>
                                <p
                                    style={{
                                        marginTop: 14,
                                        fontSize: 16,
                                        lineHeight: 1.6,
                                        color: 'var(--muted)',
                                    }}
                                >
                                    {content.contact.team_name}
                                    {content.contact.email && (
                                        <>
                                            <br />
                                            <a
                                                href={`mailto:${content.contact.email}`}
                                                style={{
                                                    borderBottom:
                                                        '2px solid var(--signal)',
                                                    paddingBottom: 2,
                                                }}
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
                            title="What we collect"
                            section={content.collection}
                            index="P/03"
                        />
                        <Section
                            title="How we use data"
                            section={content.usage}
                            index="P/04"
                        />
                        <Section
                            title="Retention"
                            section={content.retention}
                            index="P/05"
                        />
                        <Section
                            title="Your rights"
                            section={content.rights}
                            index="P/06"
                        />

                        {content.gdpr && (
                            <section
                                style={{
                                    marginTop: 56,
                                    borderTop: 'var(--hair)',
                                    paddingTop: 28,
                                }}
                            >
                                <span className="eyebrow">
                                    <span className="dot" />
                                    <span className="idx">P/07</span>
                                    DATA EXPORT &amp; DELETION (GDPR)
                                </span>
                                {content.gdpr.summary && (
                                    <p
                                        style={{
                                            marginTop: 14,
                                            fontSize: 16,
                                            lineHeight: 1.6,
                                            color: 'var(--muted)',
                                        }}
                                    >
                                        {content.gdpr.summary}
                                    </p>
                                )}
                                {content.gdpr.request_email && (
                                    <p
                                        style={{
                                            marginTop: 12,
                                            fontFamily: 'var(--mono)',
                                            fontSize: 13,
                                        }}
                                    >
                                        Request contact:{' '}
                                        <a
                                            href={`mailto:${content.gdpr.request_email}`}
                                            style={{
                                                background: 'var(--signal)',
                                                padding: '2px 6px',
                                            }}
                                        >
                                            {content.gdpr.request_email}
                                        </a>
                                    </p>
                                )}
                                {content.gdpr.request_instructions && (
                                    <p
                                        style={{
                                            marginTop: 12,
                                            fontSize: 15,
                                            lineHeight: 1.6,
                                            color: 'var(--muted)',
                                        }}
                                    >
                                        {content.gdpr.request_instructions}
                                    </p>
                                )}
                            </section>
                        )}
                    </article>
                </div>
            </section>
        </AuroraLayout>
    );
}
