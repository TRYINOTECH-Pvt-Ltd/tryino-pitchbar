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
    steps: Step[];
    latency: LatencyRow[];
};

export default function AuroraHowItWorks({
    shell,
    brand,
    steps,
    latency,
}: Props) {
    return (
        <AuroraLayout
            brand={brand}
            navItems={shell.nav_items}
            footer={shell.footer}
            title={`How it works — ${brand}`}
            description={`How the ${brand} sales AI widget turns your website into a 24/7 sales rep — from setup to first conversation in under 5 minutes.`}
        >
            <section
                className="section"
                data-screen-label="HIW hero"
                style={{ paddingBottom: 24 }}
            >
                <div className="wrap">
                    <div style={{ maxWidth: 880 }}>
                        <span className="eyebrow">
                            <span className="dot" />
                            <span className="idx">H/01</span>
                            HOW IT WORKS
                        </span>
                        <h1
                            className="h-display"
                            style={{
                                marginTop: 28,
                                fontSize: 'clamp(40px, 6.5vw, 96px)',
                            }}
                        >
                            From URL to live agent in{' '}
                            <span className="signal-mark">under 5 minutes</span>
                            .
                        </h1>
                        <p
                            className="lead"
                            style={{ marginTop: 22, color: 'var(--muted)' }}
                        >
                            {brand} reads your website, builds an AI agent
                            grounded in your own content, and embeds it on any
                            page with one line of HTML. Here's exactly what
                            happens.
                        </p>
                    </div>
                </div>
            </section>

            <section
                className="section"
                data-screen-label="HIW steps"
                style={{ paddingTop: 0 }}
            >
                <div className="wrap">
                    <div className="steps">
                        {steps.map((step) => (
                            <div key={step.number} className="step">
                                <span className="pip-track" />
                                <div className="num">
                                    {step.number}
                                    <small>{step.duration.toUpperCase()}</small>
                                </div>
                                <h3>{step.title}</h3>
                                <p>{step.description}</p>
                                {step.sub_points.length > 0 && (
                                    <ul
                                        className="bullets"
                                        style={{
                                            marginTop: 12,
                                            borderTop: 'var(--hair)',
                                            paddingTop: 12,
                                        }}
                                    >
                                        {step.sub_points.map((point) => (
                                            <li key={point}>
                                                <span className="pip" />
                                                <span>{point}</span>
                                            </li>
                                        ))}
                                    </ul>
                                )}
                            </div>
                        ))}
                    </div>
                </div>
            </section>

            <section
                className="section"
                data-screen-label="HIW latency"
                style={{ paddingTop: 0 }}
            >
                <div className="wrap">
                    <div className="tune">
                        <div className="tune-text">
                            <span className="eyebrow">
                                <span className="dot" />
                                <span className="idx">H/02</span>
                                HOT PATH CONTRACT
                            </span>
                            <h2 className="h-section" style={{ marginTop: 24 }}>
                                1 second to{' '}
                                <span className="signal-mark">first token</span>
                                . Every time.
                            </h2>
                            <p className="lead">
                                The visitor-message → first-token path has a
                                hard p95 budget. No DB writes, no synchronous
                                webhooks, no retries. Persistence and analytics
                                are dispatched async after the stream completes.
                            </p>
                            <p
                                style={{
                                    fontSize: 15.5,
                                    lineHeight: 1.6,
                                    color: 'var(--muted)',
                                    marginTop: 12,
                                }}
                            >
                                OpenTelemetry spans wrap every phase, so when
                                the budget breaks the span heatmap points right
                                at the offender.
                            </p>
                        </div>
                        <div>
                            <div className="settings">
                                <div className="settings-head">
                                    <span>P95 LATENCY BUDGET</span>
                                    <span className="right">
                                        {brand.toUpperCase()} / HOT PATH
                                    </span>
                                </div>
                                {latency.map((row, idx) => (
                                    <div key={row.phase} className="field">
                                        <div className="label">
                                            {row.phase}
                                            <span className="sub">{`PHASE ${idx + 1}`}</span>
                                        </div>
                                        <div className="control">
                                            <span
                                                style={{
                                                    fontFamily: 'var(--mono)',
                                                }}
                                            >
                                                {row.budget}
                                            </span>
                                            <span className="caret">·</span>
                                        </div>
                                    </div>
                                ))}
                                <div
                                    className="field"
                                    style={{
                                        borderTop: '2px solid var(--ink)',
                                        background: 'var(--paper-2)',
                                    }}
                                >
                                    <div className="label">
                                        Total to first token
                                        <span className="sub">P95 BUDGET</span>
                                    </div>
                                    <div className="control focus">
                                        <span
                                            style={{
                                                fontFamily: 'var(--mono)',
                                                fontWeight: 600,
                                            }}
                                        >
                                            ~ 865 ms
                                        </span>
                                        <span className="caret">↗</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <section className="cta" data-screen-label="HIW CTA">
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
                                    H/03
                                </span>
                                START NOW
                            </span>
                            <h2 style={{ marginTop: 24 }}>
                                Try it on your{' '}
                                <span className="signal-mark">own site</span>.
                            </h2>
                            <p>
                                Free to start. No card required. Live in 5
                                minutes.
                            </p>
                        </div>
                        <div className="cta-actions">
                            <Link href="/register" className="btn-big primary">
                                <span>Start free</span>
                                <span>→</span>
                            </Link>
                            <Link href="/pricing" className="btn-big">
                                <span>See pricing</span>
                                <span>→</span>
                            </Link>
                        </div>
                    </div>
                </div>
            </section>
        </AuroraLayout>
    );
}
