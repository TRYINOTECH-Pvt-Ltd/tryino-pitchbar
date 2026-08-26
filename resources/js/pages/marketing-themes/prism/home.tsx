import { Link, usePage } from '@inertiajs/react';
import { useMemo, useRef, useState } from 'react';
import type { FormEvent } from 'react';

import { dashboard, login, register } from '@/routes';

import PrismLayout from './_layout';
import PrismChat from './interactive-chat';
import type { PrismChatHandle } from './interactive-chat';

type ContentLink = { label: string; href: string };

type IconContentCard = {
    icon: string;
    title: string;
    description: string;
};

type SettingRow = {
    label: string;
    hint: string;
    value: string;
};

type LandingContent = {
    brand_name: string;
    nav_items: ContentLink[];
    header: {
        resources_label: string;
        resources_href: string;
        primary_button_label: string;
        primary_button_href: string;
    };
    hero: {
        badge: string;
        line_one: string;
        accent: string;
        line_two_suffix: string;
        line_three_prefix: string;
        line_three_highlight: string;
        line_four_highlight: string;
        description: string;
        site_test_label: string;
        site_test_placeholder: string;
        site_test_button_label: string;
        site_test_helper: string;
        live_demo_notice: string;
    };
    chat_preview: {
        title: string;
        badge: string;
        question: string;
        answer: string;
        plan_badge: string;
        plan_name: string;
        plan_price: string;
        plan_interval: string;
        plan_note: string;
        plan_features: string[];
        plan_button_label: string;
        typing_label: string;
        powered_by_prefix: string;
    };
    stats: Array<{ icon: string; value: string; label: string }>;
    video: {
        badge: string;
        title: string;
        description: string;
        bullets: string[];
        duration_label: string;
        tag_label: string;
        scene_label: string;
        card_title: string;
        timecode: string;
        chips: string[];
        footer_title: string;
        footer_description: string;
        button_label: string;
        href: string;
    };
    where_it_fits: {
        badge: string;
        title: string;
        cards: IconContentCard[];
    };
    feature_grid: {
        badge: string;
        title: string;
        cards: IconContentCard[];
    };
    control: {
        badge: string;
        title: string;
        description: string;
        callouts: IconContentCard[];
        settings_card_title: string;
        settings_rows: SettingRow[];
        cancel_label: string;
        save_button_label: string;
    };
    steps: {
        badge: string;
        title: string;
        items: IconContentCard[];
    };
    insights: {
        badge: string;
        chart_title: string;
        chart_description: string;
        metric_label: string;
        metric_value: string;
        metric_trend: string;
        chart_points: number[];
        chart_labels: string[];
        cards: IconContentCard[];
    };
    testimonials: {
        badge: string;
        title: string;
        kicker: string;
        items: Array<{
            name: string;
            role: string;
            company: string;
            quote: string;
            avatar?: string | null;
        }>;
    };
    faq: {
        badge: string;
        title: string;
        kicker: string;
        items: Array<{ question: string; answer: string }>;
    };
    final_cta: {
        title: string;
        description: string;
        primary_button_label: string;
        primary_button_href: string;
        secondary_button_label: string;
        secondary_button_href: string;
    };
    footer: {
        brand_description: string;
        socials: ContentLink[];
        groups: Array<{ title: string; links: ContentLink[] }>;
        legal_title: string;
        legal_links: ContentLink[];
        copyright: string;
    };
};

type Props = {
    canRegister: boolean;
    demoAgentId: string | null;
    content: LandingContent;
};

type AuthProps = {
    auth: { user?: { id: number | string } | null };
};

function shortStarter(question: string): string {
    const trimmed = question.replace(/^\s*[A-Za-z]+\s*[:.]?\s*/, '').trim();

    if (trimmed.length <= 38) {
        return trimmed.replace(/[.?!]+$/, '?');
    }

    return trimmed.slice(0, 36).trim() + '…';
}

