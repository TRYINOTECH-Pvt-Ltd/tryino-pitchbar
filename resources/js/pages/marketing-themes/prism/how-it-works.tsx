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

type Step = {
    number: string;
    title: string;
    duration: string;
    description: string;
    sub_points: string[];
};

type LatencyRow = { phase: string; budget: string };

type Props = {
    shell: ShellContent;
    brand: string;
    canRegister?: boolean;
    steps: Step[];
    latency: LatencyRow[];
};

export default function PrismHowItWorks({
    shell,
    brand,
    canRegister = false,
    steps,
    latency,
}: Props) {
    return (
        <PrismLayout
            brand={brand}
            navItems={shell.nav_items}
            footer={shell.footer}
            canRegister={canRegister}
            title={`How it works — ${brand}`}
            description={`How the ${brand} sales AI widget turns your website into a 24/7 sales rep — from setup to first conversation in under 5 minutes.`}
        >
            <section className="hero" data-screen-label="HIW hero">
                <div className="wrap">
                    <h1>
                        From URL to a live agent in{' '}
                        <span className="ital">under 5 minutes</span>.
                    </h1>
                    <p className="hero-sub">
                        {brand} reads your website, builds an AI agent grounded
                        in your own content, and embeds it on any page with one
                        line of HTML. Here's exactly what happens.
                    </p>
                </div>
            </section>

            <section
                className="sec"
                style={{ paddingTop: 0 }}
                data-screen-label="HIW steps"
            >
                <div className="wrap">
                    <div className="sec-hd-center">
                        <span className="eyebrow">From paste to live</span>
                        <h2>
                            Ship a working assistant in{' '}
                            <span className="ital">three</span> steps.
                        </h2>
                        <p className="lede">
                            Most teams go live before lunch — no engineering
                            required.
                        </p>
                    </div>

                    <div className="steps-grid">
                        {steps.map((step, idx) => (
                            <article className="step" key={step.title}>
                                <span className="step-n">
                                    <span className="step-num">{idx + 1}</span>
                                    {step.number} · {step.duration}
                                </span>
                                <h3>{step.title}</h3>
                                <p>{step.description}</p>
                                {step.sub_points.length > 0 ? (
                                    <ul
                                        style={{
                                            listStyle: 'none',
                                            padding: 0,
                                            margin: 0,
                                            display: 'flex',
                                            flexDirection: 'column',
                                            gap: 6,
                                            fontSize: 13,
                                            color: 'var(--muted)',
                                        }}
                                    >
                                        {step.sub_points.map((point) => (
                                            <li
                                                key={point}
                                                style={{
                                                    display: 'flex',
                                                    gap: 8,
                                                    alignItems: 'flex-start',
                                                    lineHeight: 1.5,
                                                }}
                                            >
                                                <span
                                                    aria-hidden="true"
                                                    style={{
                                                        width: 14,
                                                        height: 14,
                                                        borderRadius: 4,
                                                        background:
                                                            'var(--grad-soft)',
                                                        flex: '0 0 auto',
                                                        marginTop: 3,
                                                    }}
                                                />
                                                <span>{point}</span>
                                            </li>
                                        ))}
                                    </ul>
                                ) : null}
                            </article>
                        ))}
                    </div>
                </div>
            </section>

            <section
                className="sec"
                style={{ paddingTop: 0 }}
                data-screen-label="HIW latency"
            >
                <div className="wrap">
                    <div className="sec-hd">
                        <div>
                            <span className="eyebrow">Hot path contract</span>
                            <h2>
                                1 second to{' '}
                                <span className="ital">first token</span>. Every
                                time.
                            </h2>
                        </div>
                    </div>

                    <div className="tune-grid">
                        <div className="tune-left">
                            <h4>
                                The visitor-message → first-token path has a
                                hard p95 <span className="ital">budget</span>.
                            </h4>
                            <p>
                                No DB writes, no synchronous webhooks, no
                                retries. Persistence and analytics are
                                dispatched async after the stream completes.
                                OpenTelemetry spans wrap every phase, so when
                                the budget breaks, the span heatmap points right
                                at the offender.
                            </p>
                        </div>
                        <div className="tune-right">
                            <div className="settings">
                                <div className="settings-hd">
                                    <h5>P95 LATENCY BUDGET</h5>
                                    <span className="badge">/ hot path</span>
                                </div>
                                {latency.map((row, idx) => (
                                    <div
                                        className="settings-row"
                                        key={row.phase}
                                    >
                                        <div>
                                            <div className="lbl">
                                                {row.phase}
                                            </div>
                                            <div className="hint">{`Phase ${idx + 1}`}</div>
                                        </div>
                                        <div
                                            className="field"
                                            style={{
                                                fontFamily:
                                                    'ui-monospace, Menlo, monospace',
                                            }}
                                        >
                                            {row.budget}
                                        </div>
                                    </div>
                                ))}
                                <div
                                    className="settings-row"
                                    style={{ borderTop: '2px solid var(--fg)' }}
                                >
                                    <div>
                                        <div className="lbl">
                                            Total to first token
                                        </div>
                                        <div className="hint">P95 BUDGET</div>
                                    </div>
                                    <div
                                        className="field"
                                        style={{
                                            fontWeight: 600,
                                            fontFamily:
                                                'ui-monospace, Menlo, monospace',
                                        }}
                                    >
                                        ~ 865 ms
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <section className="wrap" data-screen-label="HIW CTA">
                <div className="final">
                    <span className="eyebrow">Start now</span>
                    <h2>
                        Try it on your <span className="ital">own site</span>.
                    </h2>
                    <p>Free to start. No card required. Live in 5 minutes.</p>
                    <div className="final-actions">
                        <Link href="/register" className="btn btn-primary">
                            Start free
                        </Link>
                        <Link href="/pricing" className="btn btn-ghost">
                            See pricing
                        </Link>
                    </div>
                </div>
            </section>
        </PrismLayout>
    );
}
