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

type Section = {
    title: string;
    body?: string[];
    bullets?: string[];
};

type Props = {
    shell: ShellContent;
    brand: string;
    canRegister?: boolean;
    effective_date: string;
    contact_email: string;
    intro: string;
    sections: Section[];
};

export default function PrismTerms({
    shell,
    brand,
    canRegister = false,
    effective_date,
    contact_email,
    intro,
    sections,
}: Props) {
    return (
        <PrismLayout
            brand={brand}
            navItems={shell.nav_items}
            footer={shell.footer}
            canRegister={canRegister}
            title={`Terms of service — ${brand}`}
            description={intro}
        >
            <section className="sec" data-screen-label="Terms">
                <div className="wrap">
                    <article style={{ maxWidth: 760, margin: '0 auto' }}>
                        <span className="eyebrow">Legal</span>
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
                            Terms of <span className="ital">service</span>.
                        </h1>
                        <p
                            style={{
                                marginTop: 18,
                                color: 'var(--muted)',
                                lineHeight: 1.6,
                                fontSize: 16,
                            }}
                        >
                            {intro}
                        </p>
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
                            Effective date: {effective_date}
                        </p>

                        <div style={{ marginTop: 40 }}>
                            {sections.map((section, idx) => (
                                <section
                                    key={section.title}
                                    style={{
                                        marginTop: idx === 0 ? 0 : 40,
                                        paddingTop: 28,
                                        borderTop: '1px solid var(--line)',
                                    }}
                                >
                                    <span className="eyebrow">{`Section ${idx + 1}`}</span>
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
                                        {section.title}
                                    </h2>
                                    {section.body?.map((para) => (
                                        <p
                                            key={para}
                                            style={{
                                                marginTop: 14,
                                                color: 'var(--muted)',
                                                lineHeight: 1.6,
                                            }}
                                        >
                                            {para}
                                        </p>
                                    ))}
                                    {section.bullets &&
                                    section.bullets.length > 0 ? (
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
                                            {section.bullets.map((b) => (
                                                <li
                                                    key={b}
                                                    style={{
                                                        display: 'flex',
                                                        gap: 12,
                                                        alignItems:
                                                            'flex-start',
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
                                                            background:
                                                                'var(--accent)',
                                                            marginTop: 8,
                                                            flex: '0 0 auto',
                                                        }}
                                                    />
                                                    <span>{b}</span>
                                                </li>
                                            ))}
                                        </ul>
                                    ) : null}
                                </section>
                            ))}

                            <section
                                style={{
                                    marginTop: 40,
                                    paddingTop: 28,
                                    borderTop: '1px solid var(--line)',
                                }}
                            >
                                <span className="eyebrow">Contact</span>
                                <p
                                    style={{
                                        marginTop: 14,
                                        color: 'var(--muted)',
                                        lineHeight: 1.6,
                                    }}
                                >
                                    Questions about these terms? Email{' '}
                                    <a
                                        href={`mailto:${contact_email}`}
                                        style={{
                                            background: 'var(--grad-soft)',
                                            color: 'var(--accent-ink)',
                                            padding: '2px 8px',
                                            borderRadius: 6,
                                            fontWeight: 600,
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
                                borderTop: '1px solid var(--line)',
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
        </PrismLayout>
    );
}
