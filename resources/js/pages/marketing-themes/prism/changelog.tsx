import { MarkdownLite } from '@/components/markdown-lite';

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

type Entry = {
    version: string;
    released_at: string | null;
    released_at_human: string | null;
    title: string;
    body: string;
};

type Props = {
    shell: ShellContent;
    brand: string;
    canRegister?: boolean;
    entries: Entry[];
};

export default function PrismChangelog({
    shell,
    brand,
    canRegister = false,
    entries,
}: Props) {
    return (
        <PrismLayout
            brand={brand}
            navItems={shell.nav_items}
            footer={shell.footer}
            canRegister={canRegister}
            title={`Changelog — ${brand}`}
            description={`Release notes for ${brand} — every shipped change, organised by version.`}
        >
            <section className="hero" data-screen-label="Changelog hero">
                <div className="wrap">
                    <h1>
                        What's <span className="ital">new</span> in {brand}.
                    </h1>
                    <p className="hero-sub">
                        Every shipped change, newest first. Anchor any version
                        with{' '}
                        <code
                            style={{
                                fontFamily: 'ui-monospace, Menlo, monospace',
                            }}
                        >
                            #vX.Y.Z
                        </code>
                        .
                    </p>
                </div>
            </section>

            <section
                className="sec"
                style={{ paddingTop: 0 }}
                data-screen-label="Changelog body"
            >
                <div className="wrap">
                    {entries.length === 0 ? (
                        <p
                            style={{
                                color: 'var(--muted)',
                                fontFamily: 'ui-monospace, Menlo, monospace',
                                fontSize: 13,
                                letterSpacing: '0.08em',
                                textTransform: 'uppercase',
                            }}
                        >
                            No published entries yet.
                        </p>
                    ) : (
                        <div
                            style={{
                                display: 'flex',
                                flexDirection: 'column',
                                gap: 56,
                                maxWidth: 760,
                                margin: '0 auto',
                            }}
                        >
                            {entries.map((entry) => (
                                <article
                                    key={entry.version}
                                    id={entry.version}
                                    style={{
                                        borderTop: '1px solid var(--line)',
                                        paddingTop: 28,
                                    }}
                                >
                                    <header
                                        style={{
                                            display: 'flex',
                                            alignItems: 'baseline',
                                            justifyContent: 'space-between',
                                            gap: 12,
                                            flexWrap: 'wrap',
                                        }}
                                    >
                                        <h2
                                            style={{
                                                fontFamily:
                                                    'ui-monospace, Menlo, monospace',
                                                fontSize: 18,
                                                fontWeight: 700,
                                                letterSpacing: '-0.01em',
                                            }}
                                        >
                                            <span
                                                style={{
                                                    background:
                                                        'var(--grad-soft)',
                                                    color: 'var(--accent-ink)',
                                                    padding: '4px 10px',
                                                    borderRadius: 8,
                                                }}
                                            >
                                                {entry.version}
                                            </span>
                                        </h2>
                                        {entry.released_at_human ? (
                                            <time
                                                style={{
                                                    fontSize: 11,
                                                    letterSpacing: '0.08em',
                                                    textTransform: 'uppercase',
                                                    color: 'var(--muted)',
                                                    fontWeight: 600,
                                                }}
                                                dateTime={
                                                    entry.released_at ??
                                                    undefined
                                                }
                                            >
                                                {entry.released_at_human}
                                            </time>
                                        ) : null}
                                    </header>
                                    <h3
                                        style={{
                                            marginTop: 16,
                                            fontFamily: 'var(--sans)',
                                            fontSize: 22,
                                            fontWeight: 600,
                                            letterSpacing: '-0.015em',
                                            lineHeight: 1.25,
                                        }}
                                    >
                                        {entry.title}
                                    </h3>
                                    <div
                                        className="page-content"
                                        style={{
                                            marginTop: 12,
                                            fontSize: 15.5,
                                            lineHeight: 1.6,
                                            color: 'var(--fg-2)',
                                        }}
                                    >
                                        <MarkdownLite
                                            markdown={entry.body}
                                            variant="changelog"
                                        />
                                    </div>
                                </article>
                            ))}
                        </div>
                    )}
                </div>
            </section>
        </PrismLayout>
    );
}
