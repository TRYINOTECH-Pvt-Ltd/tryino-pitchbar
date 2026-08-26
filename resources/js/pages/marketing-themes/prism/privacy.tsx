import PrismLayout from './_layout';

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
    canRegister?: boolean;
    content: PrivacyContent;
};

function ListBlock({
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
        <section
            style={{
                marginTop: 40,
                borderTop: '1px solid var(--line)',
                paddingTop: 28,
            }}
        >
            <span className="eyebrow">{title}</span>
            <h2
                style={{
                    marginTop: 14,
                    fontFamily: 'var(--sans)',
                    fontWeight: 600,
                    fontSize: 'clamp(22px, 3vw, 32px)',
                    letterSpacing: '-0.02em',
                    lineHeight: 1.15,
                }}
            >
                {title}
            </h2>
            {section.summary ? (
                <p
                    style={{
                        marginTop: 12,
                        color: 'var(--muted)',
                        lineHeight: 1.6,
                    }}
                >
                    {section.summary}
                </p>
            ) : null}
            {section.items && section.items.length > 0 ? (
                <ul
                    style={{
                        listStyle: 'none',
                        padding: 0,
                        margin: '14px 0 0',
                        display: 'flex',
                        flexDirection: 'column',
                        gap: 10,
                    }}
                >
                    {section.items.map((item) => (
                        <li
                            key={item}
                            style={{
                                display: 'flex',
                                gap: 12,
                                alignItems: 'flex-start',
                                color: 'var(--fg-2)',
                                lineHeight: 1.55,
                            }}
                        >
                            <span
                                aria-hidden="true"
                                style={{
                                    width: 6,
                                    height: 6,
                                    borderRadius: '50%',
                                    background: 'var(--accent)',
                                    marginTop: 8,
                                    flex: '0 0 auto',
                                }}
                            />
                            <span>{item}</span>
                        </li>
                    ))}
                </ul>
            ) : null}
        </section>
    );
}

export default function PrismPrivacy({
    shell,
    brand,
    canRegister = false,
    content,
}: Props) {
    const title = content.title ?? 'Privacy policy';

    return (
        <PrismLayout
            brand={brand}
            navItems={shell.nav_items}
            footer={shell.footer}
            canRegister={canRegister}
            title={`${title} — ${brand}`}
            description={content.summary}
        >
            <section className="sec" data-screen-label="Privacy">
                <div className="wrap">
                    <article style={{ maxWidth: 760, margin: '0 auto' }}>
                        <span className="eyebrow">
                            {(content.eyebrow ?? 'Legal').toString()}
                        </span>
                        <h1
                            style={{
                                marginTop: 18,
                                fontFamily: 'var(--sans)',
                                fontWeight: 600,
                                fontSize: 'clamp(34px, 6vw, 72px)',
                                lineHeight: 1.05,
                                letterSpacing: '-0.03em',
                            }}
                        >
                            {title.split(' ')[0]}{' '}
                            <span className="ital">
                                {title.split(' ').slice(1).join(' ') ||
                                    'policy'}
                            </span>
                        </h1>
                        {content.summary ? (
                            <p
                                style={{
                                    marginTop: 18,
                                    color: 'var(--muted)',
                                    fontSize: 16,
                                    lineHeight: 1.6,
                                }}
                            >
                                {content.summary}
                            </p>
                        ) : null}
                        {content.effective_date ? (
                            <p
                                style={{
                                    marginTop: 16,
                                    fontSize: 12,
                                    letterSpacing: '0.08em',
                                    textTransform: 'uppercase',
                                    color: 'var(--muted)',
                                    fontWeight: 600,
                                }}
                            >
                                Effective date: {content.effective_date}
                            </p>
                        ) : null}

                        {content.contact ? (
                            <section
                                style={{
                                    marginTop: 40,
                                    borderTop: '1px solid var(--line)',
                                    paddingTop: 28,
                                }}
                            >
                                <span className="eyebrow">Contact</span>
                                <p
                                    style={{
                                        marginTop: 14,
                                        color: 'var(--fg-2)',
                                        lineHeight: 1.6,
                                    }}
                                >
                                    {content.contact.team_name}
                                    {content.contact.email ? (
                                        <>
                                            <br />
                                            <a
                                                href={`mailto:${content.contact.email}`}
                                                style={{
                                                    color: 'var(--accent-ink)',
                                                    borderBottom:
                                                        '2px solid var(--accent)',
                                                    paddingBottom: 1,
                                                }}
                                            >
                                                {content.contact.email}
                                            </a>
                                        </>
                                    ) : null}
                                    {content.contact.response_sla ? (
                                        <>
                                            <br />
                                            {content.contact.response_sla}
                                        </>
                                    ) : null}
                                </p>
                            </section>
                        ) : null}

                        <ListBlock
                            title="What we collect"
                            section={content.collection}
                        />
                        <ListBlock
                            title="How we use data"
                            section={content.usage}
                        />
                        <ListBlock
                            title="Retention"
                            section={content.retention}
                        />
                        <ListBlock
                            title="Your rights"
                            section={content.rights}
                        />

                        {content.gdpr ? (
                            <section
                                style={{
                                    marginTop: 40,
                                    borderTop: '1px solid var(--line)',
                                    paddingTop: 28,
                                }}
                            >
                                <span className="eyebrow">
                                    Data export & deletion (GDPR)
                                </span>
                                {content.gdpr.summary ? (
                                    <p
                                        style={{
                                            marginTop: 14,
                                            color: 'var(--muted)',
                                            lineHeight: 1.6,
                                        }}
                                    >
                                        {content.gdpr.summary}
                                    </p>
                                ) : null}
                                {content.gdpr.request_email ? (
                                    <p style={{ marginTop: 12 }}>
                                        Request contact:{' '}
                                        <a
                                            href={`mailto:${content.gdpr.request_email}`}
                                            style={{
                                                background: 'var(--grad-soft)',
                                                color: 'var(--accent-ink)',
                                                padding: '2px 8px',
                                                borderRadius: 6,
                                                fontWeight: 600,
                                            }}
                                        >
                                            {content.gdpr.request_email}
                                        </a>
                                    </p>
                                ) : null}
                                {content.gdpr.request_instructions ? (
                                    <p
                                        style={{
                                            marginTop: 12,
                                            color: 'var(--muted)',
                                            lineHeight: 1.6,
                                        }}
                                    >
                                        {content.gdpr.request_instructions}
                                    </p>
                                ) : null}
                            </section>
                        ) : null}
                    </article>
                </div>
            </section>
        </PrismLayout>
    );
}
