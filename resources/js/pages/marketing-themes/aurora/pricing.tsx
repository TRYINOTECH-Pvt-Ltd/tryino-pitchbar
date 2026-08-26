import { Link, router } from '@inertiajs/react';
import { useState } from 'react';

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

type Plan = {
    name: string;
    monthly_price: number;
    yearly_price: number;
    currency: string;
    currency_symbol: string;
    decimal_places: number;
    tagline: string;
    volume: string;
    cta_label: string;
    cta_href: string;
    highlight: boolean;
    features: string[];
};

type LifetimePlan = {
    id: string;
    name: string;
    price: number | null;
    currency: string;
    currency_symbol: string;
    decimal_places: number;
    features: string[];
    tagline: string;
    cta_label: string;
    cta_href: string;
    monthly_conversations: number;
};

type Interval = 'month' | 'year';

type CurrencyChoice = { code: string; name: string; symbol: string };

type MatrixCell = string | boolean;

type MatrixRow = {
    label: string;
    free: MatrixCell;
    standard: MatrixCell;
    pro: MatrixCell;
};

type Faq = { q: string; a: string };

type Props = {
    shell: ShellContent;
    brand: string;
    plans: Plan[];
    lifetime_plans: LifetimePlan[];
    currency: string;
    currencies: CurrencyChoice[];
    matrix: MatrixRow[];
    faqs: Faq[];
    contact_email: string;
};

function formatPrice(value: number, symbol: string, decimals: number): string {
    return `${symbol}${value.toLocaleString(undefined, {
        minimumFractionDigits: decimals,
        maximumFractionDigits: decimals,
    })}`;
}

function MatrixValue({ value }: { value: MatrixCell }) {
    if (value === true) {
        return (
            <span
                style={{
                    display: 'inline-block',
                    width: 12,
                    height: 12,
                    background: 'var(--signal)',
                    border: '1px solid var(--ink)',
                }}
            />
        );
    }

    if (value === false) {
        return <span style={{ color: 'var(--muted)' }}>—</span>;
    }

    return (
        <span style={{ fontFamily: 'var(--mono)', fontSize: 13 }}>{value}</span>
    );
}

function FaqItem({ q, a, index }: { q: string; a: string; index: number }) {
    const [open, setOpen] = useState(false);

    return (
        <div className={`faq-row ${open ? 'open' : ''}`}>
            <button
                type="button"
                className="faq-q"
                onClick={() => setOpen((v) => !v)}
            >
                <span className="qnum">
                    Q.{String(index + 1).padStart(2, '0')}
                </span>
                <span>{q}</span>
                <span className="toggle">
                    <span className="h" />
                    <span className="v" />
                </span>
            </button>
            <div className="faq-a">
                <div className="faq-a-inner">{a}</div>
            </div>
        </div>
    );
}

