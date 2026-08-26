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

type Section = {
    title: string;
    body?: string[];
    bullets?: string[];
};

type Props = {
    shell: ShellContent;
    brand: string;
    effective_date: string;
    contact_email: string;
    intro: string;
    sections: Section[];
};

export default function AuroraTerms({
    shell,
    brand,
    effective_date,
    contact_email,
    intro,
    sections,
}: Props) {
    return (
        <AuroraLayout
            brand={brand}
            navItems={shell.nav_items}
            footer={shell.footer}
            title={`Terms of service — ${brand}`}
            description={intro}
        >
            <section className="section" data-screen-label="Terms">
                <div className="wrap">
                    <article style={{ maxWidth: 760 }}>
                        <span className="eyebrow">
                            <span className="dot" />
                            <span className="idx">T/01</span>
                            LEGAL
                        </span>
                        <h1
                            className="h-display"
                            style={{
                                marginTop: 28,
                                fontSize: 'clamp(40px, 6vw, 88px)',
                            }}
                        >
                            Terms of{' '}
                            <span className="signal-mark">service</span>.
                        </h1>
                        <p
                            className="lead"
                            style={{ marginTop: 22, color: 'var(--muted)' }}
                        >
                            {intro}
                        </p>
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
                            Effective date: {effective_date}
                        </p>

                        <div style={{ marginTop: 40 }}>
                            {sections.map((section, idx) => (
                                <section
                                    key={section.title}
                                    style={{
                                        marginTop: idx === 0 ? 0 : 40,
                                        paddingTop: 28,
                                        borderTop: 'var(--hair)',
                                    }}
                                >
                                    <span className="eyebrow">
                                        <span className="dot" />
                                        <span className="idx">
                                            T/{String(idx + 2).padStart(2, '0')}
                                        </span>
                                        SECTION
                                    </span>
                                    <h2
                                        className="h-section"
                                        style={{
                                            marginTop: 14,
                                            fontSize: 'clamp(26px, 3vw, 36px)',
                                        }}
                                    >
                                        {section.title}
                                    </h2>
                                    {section.body?.map((para) => (
                                        <p
                                            key={para}
                                            style={{
                                                marginTop: 14,
                                                fontSize: 16,
                                                lineHeight: 1.6,
                                                color: 'var(--muted)',
                                            }}
                                        >
                                            {para}
                                        </p>
                                    ))}
                                    {section.bullets &&
                                        section.bullets.length > 0 && (
                                            <ul
                                                className="bullets"
                                                style={{ marginTop: 14 }}
                                            >
                                                {section.bullets.map((b) => (
                                                    <li key={b}>
                                                        <span className="pip" />
                                                        <span>{b}</span>
                                                    </li>
                                                ))}
                                            </ul>
                                        )}
                                </section>
                            ))}

                            <section
                                style={{
                                    marginTop: 40,
                                    paddingTop: 28,
                                    borderTop: 'var(--hair)',
                                }}
                            >
                                <span className="eyebrow">
                                    <span className="dot" />
                                    <span className="idx">T/99</span>
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
                                    Questions about these terms? Email{' '}
                                    <a
                                        href={`mailto:${contact_email}`}
                                        style={{
                                            background: 'var(--signal)',
                                            padding: '2px 6px',
                                        }}
                                    >
                                        {contact_email}
                                    </a>
                                    .
                                </p>
                            </section>
                        </div>

                        <hr
                            style={{
                                marginTop: 56,
                                border: 0,
                                borderTop: '2px solid var(--ink)',
                            }}
                        />
                        <p
                            style={{
                                marginTop: 24,
                                fontStyle: 'italic',
                                fontSize: 13,
                                color: 'var(--muted)',
                            }}
                        >
                            Operators deploying {brand}: this template is
                            reasonable boilerplate but is not legal advice.
                            Adjust to reflect your jurisdiction, business model,
                            and consult counsel before relying on it in
                            production.
                        </p>
                    </article>
                </div>
            </section>
        </AuroraLayout>
    );
}