export default function PrismHome({
    canRegister,
    demoAgentId,
    content,
}: Props) {
    const { auth } = usePage<AuthProps>().props;
    const hasUser = Boolean(auth.user);
    const primaryHref = hasUser
        ? dashboard().url
        : canRegister
          ? register().url
          : login().url;

    const [openFaq, setOpenFaq] = useState<number>(0);
    const [tIdx, setTIdx] = useState<number>(0);

    const testimonialCount = content.testimonials.items.length;
    const testimonial =
        content.testimonials.items[tIdx % Math.max(1, testimonialCount)] ??
        null;

    const chatStarters = useMemo(
        () =>
            content.faq.items
                .slice(0, 4)
                .map((item) => shortStarter(item.question)),
        [content.faq.items],
    );

    const priceCard =
        content.chat_preview.plan_features.length > 0
            ? {
                  plan: content.chat_preview.plan_name,
                  price: content.chat_preview.plan_price,
                  interval: content.chat_preview.plan_interval,
                  features: content.chat_preview.plan_features.slice(0, 3),
                  cta: content.chat_preview.plan_button_label,
              }
            : null;

    return (
        <PrismLayout
            brand={content.brand_name}
            navItems={content.nav_items}
            footer={content.footer}
            canRegister={canRegister}
            hasUser={hasUser}
            title={`${content.brand_name} — ${content.hero.badge}`}
            description={content.hero.description}
        >
            <Hero
                hero={content.hero}
                chat={content.chat_preview}
                stats={content.stats}
                brandName={content.brand_name}
                demoAgentId={demoAgentId}
                starters={chatStarters}
                priceCard={priceCard}
            />

            <Stats stats={content.stats} />

            <Walkthrough video={content.video} />

            <UseCases
                whereItFits={content.where_it_fits}
                primaryHref={primaryHref}
            />

            <Features featureGrid={content.feature_grid} />

            <Tune control={content.control} />

            <Steps steps={content.steps} />

            <Analytics insights={content.insights} />

            <Testimonial
                testimonials={content.testimonials}
                current={testimonial}
                total={testimonialCount}
                onPrev={() =>
                    setTIdx(
                        (i) =>
                            (i - 1 + testimonialCount) %
                            Math.max(1, testimonialCount),
                    )
                }
                onNext={() =>
                    setTIdx((i) => (i + 1) % Math.max(1, testimonialCount))
                }
            />

            <FAQ
                faq={content.faq}
                open={openFaq}
                onToggle={(i) => setOpenFaq((cur) => (cur === i ? -1 : i))}
            />

            <CTA
                finalCta={content.final_cta}
                primaryHref={primaryHref}
                hasUser={hasUser}
            />
        </PrismLayout>
    );
}