export default function AuroraPricing({
    shell,
    brand,
    plans,
    lifetime_plans,
    currency,
    currencies,
    matrix,
    faqs,
    contact_email,
}: Props) {
    const [interval, setInterval] = useState<Interval>('month');

    const onCurrencyChange = (code: string) => {
        if (code === currency) {
            return;
        }

        router.visit(`/pricing?currency=${encodeURIComponent(code)}`, {
            preserveScroll: true,
            preserveState: false,
        });
    };

    const hasAnnualOption = plans.some(
        (p) => p.yearly_price > 0 && p.monthly_price > 0,
    );

    const annualSavingsPercent = (() => {
        const candidate = plans.find(
            (p) => p.monthly_price > 0 && p.yearly_price > 0,
        );

        if (!candidate) {
            return 0;
        }

        const monthlyOverYear = candidate.monthly_price * 12;
        const saved = monthlyOverYear - candidate.yearly_price;

        return monthlyOverYear === 0
            ? 0
            : Math.round((saved / monthlyOverYear) * 100);
    })();

    return (
        <AuroraLayout
            brand={brand}
            navItems={shell.nav_items}
            footer={shell.footer}
            title={`Pricing — ${brand}`}
            description={`Simple, conversation-based pricing for the ${brand} sales AI widget. Start free; scale as you grow.`}
        >
            <section
                className="section"
                data-screen-label="Pricing hero"
                style={{ paddingBottom: 32 }}
            >
                <div className="wrap">
                    <div style={{ maxWidth: 880 }}>
                        <span className="eyebrow">
                            <span className="dot" />
                            <span className="idx">$/01</span>
                            PRICING
                        </span>
                        <h1
                            className="h-display"
                            style={{
                                marginTop: 28,
                                fontSize: 'clamp(40px, 6vw, 96px)',
                            }}
                        >
                            Simple,{' '}
                            <span className="signal-mark">
                                conversation-based
                            </span>{' '}
                            pricing.
                        </h1>
                        <p
                            className="lead"
                            style={{ marginTop: 22, color: 'var(--muted)' }}
                        >
                            Start free. Upgrade when you outgrow it. No per-seat
                            fees, no setup fees, no surprise overages — just a
                            clear conversation limit.
                        </p>

                        <div
                            style={{
                                marginTop: 36,
                                display: 'flex',
                                flexWrap: 'wrap',
                                gap: 12,
                                alignItems: 'center',
                            }}
                        >
                            {hasAnnualOption && (
                                <>
                                    <div
                                        role="radiogroup"
                                        aria-label="Billing interval"
                                        style={{
                                            display: 'inline-flex',
                                            border: '1px solid var(--ink)',
                                        }}
                                    >
                                        {(['month', 'year'] as Interval[]).map(
                                            (v) => (
                                                <button
                                                    key={v}
                                                    type="button"
                                                    role="radio"
                                                    aria-checked={
                                                        interval === v
                                                    }
                                                    onClick={() =>
                                                        setInterval(v)
                                                    }
                                                    style={{
                                                        padding: '10px 18px',
                                                        fontFamily:
                                                            'var(--mono)',
                                                        fontSize: 11,
                                                        letterSpacing: '0.08em',
                                                        textTransform:
                                                            'uppercase',
                                                        background:
                                                            interval === v
                                                                ? 'var(--ink)'
                                                                : 'transparent',
                                                        color:
                                                            interval === v
                                                                ? 'var(--paper)'
                                                                : 'var(--ink)',
                                                    }}
                                                >
                                                    {v === 'month'
                                                        ? 'Monthly'
                                                        : 'Yearly'}
                                                </button>
                                            ),
                                        )}
                                    </div>
                                    {annualSavingsPercent > 0 && (
                                        <span
                                            style={{
                                                background: 'var(--signal)',
                                                border: '1px solid var(--ink)',
                                                padding: '8px 12px',
                                                fontFamily: 'var(--mono)',
                                                fontSize: 11,
                                                letterSpacing: '0.06em',
                                                textTransform: 'uppercase',
                                            }}
                                        >
                                            Save {annualSavingsPercent}% yearly
                                        </span>
                                    )}
                                </>
                            )}
                            {currencies.length > 1 && (
                                <label
                                    style={{
                                        display: 'inline-flex',
                                        alignItems: 'center',
                                        gap: 8,
                                        border: '1px solid var(--ink)',
                                        padding: '8px 12px',
                                        fontFamily: 'var(--mono)',
                                        fontSize: 11,
                                        letterSpacing: '0.06em',
                                    }}
                                >
                                    <span style={{ color: 'var(--muted)' }}>
                                        CURRENCY
                                    </span>
                                    <select
                                        value={currency}
                                        onChange={(e) =>
                                            onCurrencyChange(e.target.value)
                                        }
                                        aria-label="Display currency"
                                        style={{
                                            background: 'transparent',
                                            border: 0,
                                            fontWeight: 600,
                                            color: 'var(--ink)',
                                        }}
                                    >
                                        {currencies.map((c) => (
                                            <option key={c.code} value={c.code}>
                                                {c.code} — {c.name}
                                            </option>
                                        ))}
                                    </select>
                                </label>
                            )}
                        </div>
                    </div>
                </div>
            </section>

            <section
                className="section"
                data-screen-label="Pricing plans"
                style={{ paddingTop: 0 }}
            >
                <div className="wrap">
                    <div
                        className="aurora-plans-grid"
                        style={{
                            display: 'grid',
                            gridTemplateColumns:
                                plans.length <= 1
                                    ? '1fr'
                                    : `repeat(auto-fit, minmax(${plans.length === 2 ? '300px' : plans.length === 3 ? '240px' : '220px'}, 1fr))`,
                            gap: 0,
                            border: '2px solid var(--ink)',
                        }}
                    >
                        {plans.map((plan, idx) => {
                            const showYearly =
                                interval === 'year' && plan.yearly_price > 0;
                            const headlinePrice = showYearly
                                ? Math.round(plan.yearly_price / 12)
                                : plan.monthly_price;
                            const ctaHref =
                                interval === 'year' && plan.yearly_price > 0
                                    ? plan.cta_href +
                                      (plan.cta_href.includes('?')
                                          ? '&'
                                          : '?') +
                                      'interval=year'
                                    : plan.cta_href;

                            return (
                                <div
                                    key={plan.name}
                                    className="aurora-plan-card"
                                    data-first={idx === 0 ? 'true' : undefined}
                                    style={{
                                        padding: 28,
                                        borderLeft:
                                            idx === 0
                                                ? 0
                                                : '1px solid var(--hairline)',
                                        background: plan.highlight
                                            ? 'var(--signal)'
                                            : 'transparent',
                                        position: 'relative',
                                        display: 'flex',
                                        flexDirection: 'column',
                                    }}
                                >
                                    {plan.highlight && (
                                        <span
                                            style={{
                                                position: 'absolute',
                                                top: -1,
                                                right: -1,
                                                background: 'var(--ink)',
                                                color: 'var(--paper)',
                                                fontFamily: 'var(--mono)',
                                                fontSize: 10,
                                                letterSpacing: '0.1em',
                                                padding: '6px 10px',
                                            }}
                                        >
                                            MOST POPULAR
                                        </span>
                                    )}
                                    <h3
                                        style={{
                                            fontFamily: 'var(--display)',
                                            fontSize: 22,
                                            fontWeight: 500,
                                            letterSpacing: '-0.015em',
                                        }}
                                    >
                                        {plan.name}
                                    </h3>
                                    <p
                                        style={{
                                            marginTop: 6,
                                            fontSize: 13,
                                            color: 'var(--muted)',
                                            lineHeight: 1.5,
                                        }}
                                    >
                                        {plan.tagline}
                                    </p>
                                    <div
                                        style={{
                                            marginTop: 24,
                                            display: 'flex',
                                            alignItems: 'baseline',
                                            gap: 4,
                                        }}
                                    >
                                        <span
                                            style={{
                                                fontFamily: 'var(--display)',
                                                fontWeight: 500,
                                                fontSize:
                                                    'clamp(36px, 4vw, 56px)',
                                                letterSpacing: '-0.04em',
                                                lineHeight: 1,
                                            }}
                                        >
                                            {formatPrice(
                                                headlinePrice,
                                                plan.currency_symbol,
                                                plan.decimal_places,
                                            )}
                                        </span>
                                        {headlinePrice > 0 && (
                                            <span
                                                style={{
                                                    fontFamily: 'var(--mono)',
                                                    fontSize: 12,
                                                    color: 'var(--muted)',
                                                }}
                                            >
                                                /month
                                            </span>
                                        )}
                                    </div>
                                    {showYearly && plan.yearly_price > 0 && (
                                        <p
                                            style={{
                                                marginTop: 6,
                                                fontFamily: 'var(--mono)',
                                                fontSize: 11,
                                                color: 'var(--muted)',
                                            }}
                                        >
                                            {formatPrice(
                                                plan.yearly_price,
                                                plan.currency_symbol,
                                                plan.decimal_places,
                                            )}{' '}
                                            billed annually
                                        </p>
                                    )}
                                    <p
                                        style={{
                                            marginTop: 14,
                                            fontSize: 14,
                                            fontWeight: 600,
                                        }}
                                    >
                                        {plan.volume}
                                    </p>
                                    <ul
                                        style={{
                                            marginTop: 22,
                                            listStyle: 'none',
                                            display: 'flex',
                                            flexDirection: 'column',
                                            gap: 10,
                                            flex: 1,
                                        }}
                                    >
                                        {plan.features.map((feature) => (
                                            <li
                                                key={feature}
                                                style={{
                                                    display: 'grid',
                                                    gridTemplateColumns:
                                                        'auto 1fr',
                                                    gap: 10,
                                                    fontSize: 13.5,
                                                    lineHeight: 1.5,
                                                }}
                                            >
                                                <span
                                                    style={{
                                                        width: 8,
                                                        height: 8,
                                                        marginTop: 6,
                                                        background:
                                                            'var(--ink)',
                                                    }}
                                                />
                                                <span>{feature}</span>
                                            </li>
                                        ))}
                                    </ul>
                                    <Link
                                        href={ctaHref}
                                        className="btn"
                                        style={{
                                            marginTop: 24,
                                            justifyContent: 'center',
                                        }}
                                    >
                                        {plan.cta_label}
                                        <span className="arrow">→</span>
                                    </Link>
                                </div>
                            );
                        })}
                    </div>
                    <p
                        style={{
                            marginTop: 24,
                            fontFamily: 'var(--mono)',
                            fontSize: 11,
                            letterSpacing: '0.06em',
                            textTransform: 'uppercase',
                            color: 'var(--muted)',
                            textAlign: 'center',
                        }}
                    >
                        Need higher limits or a self-hosted license?{' '}
                        <a
                            href={`mailto:${contact_email}`}
                            style={{
                                background: 'var(--signal)',
                                padding: '2px 6px',
                                color: 'var(--ink)',
                            }}
                        >
                            Talk to us about Enterprise
                        </a>
                        .
                    </p>
                </div>
            </section>

            {lifetime_plans.length > 0 && (
                <section
                    className="section"
                    data-screen-label="Pricing lifetime"
                    style={{ paddingTop: 0 }}
                >
                    <div className="wrap">
                        <div className="pages-head">
                            <div>
                                <span className="eyebrow">
                                    <span className="dot" />
                                    <span className="idx">$/02</span>
                                    LIFETIME DEAL
                                </span>
                                <h2 className="h-section">
                                    Pay once.{' '}
                                    <span className="signal-mark">
                                        Use forever.
                                    </span>
                                </h2>
                            </div>
                            <p
                                className="lead"
                                style={{ color: 'var(--muted)', fontSize: 15 }}
                            >
                                A one-time purchase that unlocks the agent for
                                the lifetime of your account — no monthly bill,
                                no surprise renewal.
                            </p>
                        </div>
                        <div className="pages-grid">
                            {lifetime_plans.map((plan) => (
                                <div key={plan.id} className="page-card">
                                    <div className="page-num">
                                        LTD / {plan.name.toUpperCase()}
                                    </div>
                                    <h3 className="h-card">{plan.name}</h3>
                                    <p>{plan.tagline}</p>
                                    <div style={{ marginTop: 16 }}>
                                        <div
                                            style={{
                                                fontFamily: 'var(--display)',
                                                fontWeight: 500,
                                                fontSize: 38,
                                                letterSpacing: '-0.03em',
                                                lineHeight: 1,
                                            }}
                                        >
                                            {plan.price === null
                                                ? '—'
                                                : formatPrice(
                                                      plan.price,
                                                      plan.currency_symbol,
                                                      plan.decimal_places,
                                                  )}
                                            <span
                                                style={{
                                                    fontFamily: 'var(--mono)',
                                                    fontSize: 12,
                                                    color: 'var(--muted)',
                                                    marginLeft: 8,
                                                }}
                                            >
                                                ONE-TIME
                                            </span>
                                        </div>
                                        {plan.monthly_conversations > 0 && (
                                            <p
                                                style={{
                                                    marginTop: 6,
                                                    fontFamily: 'var(--mono)',
                                                    fontSize: 11,
                                                    color: 'var(--muted)',
                                                }}
                                            >
                                                {plan.monthly_conversations.toLocaleString()}{' '}
                                                CONVERSATIONS / MONTH
                                            </p>
                                        )}
                                    </div>
                                    <ul
                                        style={{
                                            marginTop: 18,
                                            listStyle: 'none',
                                            display: 'flex',
                                            flexDirection: 'column',
                                            gap: 8,
                                            fontSize: 13,
                                        }}
                                    >
                                        {plan.features.map((feature) => (
                                            <li
                                                key={feature}
                                                style={{
                                                    display: 'grid',
                                                    gridTemplateColumns:
                                                        'auto 1fr',
                                                    gap: 8,
                                                }}
                                            >
                                                <span
                                                    style={{
                                                        width: 6,
                                                        height: 6,
                                                        marginTop: 6,
                                                        background:
                                                            'var(--signal)',
                                                        border: '1px solid var(--ink)',
                                                    }}
                                                />
                                                <span>{feature}</span>
                                            </li>
                                        ))}
                                    </ul>
                                    <Link
                                        href={plan.cta_href}
                                        className="btn"
                                        style={{
                                            marginTop: 20,
                                            justifyContent: 'center',
                                            background: 'var(--signal)',
                                            color: 'var(--ink)',
                                        }}
                                    >
                                        {plan.cta_label}
                                        <span className="arrow">→</span>
                                    </Link>
                                </div>
                            ))}
                        </div>
                    </div>
                </section>
            )}

            <section
                className="section"
                data-screen-label="Pricing matrix"
                style={{ paddingTop: 0 }}
            >
                <div className="wrap">
                    <div className="pages-head">
                        <div>
                            <span className="eyebrow">
                                <span className="dot" />
                                <span className="idx">$/03</span>
                                COMPARE
                            </span>
                            <h2 className="h-section">
                                Every feature, side by side.
                            </h2>
                        </div>
                    </div>
                    <div
                        style={{
                            border: '2px solid var(--ink)',
                            overflowX: 'auto',
                            marginTop: 24,
                        }}
                    >
                        <table
                            style={{
                                width: '100%',
                                minWidth: 640,
                                borderCollapse: 'collapse',
                                fontSize: 14,
                            }}
                        >
                            <thead>
                                <tr>
                                    <th
                                        style={{
                                            textAlign: 'left',
                                            padding: '14px 18px',
                                            fontFamily: 'var(--mono)',
                                            fontSize: 11,
                                            letterSpacing: '0.08em',
                                            textTransform: 'uppercase',
                                            color: 'var(--muted)',
                                            borderBottom:
                                                '2px solid var(--ink)',
                                        }}
                                    >
                                        Feature
                                    </th>
                                    {['Free', 'Standard', 'Pro'].map((h) => (
                                        <th
                                            key={h}
                                            style={{
                                                textAlign: 'center',
                                                padding: '14px 18px',
                                                fontFamily: 'var(--display)',
                                                fontSize: 14,
                                                fontWeight: 600,
                                                borderBottom:
                                                    '2px solid var(--ink)',
                                                background:
                                                    h === 'Standard'
                                                        ? 'var(--signal)'
                                                        : 'transparent',
                                            }}
                                        >
                                            {h}
                                        </th>
                                    ))}
                                </tr>
                            </thead>
                            <tbody>
                                {matrix.map((row) => (
                                    <tr key={row.label}>
                                        <td
                                            style={{
                                                padding: '14px 18px',
                                                fontWeight: 500,
                                                borderTop:
                                                    '1px solid var(--hairline)',
                                            }}
                                        >
                                            {row.label}
                                        </td>
                                        <td
                                            style={{
                                                padding: '14px 18px',
                                                textAlign: 'center',
                                                borderTop:
                                                    '1px solid var(--hairline)',
                                            }}
                                        >
                                            <MatrixValue value={row.free} />
                                        </td>
                                        <td
                                            style={{
                                                padding: '14px 18px',
                                                textAlign: 'center',
                                                borderTop:
                                                    '1px solid var(--hairline)',
                                            }}
                                        >
                                            <MatrixValue value={row.standard} />
                                        </td>
                                        <td
                                            style={{
                                                padding: '14px 18px',
                                                textAlign: 'center',
                                                borderTop:
                                                    '1px solid var(--hairline)',
                                            }}
                                        >
                                            <MatrixValue value={row.pro} />
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>

            {faqs.length > 0 && (
                <section
                    className="section"
                    data-screen-label="Pricing FAQ"
                    style={{ paddingTop: 0 }}
                >
                    <div className="wrap">
                        <div className="faq-head">
                            <div>
                                <span className="eyebrow">
                                    <span className="dot" />
                                    <span className="idx">$/04</span>
                                    FREQUENTLY ASKED
                                </span>
                                <h2 className="h-section">
                                    About plans and billing.
                                </h2>
                            </div>
                        </div>
                        <div className="faq">
                            {faqs.map((faq, idx) => (
                                <FaqItem
                                    key={faq.q}
                                    q={faq.q}
                                    a={faq.a}
                                    index={idx}
                                />
                            ))}
                        </div>
                    </div>
                </section>
            )}

            <section className="cta" data-screen-label="Pricing CTA">
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
                                    $/05
                                </span>
                                READY?
                            </span>
                            <h2 style={{ marginTop: 24 }}>
                                Ready to ship a{' '}
                                <span className="signal-mark">
                                    real sales AI?
                                </span>
                            </h2>
                            <p>
                                Free to start. Deploys in 5 minutes. No card
                                required.
                            </p>
                        </div>
                        <div className="cta-actions">
                            <Link href="/register" className="btn-big primary">
                                <span>Get started free</span>
                                <span>→</span>
                            </Link>
                        </div>
                    </div>
                </div>
            </section>
        </AuroraLayout>
    );
}
