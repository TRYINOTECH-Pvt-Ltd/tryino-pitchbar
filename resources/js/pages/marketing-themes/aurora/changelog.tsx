import { MarkdownLite } from '@/components/markdown-lite';
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
    entries: Entry[];
};

export default function AuroraChangelog({ shell, brand, entries }: Props) {
    return (
        <AuroraLayout
            brand={brand}
            navItems={shell.nav_items}
            footer={shell.footer}
            title={`Changelog — ${brand}`}
            description={`Release notes for ${brand} — every shipped change, organised by version.`}
        >
            <section
                className="section"
                data-screen-label="Changelog hero"
                style={{ paddingBottom: 32 }}
            >
                <div className="wrap">
                    <div style={{ maxWidth: 760 }}>
                        <span className="eyebrow">
                            <span className="dot" />
                            <span className="idx">L/01</span>
                            CHANGELOG
                        </span>
                        <h1
                            className="h-display"
                            style={{
                                marginTop: 28,
                                fontSize: 'clamp(40px, 6vw, 88px)',
                            }}
                        >
                            What's <span className="signal-mark">new</span> in{' '}
                            {brand}.
                        </h1>
                        <p
                            className="lead"
                            style={{ marginTop: 22, color: 'var(--muted)' }}
                        >
                            Every shipped change, newest first. Anchor any
                            version with{' '}
                            <code style={{ fontFamily: 'var(--mono)' }}>
                                #vX.Y.Z
                            </code>
                            .
                        </p>
                    </div>
                </div>
            </section>

            <section
                className="section"
                data-screen-label="Changelog body"
                style={{ paddingTop: 0 }}
            >
                <div className="wrap">
                    {entries.length === 0 ? (
                        <p
                            style={{
                                color: 'var(--muted)',
                                fontFamily: 'var(--mono)',
                                fontSize: 12,
                            }}
                        >
                            NO PUBLISHED ENTRIES YET.
                        </p>
                    ) : (
                        <div
                            style={{
                                display: 'flex',
                                flexDirection: 'column',
                                gap: 56,
                                maxWidth: 760,
                            }}
                        >
                            {entries.map((entry) => (
                                <article
                                    key={entry.version}
                                    id={entry.version}
                                    style={{
                                        borderTop: '2px solid var(--ink)',
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
                                                fontFamily: 'var(--mono)',
                                                fontSize: 24,
                                                letterSpacing: '-0.01em',
                                            }}
                                        >
                                            <span
                                                style={{
                                                    background: 'var(--signal)',
                                                    padding: '2px 8px',
                                                }}
                                            >
                                                {entry.version}
                                            </span>
                                        </h2>
                                        {entry.released_at_human && (
                                            <time
                                                style={{
                                                    fontFamily: 'var(--mono)',
                                                    fontSize: 11,
                                                    letterSpacing: '0.08em',
                                                    textTransform: 'uppercase',
                                                    color: 'var(--muted)',
                                                }}
                                                dateTime={
                                                    entry.released_at ??
                                                    undefined
                                                }
                                            >
                                                {entry.released_at_human}
                                            </time>
                                        )}
                                    </header>
                                    <h3
                                        style={{
                                            marginTop: 16,
                                            fontFamily: 'var(--display)',
                                            fontSize: 22,
                                            fontWeight: 500,
                                            letterSpacing: '-0.015em',
                                        }}
                                    >
                                        {entry.title}
                                    </h3>
                                    <div
                                        className="aurora-markdown"
                                        style={{
                                            marginTop: 12,
                                            fontSize: 15.5,
                                            lineHeight: 1.6,
                                            color: 'var(--muted)',
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
        </AuroraLayout>
    );
}