function Hero({
    hero,
    chat,
    stats,
    brandName,
    demoAgentId,
    starters,
    priceCard,
}: {
    hero: LandingContent['hero'];
    chat: LandingContent['chat_preview'];
    stats: LandingContent['stats'];
    brandName: string;
    demoAgentId: string | null;
    starters: string[];
    priceCard: {
        plan: string;
        price: string;
        interval: string;
        features: string[];
        cta: string;
    } | null;
}) {
    const [domain, setDomain] = useState('');
    const [tryToken, setTryToken] = useState<string | null>(null);
    const [tryTitle, setTryTitle] = useState<string | null>(null);
    const [tryIntro, setTryIntro] = useState<string | null>(null);
    const [tryStatus, setTryStatus] = useState<
        'idle' | 'loading' | 'ready' | 'error'
    >('idle');
    const [tryError, setTryError] = useState<string | null>(null);
    const chatRef = useRef<PrismChatHandle | null>(null);

    const ask = (text: string) => {
        chatRef.current?.ask(text);
    };

    const onSubmit = async (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        const url = domain.trim();

        if (!url || tryStatus === 'loading') {
            return;
        }

        setTryStatus('loading');
        setTryError(null);

        try {
            const response = await fetch('/api/v1/widget/try-now', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                },
                credentials: 'omit',
                body: JSON.stringify({ url }),
            });

            const payload = await response.json();

            if (!response.ok) {
                const message =
                    payload?.error?.message ||
                    'We could not load that page. Try a different URL.';

                throw new Error(message);
            }

            setTryToken(payload.data.token);
            setTryTitle(payload.data.title);
            setTryIntro(payload.data.initial_message);
            setTryStatus('ready');
        } catch (error) {
            setTryStatus('error');
            setTryError(
                error instanceof Error && error.message
                    ? error.message
                    : 'We could not load that page. Try a different URL.',
            );
        }
    };

    return (
        <section className="hero" data-screen-label="01 Hero">
            <div className="wrap">
                <h1>
                    {hero.line_one} <span className="ital">{hero.accent}</span>{' '}
                    {hero.line_two_suffix} {hero.line_three_prefix}{' '}
                    <span className="ital">{hero.line_three_highlight}</span>{' '}
                    {hero.line_four_highlight}
                </h1>
                <p className="hero-sub">{hero.description}</p>

                <form className="hero-form" onSubmit={onSubmit}>
                    <div className="field">
                        <svg
                            width="16"
                            height="16"
                            viewBox="0 0 24 24"
                            fill="none"
                            stroke="currentColor"
                            strokeWidth="2"
                            aria-hidden="true"
                        >
                            <circle cx="12" cy="12" r="9" />
                            <path d="M3 12h18M12 3a14 14 0 0 1 0 18 14 14 0 0 1 0-18" />
                        </svg>
                        <input
                            type="url"
                            placeholder={hero.site_test_placeholder}
                            value={domain}
                            onChange={(e) => setDomain(e.target.value)}
                            aria-label={hero.site_test_label}
                            disabled={tryStatus === 'loading'}
                        />
                    </div>
                    <button
                        className="submit"
                        type="submit"
                        disabled={tryStatus === 'loading' || !domain.trim()}
                    >
                        {tryStatus === 'loading'
                            ? 'Reading…'
                            : hero.site_test_button_label}{' '}
                        <span className="arr">→</span>
                    </button>
                </form>

                {tryStatus === 'ready' && tryTitle ? (
                    <div
                        className="hero-status hero-status-ready"
                        role="status"
                    >
                        <span className="hero-status-dot" />
                        <span>
                            Loaded <b>{tryTitle}</b> — ask the chat below about
                            it.
                        </span>
                    </div>
                ) : tryStatus === 'error' && tryError ? (
                    <div className="hero-status hero-status-error" role="alert">
                        <span className="hero-status-dot" />
                        <span>{tryError}</span>
                    </div>
                ) : (
                    <div className="hero-tags">
                        <span className="pill-tag">
                            <span className="dot" />
                            {hero.site_test_helper || 'No credit card required'}
                        </span>
                        <span className="pill-tag">
                            <span className="dot" />
                            Instant preview
                        </span>
                        <span className="pill-tag live">
                            <span className="dot" />
                            {hero.live_demo_notice || 'Live demo on this page'}
                        </span>
                    </div>
                )}

                <div className="hero-mock hero-mock-light">
                    <div className="hero-mock-noise" aria-hidden="true" />
                    <HeroMockConnectors />

                    <div className="hero-grid">
                        <div className="hero-col hero-col-left">
                            <div className="hero-flag hero-flag-input">
                                <span className="hero-flag-dot" />
                                <span>
                                    <b>INPUT:</b> Visitor lands on your pricing
                                    page
                                </span>
                            </div>
                            <PricingMockCard
                                url={`${brandName.toLowerCase().replace(/\s+/g, '')}.com/pricing`}
                                heading="Pricing"
                                plans={[
                                    {
                                        name: 'Starter',
                                        price: null,
                                        highlight: false,
                                    },
                                    {
                                        name: 'Pro',
                                        price: `${chat.plan_price || '$199'}`,
                                        interval: `/${chat.plan_interval || 'month'}`,
                                        highlight: true,
                                    },
                                    {
                                        name: 'Enterprise',
                                        price: null,
                                        highlight: false,
                                    },
                                ]}
                                onPlanClick={(plan) =>
                                    ask(`Tell me about the ${plan} plan.`)
                                }
                            />
                            <button
                                type="button"
                                className="hero-chip hero-chip-visitor hero-chip-btn"
                                onClick={() => ask("What's included in Pro?")}
                                aria-label="Ask what is included in Pro"
                            >
                                <span className="hero-chip-ic">
                                    <svg
                                        width="16"
                                        height="16"
                                        viewBox="0 0 24 24"
                                        fill="none"
                                        stroke="currentColor"
                                        strokeWidth="2"
                                    >
                                        <circle cx="12" cy="8" r="4" />
                                        <path d="M4 21c1-4 5-6 8-6s7 2 8 6" />
                                    </svg>
                                </span>
                                <span>
                                    <b>Visitor asks</b>
                                    <span>what's included in Pro?</span>
                                </span>
                            </button>
                        </div>

                        <div className="hero-col hero-col-center">
                            <div className="hero-mock-stage">
                                <PrismChat
                                    key={tryToken ?? 'agent'}
                                    agentId={demoAgentId}
                                    title={
                                        tryToken && tryTitle
                                            ? tryTitle
                                            : chat.title
                                    }
                                    placeholder={
                                        tryToken
                                            ? 'Ask about this page…'
                                            : 'Ask anything…'
                                    }
                                    starters={starters}
                                    seedQuestion="I can help you find the right plan."
                                    seedAnswer="What would you like to know?"
                                    offlineMessage={hero.live_demo_notice}
                                    priceCard={priceCard}
                                    intent="Pricing Information"
                                    seedAsAssistantOnly
                                    handleRef={chatRef}
                                    tryToken={tryToken}
                                    tryIntro={tryIntro ?? undefined}
                                    tryStarters={
                                        tryToken
                                            ? [
                                                  'What is this page about?',
                                                  'Summarize this in 3 bullets.',
                                                  'What does it cost?',
                                              ]
                                            : undefined
                                    }
                                />
                            </div>
                        </div>

                        <div className="hero-col hero-col-right">
                            <div className="hero-flag hero-flag-output">
                                <span className="hero-flag-dot" />
                                <span>
                                    <b>OUTPUT:</b> Qualified &amp; synced
                                    <span className="hero-flag-sub">
                                        to your CRM
                                    </span>
                                </span>
                            </div>
                            <CrmCard
                                intent="Pricing Information"
                                planInterest={chat.plan_name || 'Pro Plan'}
                                engagementTime="2m 18s"
                                leadScore={4.5}
                            />
                            <NotifyCard />
                        </div>
                    </div>

                    {stats.length > 0 ? (
                        <div className="hero-stats" aria-hidden="true">
                            {[
                                {
                                    value: '2.6x',
                                    label: 'More meetings booked',
                                    icon: 0,
                                },
                                {
                                    value: '35%',
                                    label: 'Higher conversion',
                                    icon: 1,
                                },
                                {
                                    value: '24/7',
                                    label: 'AI sales coverage',
                                    icon: 2,
                                },
                                {
                                    value: '90%+',
                                    label: 'Intent detection accuracy',
                                    icon: 3,
                                },
                            ].map((s, i) => (
                                <div
                                    className="hero-stat"
                                    key={`${s.label}-${i}`}
                                >
                                    <span className="hero-stat-ic">
                                        {STAT_ICONS[s.icon] ?? STAT_ICONS[0]}
                                    </span>
                                    <span>
                                        <b>{s.value}</b>
                                        <span>{s.label}</span>
                                    </span>
                                </div>
                            ))}
                        </div>
                    ) : null}
                </div>
            </div>
        </section>
    );
}

