import { Link, router } from '@inertiajs/react';
import { useState } from 'react';

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
    canRegister?: boolean;
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
            <svg
                width="16"
                height="16"
                viewBox="0 0 24 24"
                fill="none"
                stroke="currentColor"
                strokeWidth="2.5"
                style={{
                    color: 'var(--accent)',
                    display: 'inline-block',
                    verticalAlign: 'middle',
                }}
            >
                <path d="M5 12l5 5L20 7" />
            </svg>
        );
    }

    if (value === false) {
        return <span style={{ color: 'var(--muted-2)' }}>—</span>;
    }

    return <span style={{ fontWeight: 600 }}>{value}</span>;
}

function FaqItem({
    q,
    a,
    open,
    onToggle,
}: {
    q: string;
    a: string;
    open: boolean;
    onToggle: () => void;
}) {
    return (
        <div className={`faq-item ${open ? 'open' : ''}`}>
            <button
                type="button"
                className="faq-q"
                onClick={onToggle}
                aria-expanded={open}
            >
                <span>{q}</span>
                <span className="sign">{open ? '–' : '+'}</span>
            </button>
            {open ? <div className="faq-a">{a}</div> : null}
        </div>
    );
}

export default function PrismPricing({
    shell,
    brand,
    canRegister = false,
    plans,
    lifetime_plans,
    currency,
    currencies,
    matrix,
    faqs,
    contact_email,
}: Props) {
    const [billingInterval, setBillingInterval] = useState<Interval>('month');
    const [openFaq, setOpenFaq] = useState<number>(0);

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
        <PrismLayout
            brand={brand}
            navItems={shell.nav_items}
            footer={shell.footer}
            canRegister={canRegister}
            title={`Pricing — ${brand}`}
            description={`Simple, conversation-based pricing for the ${brand} sales AI widget. Start free; scale as you grow.`}
        >
            <section className="hero" data-screen-label="Pricing hero">
                <div className="wrap">
                    <h1>
                        Simple, <span className="ital">conversation-based</span>{' '}
                        pricing.
                    </h1>
                    <p className="hero-sub">
                        Start free. Upgrade when you outgrow it. No per-seat
                        fees, no setup fees, no surprise overages — just a clear
                        conversation limit.
                    </p>

                    <div
                        style={{
                            marginTop: 28,
                            display: 'flex',
                            flexWrap: 'wrap',
                            gap: 12,
                            justifyContent: 'center',
                            alignItems: 'center',
                        }}
                    >
                        {hasAnnualOption ? (
                            <>
                                <div
                                    role="radiogroup"
                                    aria-label="Billing interval"
                                    style={{
                                        display: 'inline-flex',
                                        border: '1px solid var(--line)',
                                        borderRadius: 'var(--r-pill)',
                                        overflow: 'hidden',
                                        background: '#fff',
                                    }}
                                >
                                    {(['month', 'year'] as Interval[]).map(
                                        (v) => (
                                            <button
                                                key={v}
                                                type="button"
                                                role="radio"
                                                aria-checked={
                                                    billingInterval === v
                                                }
                                                onClick={() =>
                                                    setBillingInterval(v)
                                                }
                                                style={{
                                                    padding: '8px 18px',
                                                    fontSize: 13,
                                                    fontWeight: 500,
                                                    background:
                                                        billingInterval === v
                                                            ? 'var(--fg)'
                                                            : 'transparent',
                                                    color:
                                                        billingInterval === v
                                                            ? '#fff'
                                                            : 'var(--fg-2)',
                                                    cursor: 'pointer',
                                                    border: 0,
                                                }}
                                            >
                                                {v === 'month'
                                                    ? 'Monthly'
                                                    : 'Yearly'}
                                            </button>
                                        ),
                                    )}
                                </div>
                                {annualSavingsPercent > 0 ? (
                                    <span className="pill-tag">
                                        <span className="dot" />
                                        Save {annualSavingsPercent}% yearly
                                    </span>
                                ) : null}
                            </>
                        ) : null}
                        {currencies.length > 1 ? (
                            <label
                                style={{
                                    display: 'inline-flex',
                                    alignItems: 'center',
                                    gap: 8,
                                    border: '1px solid var(--line)',
                                    borderRadius: 'var(--r-pill)',
                                    padding: '6px 14px',
                                    background: '#fff',
                                    fontSize: 13,
                                }}
                            >
                                <span
                                    style={{
                                        color: 'var(--muted)',
                                        fontSize: 11,
                                        letterSpacing: '0.08em',
                                        textTransform: 'uppercase',
                                        fontWeight: 600,
                                    }}
                                >
                                    Currency
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
                                        color: 'var(--fg)',
                                        fontSize: 13,
                                        cursor: 'pointer',
                                    }}
                                >
                                    {currencies.map((c) => (
                                        <option key={c.code} value={c.code}>
                                            {c.code} — {c.name}
                                        </option>
                                    ))}
                                </select>
                            </label>
                        ) : null}
                    </div>
                </div>
            </section>

            <section
                className="sec"
                style={{ paddingTop: 0 }}
                data-screen-label="Pricing plans"
            >
                <div className="wrap">
                    <div
                        style={{
                            display: 'grid',
                            gridTemplateColumns:
                                plans.length <= 1
                                    ? '1fr'
                                    : `repeat(auto-fit, minmax(${plans.length === 2 ? '300px' : '240px'}, 1fr))`,
                            gap: 14,
                        }}
                    >
                        {plans.map((plan) => {
                            const showYearly =
                                billingInterval === 'year' &&
                                plan.yearly_price > 0;
                            const headlinePrice = showYearly
                                ? Math.round(plan.yearly_price / 12)
                                : plan.monthly_price;
                            const ctaHref =
                                billingInterval === 'year' &&
                                plan.yearly_price > 0
                                    ? plan.cta_href +
                                      (plan.cta_href.includes('?')
                                          ? '&'
                                          : '?') +
                                      'interval=year'
                                    : plan.cta_href;

                            return (
                                <div
                                    key={plan.name}
                                    style={{
                                        position: 'relative',
                                        border: '1px solid var(--line)',
                                        borderRadius: 'var(--r-lg)',
                                        padding: 28,
                                        display: 'flex',
                                        flexDirection: 'column',
                                        background: plan.highlight
                                            ? 'var(--grad-soft)'
                                            : '#fff',
                                        boxShadow: plan.highlight
                                            ? 'var(--shadow-md)'
                                            : 'none',
                                    }}
                                >
                                    {plan.highlight ? (
                                        <span
                                            style={{
                                                position: 'absolute',
                                                top: -12,
                                                left: 24,
                                                background: 'var(--grad)',
                                                color: '#fff',
                                                fontSize: 10,
                                                letterSpacing: '0.1em',
                                                textTransform: 'uppercase',
                                                fontWeight: 700,
                                                padding: '4px 12px',
                                                borderRadius: 'var(--r-pill)',
                                            }}
                                        >
                                            Most popular
                                        </span>
                                    ) : null}
                                    <h3
                                        style={{
                                            fontFamily: 'var(--sans)',
                                            fontSize: 22,
                                            fontWeight: 600,
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
                                            marginTop: 22,
                                            display: 'flex',
                                            alignItems: 'baseline',
                                            gap: 6,
                                        }}
                                    >
                                        <span
                                            style={{
                                                fontFamily: 'var(--sans)',
                                                fontWeight: 600,
                                                fontSize:
                                                    'clamp(34px, 4vw, 52px)',
                                                letterSpacing: '-0.03em',
                                                lineHeight: 1,
                                            }}
                                        >
                                            {formatPrice(
                                                headlinePrice,
                                                plan.currency_symbol,
                                                plan.decimal_places,
                                            )}
                                        </span>
                                        {headlinePrice > 0 ? (
                                            <span
                                                style={{
                                                    fontSize: 12,
                                                    color: 'var(--muted)',
                                                    fontWeight: 500,
                                                }}
                                            >
                                                / month
                                            </span>
                                        ) : null}
                                    </div>
                                    {showYearly && plan.yearly_price > 0 ? (
                                        <p
                                            style={{
                                                marginTop: 6,
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
                                    ) : null}
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
                                            marginTop: 18,
                                            listStyle: 'none',
                                            padding: 0,
                                            display: 'flex',
                                            flexDirection: 'column',
                                            gap: 8,
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
                                                    color: 'var(--fg-2)',
                                                }}
                                            >
                                                <svg
                                                    width="14"
                                                    height="14"
                                                    viewBox="0 0 24 24"
                                                    fill="none"
                                                    stroke="currentColor"
                                                    strokeWidth="2.5"
                                                    style={{
                                                        color: 'var(--accent)',
                                                        flex: '0 0 auto',
                                                        marginTop: 4,
                                                    }}
                                                >
                                                    <path d="M5 12l5 5L20 7" />
                                                </svg>
                                                <span>{feature}</span>
                                            </li>
                                        ))}
                                    </ul>
                                    <Link
                                        href={ctaHref}
                                        className={
                                            plan.highlight
                                                ? 'btn btn-primary'
                                                : 'btn btn-light'
                                        }
                                        style={{
                                            marginTop: 22,
                                            justifyContent: 'center',
                                        }}
                                    >
                                        {plan.cta_label}
                                        <span className="arr">→</span>
                                    </Link>
                                </div>
                            );
                        })}
                    </div>
                    <p
                        style={{
                            marginTop: 28,
                            fontSize: 13,
                            color: 'var(--muted)',
                            textAlign: 'center',
                        }}
                    >
                        Need higher limits or a self-hosted license?{' '}
                        <a
                            href={`mailto:${contact_email}`}
                            style={{
                                color: 'var(--accent-ink)',
                                fontWeight: 600,
                                borderBottom: '2px solid var(--accent)',
                            }}
                        >
                            Talk to us about Enterprise
                        </a>
                        .
                    </p>
                </div>
            </section>

            {lifetime_plans.length > 0 ? (
                <section
                    className="sec"
                    style={{ paddingTop: 0 }}
                    data-screen-label="Pricing lifetime"
                >
                    <div className="wrap">
                        <div className="sec-hd">
                            <div>
                                <span className="eyebrow">Lifetime deal</span>
                                <h2>
                                    Pay once.{' '}
                                    <span className="ital">Use forever.</span>
                                </h2>
                            </div>
                            <p
                                style={{
                                    color: 'var(--muted)',
                                    fontSize: 14.5,
                                    maxWidth: '36ch',
                                }}
                            >
                                A one-time purchase that unlocks the agent for
                                the lifetime of your account — no monthly bill,
                                no surprise renewal.
                            </p>
                        </div>
                        <div className="uc-grid">
                            {lifetime_plans.map((plan) => (
                                <article className="uc" key={plan.id}>
                                    <div className="uc-ic">
                                        <span
                                            style={{
                                                fontSize: 11,
                                                fontWeight: 700,
                                            }}
                                        >
                                            LTD
                                        </span>
                                    </div>
                                    <h3>{plan.name}</h3>
                                    <p>{plan.tagline}</p>
                                    <div
                                        style={{
                                            fontFamily: 'var(--sans)',
                                            fontWeight: 600,
                                            fontSize: 32,
                                            letterSpacing: '-0.03em',
                                            lineHeight: 1,
                                            marginTop: 10,
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
                                                fontSize: 12,
                                                color: 'var(--muted)',
                                                marginLeft: 8,
                                                fontWeight: 500,
                                            }}
                                        >
                                            ONE-TIME
                                        </span>
                                    </div>
                                    {plan.monthly_conversations > 0 ? (
                                        <p
                                            style={{
                                                fontSize: 11,
                                                color: 'var(--muted)',
                                                letterSpacing: '0.06em',
                                                textTransform: 'uppercase',
                                                fontWeight: 600,
                                            }}
                                        >
                                            {plan.monthly_conversations.toLocaleString()}{' '}
                                            conversations / month
                                        </p>
                                    ) : null}
                                    <ul
                                        style={{
                                            marginTop: 12,
                                            listStyle: 'none',
                                            padding: 0,
                                            display: 'flex',
                                            flexDirection: 'column',
                                            gap: 6,
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
                                                    fontSize: 13,
                                                    color: 'var(--fg-2)',
                                                    lineHeight: 1.5,
                                                }}
                                            >
                                                <svg
                                                    width="12"
                                                    height="12"
                                                    viewBox="0 0 24 24"
                                                    fill="none"
                                                    stroke="currentColor"
                                                    strokeWidth="2.5"
                                                    style={{
                                                        color: 'var(--accent)',
                                                        flex: '0 0 auto',
                                                        marginTop: 5,
                                                    }}
                                                >
                                                    <path d="M5 12l5 5L20 7" />
                                                </svg>
                                                <span>{feature}</span>
                                            </li>
                                        ))}
                                    </ul>
                                    <Link
                                        href={plan.cta_href}
                                        className="btn btn-primary"
                                        style={{
                                            marginTop: 16,
                                            justifyContent: 'center',
                                        }}
                                    >
                                        {plan.cta_label}
                                        <span className="arr">→</span>
                                    </Link>
                                </article>
                            ))}
                        </div>
                    </div>
                </section>
            ) : null}

            <section
                className="sec"
                style={{ paddingTop: 0 }}
                data-screen-label="Pricing matrix"
            >
                <div className="wrap">
                    <div className="sec-hd">
                        <div>
                            <span className="eyebrow">Compare</span>
                            <h2>
                                Every feature,{' '}
                                <span className="ital">side by side.</span>
                            </h2>
                        </div>
                    </div>
                    <div
                        style={{
                            border: '1px solid var(--line)',
                            borderRadius: 'var(--r-lg)',
                            overflowX: 'auto',
                            background: '#fff',
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
                                            fontSize: 11,
                                            letterSpacing: '0.08em',
                                            textTransform: 'uppercase',
                                            color: 'var(--muted)',
                                            borderBottom:
                                                '1px solid var(--line)',
                                            fontWeight: 600,
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
                                                fontFamily: 'var(--sans)',
                                                fontSize: 14,
                                                fontWeight: 600,
                                                borderBottom:
                                                    '1px solid var(--line)',
                                                background:
                                                    h === 'Standard'
                                                        ? 'var(--grad-soft)'
                                                        : 'transparent',
                                                color:
                                                    h === 'Standard'
                                                        ? 'var(--accent-ink)'
                                                        : 'var(--fg)',
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
                                                    '1px solid var(--line-2)',
                                                color: 'var(--fg)',
                                            }}
                                        >
                                            {row.label}
                                        </td>
                                        <td
                                            style={{
                                                padding: '14px 18px',
                                                textAlign: 'center',
                                                borderTop:
                                                    '1px solid var(--line-2)',
                                            }}
                                        >
                                            <MatrixValue value={row.free} />
                                        </td>
                                        <td
                                            style={{
                                                padding: '14px 18px',
                                                textAlign: 'center',
                                                borderTop:
                                                    '1px solid var(--line-2)',
                                                background: 'var(--grad-soft)',
                                            }}
                                        >
                                            <MatrixValue value={row.standard} />
                                        </td>
                                        <td
                                            style={{
                                                padding: '14px 18px',
                                                textAlign: 'center',
                                                borderTop:
                                                    '1px solid var(--line-2)',
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

            {faqs.length > 0 ? (
                <section
                    className="sec"
                    style={{ paddingTop: 0 }}
                    data-screen-label="Pricing FAQ"
                >
                    <div className="wrap">
                        <div className="faq-grid">
                            <aside className="faq-side">
                                <span className="eyebrow">
                                    Frequently asked
                                </span>
                                <h2>
                                    About <span className="ital">plans</span>{' '}
                                    and billing.
                                </h2>
                                <p>
                                    Don't see your question?{' '}
                                    <a href={`mailto:${contact_email}`}>
                                        {contact_email}
                                    </a>{' '}
                                    — we reply within a business day.
                                </p>
                            </aside>
                            <div className="faq">
                                {faqs.map((faq, idx) => (
                                    <FaqItem
                                        key={faq.q}
                                        q={faq.q}
                                        a={faq.a}
                                        open={openFaq === idx}
                                        onToggle={() =>
                                            setOpenFaq((cur) =>
                                                cur === idx ? -1 : idx,
                                            )
                                        }
                                    />
                                ))}
                            </div>
                        </div>
                    </div>
                </section>
            ) : null}

            <section className="wrap" data-screen-label="Pricing CTA">
                <div className="final">
                    <span className="eyebrow">Ready?</span>
                    <h2>
                        Ready to ship a{' '}
                        <span className="ital">real sales AI?</span>
                    </h2>
                    <p>
                        Free to start. Deploys in 5 minutes. No card required.
                    </p>
                    <div className="final-actions">
                        <Link href="/register" className="btn btn-primary">
                            Get started free
                        </Link>
                    </div>
                </div>
            </section>
        </PrismLayout>
    );
}
