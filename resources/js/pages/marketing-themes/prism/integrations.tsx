import { Link } from '@inertiajs/react';

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

type Native = {
    name: string;
    category: string;
    tagline: string;
    description: string;
    icon: string;
};

type DataSource = { name: string; tagline: string; icon: string };

type RoadmapItem = { name: string; tagline: string };

type Props = {
    shell: ShellContent;
    brand: string;
    canRegister?: boolean;
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

export default function PrismIntegrations({
    shell,
    brand,
    canRegister = false,
    native,
    data_sources,
    roadmap,
}: Props) {
    return (
        <PrismLayout
            brand={brand}
            navItems={shell.nav_items}
            footer={shell.footer}
            canRegister={canRegister}
            title={`Integrations — ${brand}`}
            description={`Plug ${brand} into the tools your team already uses — Notion, Google Docs, Slack, Stripe, webhooks, and more.`}
        >
            <section className="hero" data-screen-label="INT hero">
                <div className="wrap">
                    <h1>
                        Plug {brand} into the{' '}
                        <span className="ital">tools you already use</span>.
                    </h1>
                    <p className="hero-sub">
                        Native sync where it matters, signed webhooks everywhere
                        else. Bring your knowledge in. Push leads out. Capture
                        human escalations without leaving Slack.
                    </p>
                </div>
            </section>

            <section
                className="sec"
                style={{ paddingTop: 0 }}
                data-screen-label="INT native"
            >
                <div className="wrap">
                    <div className="sec-hd">
                        <div>
                            <span className="eyebrow">Native integrations</span>
                            <h2>Ship-ready connectors, no glue code.</h2>
                        </div>
                    </div>
                    <div className="features">
                        {native.map((item) => (
                            <article className="feat" key={item.name}>
                                <div className="ic">
                                    <span
                                        style={{
                                            fontSize: 12,
                                            fontWeight: 700,
                                        }}
                                    >
                                        {code(item.name)}
                                    </span>
                                </div>
                                <h4>{item.name}</h4>
                                <p>
                                    <strong style={{ color: 'var(--fg)' }}>
                                        {item.tagline}
                                    </strong>
                                    <br />
                                    {item.description}
                                </p>
                                <div className="tag">
                                    {item.category.toUpperCase()} · LIVE
                                </div>
                            </article>
                        ))}
                    </div>
                </div>
            </section>

            <section
                className="sec"
                style={{ paddingTop: 0 }}
                data-screen-label="INT sources"
            >
                <div className="wrap">
                    <div className="sec-hd">
                        <div>
                            <span className="eyebrow">Knowledge in</span>
                            <h2>
                                Five ways to <span className="ital">feed</span>{' '}
                                the agent.
                            </h2>
                        </div>
                    </div>
                    <div className="tune-grid">
                        <div className="tune-left">
                            <h4>
                                Mix and match — most teams start with a{' '}
                                <span className="ital">sitemap crawl</span>,
                                paste a few FAQs, then enable auto-index for the
                                long tail.
                            </h4>
                            <p>
                                Every source type ships with idempotent ingest,
                                dedup, and automatic re-crawl on a schedule you
                                control.
                            </p>
                        </div>
                        <div className="tune-right">
                            <div className="settings">
                                <div className="settings-hd">
                                    <h5>SOURCE TYPES</h5>
                                    <span className="badge">/ ingest</span>
                                </div>
                                {data_sources.map((src) => (
                                    <div
                                        className="settings-row"
                                        key={src.name}
                                    >
                                        <div>
                                            <div className="lbl">
                                                {src.name}
                                            </div>
                                            <div className="hint">
                                                {src.tagline}
                                            </div>
                                        </div>
                                        <div
                                            className="field"
                                            style={{
                                                fontFamily:
                                                    'ui-monospace, Menlo, monospace',
                                            }}
                                        >
                                            {code(src.name)}
                                        </div>
                                    </div>
                                ))}
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <section
                className="sec"
                style={{ paddingTop: 0 }}
                data-screen-label="INT roadmap"
            >
                <div className="wrap">
                    <div className="sec-hd">
                        <div>
                            <span className="eyebrow">On the roadmap</span>
                            <h2>Coming soon.</h2>
                        </div>
                        <p
                            style={{
                                color: 'var(--muted)',
                                fontSize: 14.5,
                                maxWidth: 36 + 'ch',
                            }}
                        >
                            Until these ship natively, the outgoing webhook + a
                            Zapier catch-hook covers every CRM in your stack.
                        </p>
                    </div>
                    <div className="uc-grid">
                        {roadmap.map((item) => (
                            <article className="uc" key={item.name}>
                                <div className="uc-ic">
                                    <span
                                        style={{
                                            fontSize: 11,
                                            fontWeight: 700,
                                        }}
                                    >
                                        {code(item.name)}
                                    </span>
                                </div>
                                <h3>{item.name}</h3>
                                <p>{item.tagline}</p>
                            </article>
                        ))}
                    </div>
                </div>
            </section>

            <section className="wrap" data-screen-label="INT CTA">
                <div className="final">
                    <span className="eyebrow">Ready?</span>
                    <h2>
                        Ready to <span className="ital">plug it in?</span>
                    </h2>
                    <p>
                        Free to start. Connect Notion, Slack, and your first
                        webhook in under 5 minutes.
                    </p>
                    <div className="final-actions">
                        <Link href="/register" className="btn btn-primary">
                            Get started free
                        </Link>
                        <a
                            href="/documentation/integrations"
                            target="_blank"
                            rel="noopener noreferrer"
                            className="btn btn-ghost"
                        >
                            Read integration docs
                        </a>
                    </div>
                </div>
            </section>
        </PrismLayout>
    );
}