const STAT_ICONS = [
    <svg
        key="trend"
        width="20"
        height="20"
        viewBox="0 0 24 24"
        fill="none"
        stroke="currentColor"
        strokeWidth="2"
    >
        <path d="M3 17l6-6 4 4 8-8" />
        <path d="M14 7h7v7" />
    </svg>,
    <svg
        key="target"
        width="20"
        height="20"
        viewBox="0 0 24 24"
        fill="none"
        stroke="currentColor"
        strokeWidth="2"
    >
        <circle cx="12" cy="12" r="9" />
        <circle cx="12" cy="12" r="5" />
        <circle cx="12" cy="12" r="1.5" fill="currentColor" />
    </svg>,
    <svg
        key="clock"
        width="20"
        height="20"
        viewBox="0 0 24 24"
        fill="none"
        stroke="currentColor"
        strokeWidth="2"
    >
        <circle cx="12" cy="12" r="9" />
        <path d="M12 7v5l3 2" />
    </svg>,
    <svg
        key="shield"
        width="20"
        height="20"
        viewBox="0 0 24 24"
        fill="none"
        stroke="currentColor"
        strokeWidth="2"
    >
        <path d="M12 3l8 3v6c0 5-3.5 8-8 9-4.5-1-8-4-8-9V6z" />
        <path d="M9 12l2 2 4-4" />
    </svg>,
];

function PricingMockCard({
    url,
    heading,
    plans,
    onPlanClick,
}: {
    url: string;
    heading: string;
    plans: Array<{
        name: string;
        price: string | null;
        interval?: string;
        highlight: boolean;
    }>;
    onPlanClick?: (plan: string) => void;
}) {
    return (
        <div className="pricing-mock">
            <div className="pricing-mock-url">
                <span className="d" />
                <span className="d" />
                <span className="d" />
                <span>{url}</span>
            </div>
            <div className="pricing-mock-heading">
                <span className="pricing-mock-title">{heading}</span>
                <span className="pricing-mock-bar" />
            </div>
            <div className="pricing-mock-plans">
                {plans.map((p) => (
                    <button
                        type="button"
                        key={p.name}
                        className={`pricing-mock-plan ${p.highlight ? 'is-highlight' : ''}`}
                        onClick={() => onPlanClick?.(p.name)}
                        aria-label={`Ask about the ${p.name} plan`}
                    >
                        <span className="pricing-mock-plan-name">{p.name}</span>
                        {p.price ? (
                            <span className="pricing-mock-plan-price">
                                {p.price}
                                {p.interval ? (
                                    <small>{p.interval}</small>
                                ) : null}
                            </span>
                        ) : (
                            <div className="pricing-mock-plan-lines">
                                <span />
                                <span style={{ width: '60%' }} />
                            </div>
                        )}
                        <span className="pricing-mock-plan-cta">
                            Get started
                        </span>
                    </button>
                ))}
            </div>
        </div>
    );
}

function CrmCard({
    intent,
    planInterest,
    engagementTime,
    leadScore,
}: {
    intent: string;
    planInterest: string;
    engagementTime: string;
    leadScore: number;
}) {
    return (
        <div className="crm-mock" aria-hidden="true">
            <div className="crm-mock-head">
                <span className="crm-logo crm-logo-wp">
                    <svg
                        width="20"
                        height="20"
                        viewBox="0 0 24 24"
                        fill="currentColor"
                    >
                        <path d="M12 2a10 10 0 1 0 10 10A10 10 0 0 0 12 2zm-8.5 10A8.5 8.5 0 0 1 5 8l4.65 12.74A8.51 8.51 0 0 1 3.5 12zm8.5 8.5a8.46 8.46 0 0 1-2.4-.35l2.55-7.4 2.61 7.16a.78.78 0 0 0 .06.11 8.49 8.49 0 0 1-2.82.48zm1.17-12.5c.51 0 1-.07 1-.07s-.46-.05.59-.05c.51 0 .45.7 0 .73s-.74.62-.74.62l1 2.85.93-2.85s-.71-.59-.71-.62 0-.7.51-.7c.94 0 1.43.05 1.43.05a.66.66 0 0 1 .43.06.59.59 0 0 0-.07-.06l-2.34 6.93-1.66-4.5-1.55 4.5L9.42 8.4a.62.62 0 0 1 .39-.06s.51.05 1 .05.93-.05.93-.05c.46 0 .51.7 0 .73s-.69.05-.69.05l3.07 9.16 1.65-4.93-1.18-3.23s-.49-.06-1-.06c-.4 0-.43-.7.07-.7zm6.13 4a8.5 8.5 0 0 1-3.15 11.43l2.59-7.5c.5-1.21.65-2.17.65-3.04a8 8 0 0 0-.09-1z" />
                    </svg>
                </span>
                <span className="crm-mock-name">WordPress</span>
            </div>
            <div className="crm-mock-body">
                <div className="crm-mock-row crm-mock-row-head">
                    <span>New qualified lead</span>
                    <span className="crm-synced">
                        <svg
                            width="12"
                            height="12"
                            viewBox="0 0 24 24"
                            fill="none"
                            stroke="currentColor"
                            strokeWidth="3"
                        >
                            <path d="M5 12l5 5L20 7" />
                        </svg>
                        Synced
                    </span>
                </div>
                <div className="crm-detail">
                    <div className="crm-detail-row">
                        <span className="crm-detail-label">Intent</span>
                        <span className="crm-detail-value crm-detail-value-accent">
                            {intent}
                        </span>
                    </div>
                    <div className="crm-detail-row">
                        <span className="crm-detail-label">Plan Interest</span>
                        <span className="crm-detail-value">{planInterest}</span>
                    </div>
                    <div className="crm-detail-row">
                        <span className="crm-detail-label">
                            Engagement time
                        </span>
                        <span className="crm-detail-value">
                            {engagementTime}
                        </span>
                    </div>
                    <div className="crm-detail-row">
                        <span className="crm-detail-label">Lead Score</span>
                        <span className="crm-detail-stars">
                            {renderStars(leadScore)}
                        </span>
                    </div>
                </div>
            </div>
        </div>
    );
}

