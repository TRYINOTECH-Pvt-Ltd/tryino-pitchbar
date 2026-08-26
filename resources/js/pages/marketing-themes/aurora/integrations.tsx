import { Link } from '@inertiajs/react';
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

type Native = {
    name: string;
    category: string;
    tagline: string;
    description: string;
    icon: string;
    accent: 'slate' | 'sky' | 'violet' | 'indigo' | 'emerald' | 'amber';
};

type DataSource = { name: string; tagline: string; icon: string };

type RoadmapItem = { name: string; tagline: string };

type Props = {
    shell: ShellContent;
    brand: string;
    native: Native[];
    data_sources: DataSource[];
    roadmap: RoadmapItem[];
};

function code(name: string): string {
    return (
        name
            .split(/\s+/)
            .slice(0, 2)
            .map((p) => p[0])
            .join('')
            .toUpperCase()
            .slice(0, 3) || 'IN'
    );
}

export default function AuroraIntegrations({
    shell,
    brand,
    native,
    data_sources,
    roadmap,
}: Props) {
    return (
        <AuroraLayout
            brand={brand}
            navItems={shell.nav_items}
            footer={shell.footer}
            title={`Integrations — ${brand}`}
            description={`Plug ${brand} into the tools your team already uses — Notion, Google Docs, Slack, Stripe, webhooks, and more.`}
        >
            <section
                className="section"
                data-screen-label="INT hero"
                style={{ paddingBottom: 32 }}
            >
                <div className="wrap">
                    <div style={{ maxWidth: 900 }}>
                        <span className="eyebrow">
                            <span className="dot" />
                            <span className="idx">I/01</span>
                            INTEGRATIONS
                        </span>
                        <h1
                            className="h-display"
                            style={{
                                marginTop: 28,
                                fontSize: 'clamp(40px, 6vw, 96px)',
                            }}
                        >
                            Plug {brand} into the{' '}
                            <span className="signal-mark">
                                tools you already use
                            </span>
                            .
                        </h1>
                        <p
                            className="lead"
                            style={{ marginTop: 22, color: 'var(--muted)' }}
                        >
                            Native sync where it matters, signed webhooks
                            everywhere else. Bring your knowledge in. Push leads
                            out. Capture human escalations without leaving
                            Slack.
                        </p>
                    </div>
                </div>
            </section>

            <section className="dark-section" data-screen-label="INT native">
                <div className="wrap">
                    <div className="feat-head">
                        <div>
                            <span className="eyebrow">
                                <span className="dot" />
                                <span className="idx">I/02</span>
                                NATIVE INTEGRATIONS
                            </span>
                            <h2 className="h-section">
                                Ship-ready connectors, no glue code.
                            </h2>
                        </div>
                    </div>
                    <div className="features">
                        {native.map((item) => (
                            <div key={item.name} className="feat">
                                <div className="feat-icon">
                                    {code(item.name)}
                                </div>
                                <h3>{item.name}</h3>
                                <p>
                                    <strong style={{ color: 'var(--paper)' }}>
                                        {item.tagline}
                                    </strong>
                                    <br />
                                    {item.description}
                                </p>
                                <div className="meta">
                                    <span>{item.category.toUpperCase()}</span>
                                    <span className="live">LIVE</span>
                                </div>
                            </div>
                        ))}
                    </div>
                </div>
            </section>

            <section className="section" data-screen-label="INT sources">
                <div className="wrap">
                    <div className="tune">
                        <div className="tune-text">
                            <span className="eyebrow">
                                <span className="dot" />
                                <span className="idx">I/03</span>
                                KNOWLEDGE IN
                            </span>
                            <h2 className="h-section" style={{ marginTop: 24 }}>
                                Five ways to feed the agent.
                            </h2>
                            <p className="lead">
                                Mix and match — most teams start with a sitemap
                                crawl, paste a few FAQs, and turn on auto-index
                                for the long tail.
                            </p>
                        </div>
                        <div>
                            <div className="settings">
                                <div className="settings-head">
                                    <span>SOURCE TYPES</span>
                                    <span className="right">
                                        {brand.toUpperCase()} / INGEST
                                    </span>
                                </div>
                                {data_sources.map((src) => (
                                    <div key={src.name} className="field">
                                        <div className="label">
                                            {src.name}
                                            <span className="sub">
                                                {src.tagline}
                                            </span>
                                        </div>
                                        <div className="control">
                                            <span
                                                style={{
                                                    fontFamily: 'var(--mono)',
                                                }}
                                            >
                                                {code(src.name)}
                                            </span>
                                            <span className="caret">+</span>
                                        </div>
                                    </div>
                                ))}
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <section
                className="section"
                data-screen-label="INT roadmap"
                style={{ paddingTop: 0 }}
            >
                <div className="wrap">
                    <div className="pages-head">
                        <div>
                            <span className="eyebrow">
                                <span className="dot" />
                                <span className="idx">I/04</span>
                                ON THE ROADMAP
                            </span>
                            <h2 className="h-section">Coming soon.</h2>
                        </div>
                        <p
                            className="lead"
                            style={{ color: 'var(--muted)', fontSize: 15 }}
                        >
                            Until these ship natively, the outgoing webhook + a
                            Zapier catch-hook covers every CRM in your stack.
                        </p>
                    </div>
                    <div className="pages-grid">
                        {roadmap.map((item, idx) => (
                            <div key={item.name} className="page-card">
                                <div className="page-num">
                                    {`0${idx + 1}`} / {item.name.toUpperCase()}
                                </div>
                                <h3 className="h-card">{item.name}</h3>
                                <p>{item.tagline}</p>
                                <div className="page-arrow">→</div>
                            </div>
                        ))}
                    </div>
                </div>
            </section>

            <section className="cta" data-screen-label="INT CTA">
                <div className="wrap">
                    <div className="cta-grid">
                        <div>
                            <span
                                className="eyebrow"
                                style={{ color: 'var(--paper)' }}
                            >
                                <span className="dot" />
                                <span
                                    className="idx"
                                    style={{ color: 'rgba(255,255,255,0.5)' }}
                                >
                                    I/05
                                </span>
                                READY?
                            </span>
                            <h2 style={{ marginTop: 24 }}>
                                Ready to{' '}
                                <span className="signal-mark">plug it in?</span>
                            </h2>
                            <p>
                                Free to start. Connect Notion, Slack, and your
                                first webhook in under 5 minutes.
                            </p>
                        </div>
                        <div className="cta-actions">
                            <Link href="/register" className="btn-big primary">
                                <span>Get started free</span>
                                <span>→</span>
                            </Link>
                            <a
                                href="/documentation/integrations"
                                target="_blank"
                                rel="noopener noreferrer"
                                className="btn-big"
                            >
                                <span>Read integration docs</span>
                                <span>↗</span>
                            </a>
                        </div>
                    </div>
                </div>
            </section>
        </AuroraLayout>
    );
}