function renderStars(score: number) {
    const full = Math.floor(score);
    const half = score - full >= 0.5;
    const out = [];

    for (let i = 0; i < 5; i++) {
        if (i < full) {
            out.push(<Star key={i} fill="#fbbf24" />);
        } else if (i === full && half) {
            out.push(<Star key={i} fill="#fbbf24" half />);
        } else {
            out.push(<Star key={i} fill="rgba(255,255,255,0.18)" />);
        }
    }

    return out;
}

function Star({ fill, half }: { fill: string; half?: boolean }) {
    return (
        <svg width="14" height="14" viewBox="0 0 24 24" aria-hidden="true">
            <defs>
                {half ? (
                    <linearGradient id={`star-half-${fill}`}>
                        <stop offset="50%" stopColor={fill} />
                        <stop offset="50%" stopColor="rgba(255,255,255,0.18)" />
                    </linearGradient>
                ) : null}
            </defs>
            <path
                d="M12 2l3 6 6 .9-4.5 4.4 1 6.2L12 17l-5.5 3.5 1-6.2L3 9.9 9 8.9z"
                fill={half ? `url(#star-half-${fill})` : fill}
            />
        </svg>
    );
}

function NotifyCard() {
    return (
        <div className="notify-card" aria-hidden="true">
            <span className="notify-card-ic">
                <svg
                    width="18"
                    height="18"
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    strokeWidth="2"
                >
                    <path d="M6 8a6 6 0 0 1 12 0c0 7 3 7 3 9H3c0-2 3-2 3-9z" />
                    <path d="M10 21a2 2 0 0 0 4 0" />
                </svg>
                <span className="notify-dot" />
            </span>
            <div className="notify-card-text">
                <b>Your team gets notified</b>
                <span>and can follow up</span>
            </div>
            <div className="notify-avatars">
                <span className="avatar avatar-a" />
                <span className="avatar avatar-b" />
                <span className="avatar avatar-c" />
                <span className="notify-more">+3</span>
            </div>
        </div>
    );
}

function HeroMockConnectors() {
    return (
        <svg
            className="hero-connectors"
            viewBox="0 0 1080 620"
            preserveAspectRatio="none"
            aria-hidden="true"
        >
            <defs>
                <linearGradient id="prism-conn-l" x1="0" y1="0" x2="1" y2="0">
                    <stop offset="0%" stopColor="rgba(180,140,255,0)" />
                    <stop offset="100%" stopColor="rgba(180,140,255,0.6)" />
                </linearGradient>
                <linearGradient id="prism-conn-r" x1="0" y1="0" x2="1" y2="0">
                    <stop offset="0%" stopColor="rgba(255,150,90,0.6)" />
                    <stop offset="100%" stopColor="rgba(255,150,90,0)" />
                </linearGradient>
            </defs>
            <path
                d="M 240 250 Q 360 220 420 280"
                fill="none"
                stroke="url(#prism-conn-l)"
                strokeWidth="1.2"
                strokeDasharray="3 4"
            />
            <path
                d="M 660 280 Q 740 230 860 250"
                fill="none"
                stroke="url(#prism-conn-r)"
                strokeWidth="1.2"
                strokeDasharray="3 4"
            />
            <circle cx="420" cy="280" r="3" fill="#b18bff" />
            <circle cx="660" cy="280" r="3" fill="#ff9c5a" />
        </svg>
    );
}

function Stats({ stats }: { stats: LandingContent['stats'] }) {
    return (
        <section className="stats" aria-label="Key results">
            <div className="wrap">
                <div className="stats-grid">
                    {stats.map((s, idx) => (
                        <div key={`${s.label}-${idx}`} className="stat">
                            <div className="k">{s.label}</div>
                            <div className="v">{s.value}</div>
                            <div className="l">{s.label}</div>
                        </div>
                    ))}
                </div>
            </div>
        </section>
    );
}

function Walkthrough({ video }: { video: LandingContent['video'] }) {
    return (
        <section
            className="sec"
            id="product"
            data-screen-label="02 Walkthrough"
        >
            <div className="wrap">
                <div className="sec-hd">
                    <div>
                        <span className="eyebrow">{video.badge}</span>
                        <h2>{video.title}</h2>
                    </div>
                    <a
                        className="btn btn-ghost btn-sm"
                        href={video.href}
                        target="_blank"
                        rel="noopener noreferrer"
                    >
                        See live demo <span className="arr">→</span>
                    </a>
                </div>

                <div className="video-wrap">
                    <div className="video" aria-label="Product walkthrough">
                        <span className="video-tag">
                            {video.duration_label}
                        </span>
                        <button
                            type="button"
                            className="video-play"
                            aria-label={video.button_label}
                        >
                            ▶
                        </button>
                        <div className="video-cap">
                            <span>00:00 / {video.timecode}</span>
                            <span>{video.scene_label}</span>
                        </div>
                    </div>

                    <aside className="video-meta">
                        <h4>{video.footer_title || "What you'll see"}</h4>
                        <ul>
                            {video.bullets.map((b) => (
                                <li key={b}>
                                    <span className="ic">
                                        <svg
                                            width="12"
                                            height="12"
                                            viewBox="0 0 24 24"
                                            fill="none"
                                            stroke="currentColor"
                                            strokeWidth="2.4"
                                            aria-hidden="true"
                                        >
                                            <path d="M5 12l5 5L20 7" />
                                        </svg>
                                    </span>
                                    <span>{b}</span>
                                </li>
                            ))}
                        </ul>
                        <a
                            className="btn btn-light btn-sm more"
                            href={video.href}
                            style={{ alignSelf: 'flex-start' }}
                        >
                            {video.button_label} <span className="arr">→</span>
                        </a>
                    </aside>
                </div>
            </div>
        </section>
    );
}

function UseCases({
    whereItFits,
    primaryHref,
}: {
    whereItFits: LandingContent['where_it_fits'];
    primaryHref: string;
}) {
    return (
        <section
            className="sec"
            style={{ paddingTop: 0 }}
            data-screen-label="03 Use cases"
        >
            <div className="wrap">
                <div className="sec-hd">
                    <div>
                        <span className="eyebrow">{whereItFits.badge}</span>
                        <h2>{whereItFits.title}</h2>
                    </div>
                    <Link className="btn btn-ghost btn-sm" href={primaryHref}>
                        Get started <span className="arr">→</span>
                    </Link>
                </div>

                <div className="uc-grid">
                    {whereItFits.cards.map((card) => (
                        <article key={card.title} className="uc">
                            <div className="uc-ic">
                                <svg
                                    width="20"
                                    height="20"
                                    viewBox="0 0 24 24"
                                    fill="none"
                                    stroke="currentColor"
                                    strokeWidth="1.7"
                                >
                                    <rect
                                        x="3"
                                        y="4"
                                        width="18"
                                        height="16"
                                        rx="2"
                                    />
                                    <path d="M3 9h18M8 14h3M8 17h6" />
                                </svg>
                            </div>
                            <h3>{card.title}</h3>
                            <p>{card.description}</p>
                        </article>
                    ))}
                </div>
            </div>
        </section>
    );
}

function Features({
    featureGrid,
}: {
    featureGrid: LandingContent['feature_grid'];
}) {
    return (
        <section
            className="sec"
            style={{ paddingTop: 0 }}
            data-screen-label="04 Features"
        >
            <div className="wrap">
                <div className="sec-hd-center">
                    <span className="eyebrow">{featureGrid.badge}</span>
                    <h2>{featureGrid.title}</h2>
                </div>

                <div className="features">
                    {featureGrid.cards.map((card, idx) => (
                        <article key={card.title} className="feat">
                            <div className="ic">
                                <svg
                                    width="18"
                                    height="18"
                                    viewBox="0 0 24 24"
                                    fill="none"
                                    stroke="currentColor"
                                    strokeWidth="1.8"
                                >
                                    <circle cx="12" cy="12" r="3" />
                                    <path d="M12 1v6M12 17v6M4.2 4.2l4.3 4.3M15.5 15.5l4.3 4.3M1 12h6M17 12h6" />
                                </svg>
                            </div>
                            <h4>{card.title}</h4>
                            <p>{card.description}</p>
                            <div className="tag">
                                {String(idx + 1).padStart(2, '0')} /{' '}
                                {card.title.split(' ')[0]}
                            </div>
                        </article>
                    ))}
                </div>
            </div>
        </section>
    );
}

function Tune({ control }: { control: LandingContent['control'] }) {
    return (
        <section
            className="sec"
            style={{ paddingTop: 0 }}
            data-screen-label="05 Tune"
        >
            <div className="wrap">
                <div className="sec-hd">
                    <div>
                        <span className="eyebrow">{control.badge}</span>
                        <h2>{control.title}</h2>
                    </div>
                </div>

                <div className="tune-grid">
                    <div className="tune-left">
                        <h4>{control.title}</h4>
                        <p>{control.description}</p>
                        <div className="tune-points">
                            {control.callouts.map((callout) => (
                                <div className="tune-pt" key={callout.title}>
                                    <div className="ic">
                                        <svg
                                            width="14"
                                            height="14"
                                            viewBox="0 0 24 24"
                                            fill="none"
                                            stroke="currentColor"
                                            strokeWidth="2"
                                        >
                                            <rect
                                                x="3"
                                                y="11"
                                                width="18"
                                                height="11"
                                                rx="2"
                                            />
                                            <path d="M7 11V7a5 5 0 0 1 10 0v4" />
                                        </svg>
                                    </div>
                                    <div>
                                        <b>{callout.title}</b>
                                        <span className="t-sub">
                                            {callout.description}
                                        </span>
                                    </div>
                                </div>
                            ))}
                        </div>
                    </div>
                    <div className="tune-right">
                        <div className="settings">
                            <div className="settings-hd">
                                <h5>{control.settings_card_title}</h5>
                                <span className="badge">/ pricing-page</span>
                            </div>
                            {control.settings_rows.map((row) => (
                                <div className="settings-row" key={row.label}>
                                    <div>
                                        <div className="lbl">{row.label}</div>
                                        <div className="hint">{row.hint}</div>
                                    </div>
                                    <div className="field">{row.value}</div>
                                </div>
                            ))}
                            <div className="settings-foot">
                                <button
                                    type="button"
                                    className="btn btn-sm btn-light"
                                    tabIndex={-1}
                                >
                                    {control.cancel_label}
                                </button>
                                <button
                                    type="button"
                                    className="btn btn-sm btn-primary"
                                    tabIndex={-1}
                                >
                                    {control.save_button_label}
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>
    );
}

function Steps({ steps }: { steps: LandingContent['steps'] }) {
    return (
        <section
            className="sec"
            id="how"
            style={{ paddingTop: 0 }}
            data-screen-label="06 Steps"
        >
            <div className="wrap">
                <div className="sec-hd-center">
                    <span className="eyebrow">{steps.badge}</span>
                    <h2>{steps.title}</h2>
                </div>

                <div className="steps-grid">
                    {steps.items.map((step, idx) => (
                        <article className="step" key={step.title}>
                            <span className="step-n">
                                <span className="step-num">{idx + 1}</span>
                                Step {['one', 'two', 'three'][idx] || idx + 1}
                            </span>
                            <h3>{step.title}</h3>
                            <p>{step.description}</p>
                        </article>
                    ))}
                </div>
            </div>
        </section>
    );
}

function buildSparkline(points: number[]) {
    const resolved = points.length > 0 ? points : [10, 20, 30, 40, 60, 80, 100];
    const w = 600;
    const h = 160;
    const max = Math.max(...resolved, 1);
    const min = Math.min(...resolved, 0);
    const range = Math.max(max - min, 1);
    const stepX = resolved.length > 1 ? w / (resolved.length - 1) : 0;
    const coords = resolved.map((p, i) => ({
        x: i * stepX,
        y: h - ((p - min) / range) * h,
    }));
    const line = coords
        .map(
            (c, i) =>
                `${i === 0 ? 'M' : 'L'}${c.x.toFixed(2)},${c.y.toFixed(2)}`,
        )
        .join(' ');
    const area = `${line} L${w},${h} L0,${h} Z`;

    return { line, area, w, h, last: coords[coords.length - 1] };
}

function Analytics({ insights }: { insights: LandingContent['insights'] }) {
    const spark = useMemo(
        () => buildSparkline(insights.chart_points),
        [insights.chart_points],
    );

    return (
        <section
            className="sec"
            style={{ paddingTop: 0 }}
            data-screen-label="07 Signal"
        >
            <div className="wrap">
                <div className="sec-hd">
                    <div>
                        <span className="eyebrow">{insights.badge}</span>
                        <h2>{insights.chart_title}</h2>
                    </div>
                </div>

                <div className="ana-grid">
                    <div className="ana-main">
                        <div
                            style={{
                                display: 'flex',
                                justifyContent: 'space-between',
                                alignItems: 'end',
                                gap: 16,
                                flexWrap: 'wrap',
                            }}
                        >
                            <div>
                                <span className="eyebrow">
                                    {insights.metric_label}
                                </span>
                                <div className="big">
                                    {insights.metric_value}
                                </div>
                                <div className="delta">
                                    {insights.metric_trend}
                                </div>
                            </div>
                            <div className="legend">
                                <div>{insights.chart_description}</div>
                            </div>
                        </div>
                        <div className="ana-chart">
                            <svg
                                viewBox={`0 0 ${spark.w} ${spark.h + 20}`}
                                preserveAspectRatio="none"
                                width="100%"
                                height="100%"
                                style={{ position: 'absolute', inset: 0 }}
                            >
                                <defs>
                                    <linearGradient
                                        id="prism-agrad"
                                        x1="0"
                                        y1="0"
                                        x2="0"
                                        y2="1"
                                    >
                                        <stop
                                            offset="0%"
                                            stopColor="#8C5BFF"
                                            stopOpacity=".28"
                                        />
                                        <stop
                                            offset="100%"
                                            stopColor="#8C5BFF"
                                            stopOpacity="0"
                                        />
                                    </linearGradient>
                                    <pattern
                                        id="prism-agrid"
                                        width="60"
                                        height="36"
                                        patternUnits="userSpaceOnUse"
                                    >
                                        <path
                                            d="M 60 0 L 0 0 0 36"
                                            fill="none"
                                            stroke="#F2F2F0"
                                            strokeWidth=".7"
                                        />
                                    </pattern>
                                </defs>
                                <rect
                                    width={spark.w}
                                    height={spark.h}
                                    fill="url(#prism-agrid)"
                                />
                                <path
                                    d={spark.line}
                                    fill="none"
                                    stroke="#6B4FE8"
                                    strokeWidth="2"
                                />
                                <path d={spark.area} fill="url(#prism-agrad)" />
                                {spark.last ? (
                                    <circle
                                        cx={spark.last.x}
                                        cy={spark.last.y}
                                        r="5"
                                        fill="#6B4FE8"
                                        stroke="#fff"
                                        strokeWidth="2"
                                    />
                                ) : null}
                            </svg>
                            <div className="ana-x">
                                {insights.chart_labels.map((l) => (
                                    <span key={l}>{l}</span>
                                ))}
                            </div>
                        </div>
                    </div>

                    <div className="ana-side">
                        {insights.cards.map((card) => (
                            <div className="ana-card" key={card.title}>
                                <div className="ic">
                                    <svg
                                        width="14"
                                        height="14"
                                        viewBox="0 0 24 24"
                                        fill="none"
                                        stroke="currentColor"
                                        strokeWidth="2"
                                    >
                                        <path d="M5 12l5 5L20 7" />
                                    </svg>
                                </div>
                                <h5>{card.title}</h5>
                                <p>{card.description}</p>
                                <span className="more">View report</span>
                            </div>
                        ))}
                    </div>
                </div>
            </div>
        </section>
    );
}

function Testimonial({
    testimonials,
    current,
    total,
    onPrev,
    onNext,
}: {
    testimonials: LandingContent['testimonials'];
    current: LandingContent['testimonials']['items'][number] | null;
    total: number;
    onPrev: () => void;
    onNext: () => void;
}) {
    if (!current) {
        return null;
    }

    return (
        <section
            className="sec"
            style={{ paddingTop: 0 }}
            data-screen-label="08 Testimonial"
        >
            <div className="wrap">
                <div className="sec-hd-center">
                    <span className="eyebrow">{testimonials.badge}</span>
                    <h2>{testimonials.title}</h2>
                </div>

                <div className="testi">
                    <div className="testi-photo">
                        <span>{current.name.charAt(0)}</span>
                    </div>
                    <div className="testi-body">
                        <span className="qmark">&ldquo;</span>
                        <blockquote>{current.quote}</blockquote>
                        <div className="testi-foot">
                            <div className="testi-who">
                                <b>{current.name}</b>
                                <span>
                                    {current.role} · {current.company}
                                </span>
                            </div>
                            {total > 1 ? (
                                <div className="testi-nav">
                                    <button
                                        type="button"
                                        aria-label="Previous"
                                        onClick={onPrev}
                                    >
                                        ←
                                    </button>
                                    <button
                                        type="button"
                                        aria-label="Next"
                                        onClick={onNext}
                                    >
                                        →
                                    </button>
                                </div>
                            ) : null}
                        </div>
                    </div>
                </div>
            </div>
        </section>
    );
}

function FAQ({
    faq,
    open,
    onToggle,
}: {
    faq: LandingContent['faq'];
    open: number;
    onToggle: (idx: number) => void;
}) {
    return (
        <section
            className="sec"
            style={{ paddingTop: 0 }}
            data-screen-label="09 FAQ"
        >
            <div className="wrap">
                <div className="faq-grid">
                    <aside className="faq-side">
                        <span className="eyebrow">{faq.badge}</span>
                        <h2>{faq.title}</h2>
                        <p>{faq.kicker}</p>
                    </aside>

                    <div className="faq">
                        {faq.items.map((item, i) => (
                            <div
                                key={item.question}
                                className={`faq-item ${open === i ? 'open' : ''}`}
                            >
                                <button
                                    type="button"
                                    className="faq-q"
                                    onClick={() => onToggle(i)}
                                    aria-expanded={open === i}
                                >
                                    <span>{item.question}</span>
                                    <span className="sign">
                                        {open === i ? '–' : '+'}
                                    </span>
                                </button>
                                {open === i ? (
                                    <div className="faq-a">{item.answer}</div>
                                ) : null}
                            </div>
                        ))}
                    </div>
                </div>
            </div>
        </section>
    );
}

function CTA({
    finalCta,
    primaryHref,
    hasUser,
}: {
    finalCta: LandingContent['final_cta'];
    primaryHref: string;
    hasUser: boolean;
}) {
    const label = hasUser ? 'Open dashboard' : 'Get started';

    return (
        <section className="wrap" data-screen-label="10 CTA">
            <div className="final">
                <span className="eyebrow">Ready when you are</span>
                <h2>{finalCta.title}</h2>
                <p>{finalCta.description}</p>
                <div className="final-actions">
                    <Link href={primaryHref} className="btn btn-primary">
                        {label} <span aria-hidden="true">→</span>
                    </Link>
                </div>
            </div>
        </section>
    );
}
