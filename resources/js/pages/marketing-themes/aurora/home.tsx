import { Link, usePage } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import type { CSSProperties } from 'react';

import { dashboard, login, register } from '@/routes';

import AuroraLayout from './_layout';
import InteractiveChat from './interactive-chat';

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

function resolveHref(
    href: string,
    primaryHref:
        | ReturnType<typeof dashboard>
        | ReturnType<typeof register>
        | ReturnType<typeof login>,
): string {
    if (href === '__primary__') {
        return typeof primaryHref === 'string' ? primaryHref : primaryHref.url;
    }

    return href;
}

function initials(name: string): string {
    return name
        .split(/\s+/)
        .filter(Boolean)
        .map((part) => part[0])
        .join('')
        .slice(0, 2)
        .toUpperCase();
}

function buildSparkPath(points: number[]) {
    const resolved = points.length > 0 ? points : [0];
    const maxY = Math.max(...resolved, 1);
    const w = 600;
    const h = 220;
    const stepX = resolved.length > 1 ? w / (resolved.length - 1) : 0;
    const coords = resolved.map((p, i) => ({
        x: i * stepX,
        y: h - (p / maxY) * h,
    }));
    const line = coords
        .map(
            (c, i) =>
                `${i === 0 ? 'M' : 'L'} ${c.x.toFixed(2)} ${c.y.toFixed(2)}`,
        )
        .join(' ');
    const area = `${line} L ${w} ${h} L 0 ${h} Z`;
    const dots = coords.filter(
        (_, i) => i % Math.max(1, Math.round(coords.length / 4)) === 0,
    );

    return { line, area, dots, w, h };
}

function shortStarter(question: string): string {
    const trimmed = question.replace(/^\s*[A-Za-z]+\s*[:.]?\s*/, '').trim();

    if (trimmed.length <= 38) {
        return trimmed.replace(/[.?!]+$/, '?');
    }

    return trimmed.slice(0, 36).trim() + '…';
}

export default function AuroraHome({
    canRegister,
    demoAgentId,
    content,
}: Props) {
    const { auth } = usePage<AuthProps>().props;
    const hasUser = Boolean(auth.user);
    const primaryHref = hasUser
        ? dashboard()
        : canRegister
          ? register()
          : login();

    const [openFaq, setOpenFaq] = useState<number>(0);
    const [tIdx, setTIdx] = useState<number>(0);

    const testimonialCount = content.testimonials.items.length;
    const testimonial =
        content.testimonials.items[tIdx % Math.max(1, testimonialCount)] ??
        null;

    const stats = content.stats;
    const spark = useMemo(
        () => buildSparkPath(content.insights.chart_points),
        [content.insights.chart_points],
    );

    const ticker = useMemo(() => {
        const labels = stats
            .slice(0, 3)
            .map((s) => `${s.value} · ${s.label.toUpperCase()}`);

        return [
            `LIVE DEMO — ASK ${content.brand_name.toUpperCase()} ANYTHING`,
            ...labels,
            content.hero.site_test_helper.toUpperCase(),
        ];
    }, [content.brand_name, content.hero.site_test_helper, stats]);

    const chatStarters = useMemo(
        () =>
            content.faq.items
                .slice(0, 4)
                .map((item) => shortStarter(item.question)),
        [content.faq.items],
    );

    return (
        <AuroraLayout
            brand={content.brand_name}
            navItems={content.nav_items}
            footer={content.footer}
            ticker={ticker}
            title={`${content.brand_name} — ${content.hero.badge}`}
            description={content.hero.description}
        >
            <Hero
                hero={content.hero}
                chat={content.chat_preview}
                stats={stats}
                demoAgentId={demoAgentId}
                starters={chatStarters}
            />

            <Stats stats={stats} />

            <Walkthrough video={content.video} />

            <Pages whereItFits={content.where_it_fits} />

            <Features featureGrid={content.feature_grid} />

            <Tune control={content.control} brandName={content.brand_name} />

            <Steps steps={content.steps} />

            <Insights insights={content.insights} spark={spark} />

            <Testimonial
                testimonials={content.testimonials}
                current={testimonial}
                index={tIdx}
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
                primaryHref={resolveHref(
                    content.final_cta.primary_button_href,
                    primaryHref,
                )}
                secondaryHref={resolveHref(
                    content.final_cta.secondary_button_href,
                    primaryHref,
                )}
            />
        </AuroraLayout>
    );
}

function Hero({
    hero,
    chat,
    stats,
    demoAgentId,
    starters,
}: {
    hero: LandingContent['hero'];
    chat: LandingContent['chat_preview'];
    stats: LandingContent['stats'];
    demoAgentId: string | null;
    starters: string[];
}) {
    const [first, second, third] = stats;

    return (
        <section className="hero" data-screen-label="01 Hero">
            <div className="wrap">
                <div className="hero-grid">
                    <div className="hero-lead">
                        <span className="eyebrow">
                            <span className="dot" />
                            <span className="idx">S/01</span>
                            {hero.badge.toUpperCase()}
                        </span>
                        <h1 className="h-display">
                            {hero.line_one}{' '}
                            <span className="signal-mark">{hero.accent}</span>{' '}
                            {hero.line_two_suffix} {hero.line_three_prefix}{' '}
                            {hero.line_three_highlight}{' '}
                            {hero.line_four_highlight}
                        </h1>
                        <p className="hero-sub">{hero.description}</p>
                        <div className="hero-meta">
                            {first && (
                                <div>
                                    {first.label}
                                    <strong>{first.value}</strong>
                                </div>
                            )}
                            {second && (
                                <div>
                                    {second.label}
                                    <strong>{second.value}</strong>
                                </div>
                            )}
                            {third && (
                                <div>
                                    {third.label}
                                    <strong>{third.value}</strong>
                                </div>
                            )}
                        </div>
                    </div>
                    <div className="hero-right">
                        <InteractiveChat
                            agentId={demoAgentId}
                            title={chat.title}
                            badge={chat.badge}
                            placeholder={hero.site_test_placeholder}
                            starters={starters}
                            seedQuestion={chat.question}
                            seedAnswer={chat.answer}
                            offlineMessage={hero.live_demo_notice}
                        />
                    </div>
                </div>
            </div>
        </section>
    );
}

function Stats({ stats }: { stats: LandingContent['stats'] }) {
    return (
        <section data-screen-label="02 Stats" style={{ paddingBottom: 0 }}>
            <div className="wrap">
                <div className="stats">
                    {stats.map((stat, idx) => (
                        <div key={`${stat.label}-${idx}`} className="stat">
                            <span className="corner" />
                            <div className="label">
                                <span>{`0${idx + 1} / ${stat.label
                                    .split(' ')
                                    .slice(0, 2)
                                    .join(' ')
                                    .toUpperCase()}`}</span>
                                <span>↗</span>
                            </div>
                            <div className="num">{stat.value}</div>
                            <div className="desc">{stat.label}</div>
                        </div>
                    ))}
                </div>
            </div>
        </section>
    );
}

function Walkthrough({ video }: { video: LandingContent['video'] }) {
    return (
        <section className="section" data-screen-label="03 Walkthrough">
            <div className="wrap">
                <div className="split">
                    <div className="split-text">
                        <span className="eyebrow">
                            <span className="dot" />
                            <span className="idx">S/03</span>
                            {video.badge.toUpperCase()}
                        </span>
                        <h2 className="h-section" style={{ marginTop: 24 }}>
                            {video.title}
                        </h2>
                        <p className="lead">{video.description}</p>
                        <ul className="bullets">
                            {video.bullets.map((bullet) => (
                                <li key={bullet}>
                                    <span className="pip" />
                                    <span>{bullet}</span>
                                </li>
                            ))}
                        </ul>
                    </div>
                    <div>
                        <a
                            className="video-frame"
                            href={video.href}
                            target="_blank"
                            rel="noopener noreferrer"
                        >
                            <div className="chrome">
                                <div className="dots">
                                    <span />
                                    <span />
                                    <span />
                                </div>
                                <span>
                                    {video.duration_label.toUpperCase()} /{' '}
                                    {video.tag_label.toUpperCase()}
                                </span>
                                <span>{video.scene_label.toUpperCase()}</span>
                            </div>
                            <div className="canvas">
                                <span
                                    className="video-play"
                                    aria-label={video.button_label}
                                >
                                    <svg
                                        width="28"
                                        height="32"
                                        viewBox="0 0 28 32"
                                        fill="currentColor"
                                    >
                                        <path d="M0 0 L28 16 L0 32 Z" />
                                    </svg>
                                </span>
                            </div>
                            <div className="runtime">
                                00:00 / {video.timecode}
                            </div>
                            <div className="runtime">
                                {video.button_label.toUpperCase()}
                            </div>
                        </a>
                    </div>
                </div>
            </div>
        </section>
    );
}

function Pages({
    whereItFits,
}: {
    whereItFits: LandingContent['where_it_fits'];
}) {
    return (
        <section
            className="section"
            data-screen-label="04 Pages"
            style={{ paddingTop: 0 }}
        >
            <div className="wrap">
                <div className="pages-head">
                    <div>
                        <span className="eyebrow">
                            <span className="dot" />
                            <span className="idx">S/04</span>
                            {whereItFits.badge.toUpperCase()}
                        </span>
                        <h2 className="h-section">{whereItFits.title}</h2>
                    </div>
                </div>
                <div className="pages-grid">
                    {whereItFits.cards.map((card, idx) => (
                        <div key={card.title} className="page-card">
                            <div className="page-num">
                                {`0${idx + 1}`} / {card.title.toUpperCase()}
                            </div>
                            <h3 className="h-card">{card.title}</h3>
                            <p>{card.description}</p>
                            <div className="page-illust">
                                <PagePreview kind={idx} />
                            </div>
                            <div className="page-arrow">→</div>
                        </div>
                    ))}
                </div>
            </div>
        </section>
    );
}

function PagePreview({ kind }: { kind: number }) {
    const cell = (w: string, color: string): CSSProperties => ({
        height: 10,
        width: w,
        background: color,
        marginBottom: 6,
    });

    return (
        <div style={{ padding: 14, height: '100%' }}>
            {kind === 0 && (
                <>
                    <div style={cell('40%', 'var(--ink)')} />
                    <div style={cell('70%', 'var(--hairline)')} />
                    <div style={{ display: 'flex', gap: 6, marginTop: 10 }}>
                        <div
                            style={{
                                flex: 1,
                                height: 46,
                                border: '1px solid var(--hairline)',
                            }}
                        />
                        <div
                            style={{
                                flex: 1,
                                height: 46,
                                border: '1px solid var(--ink)',
                                background: 'var(--signal)',
                            }}
                        />
                        <div
                            style={{
                                flex: 1,
                                height: 46,
                                border: '1px solid var(--hairline)',
                            }}
                        />
                    </div>
                </>
            )}
            {kind === 1 && (
                <div style={{ display: 'flex', gap: 8 }}>
                    <div
                        style={{
                            width: 50,
                            height: 50,
                            background: 'var(--ink)',
                        }}
                    />
                    <div style={{ flex: 1 }}>
                        <div style={cell('80%', 'var(--ink)')} />
                        <div style={cell('60%', 'var(--hairline)')} />
                        <div style={cell('40%', 'var(--signal)')} />
                    </div>
                </div>
            )}
            {kind === 2 && (
                <>
                    <div style={cell('30%', 'var(--ink)')} />
                    <div style={cell('90%', 'var(--hairline)')} />
                    <div style={cell('85%', 'var(--hairline)')} />
                    <div style={cell('50%', 'var(--hairline)')} />
                </>
            )}
        </div>
    );
}

function Features({
    featureGrid,
}: {
    featureGrid: LandingContent['feature_grid'];
}) {
    return (
        <section className="dark-section" data-screen-label="05 Features">
            <div className="wrap">
                <div className="feat-head">
                    <div>
                        <span className="eyebrow">
                            <span className="dot" />
                            <span className="idx">S/05</span>
                            {featureGrid.badge.toUpperCase()}
                        </span>
                        <h2 className="h-section">{featureGrid.title}</h2>
                    </div>
                </div>
                <div className="features">
                    {featureGrid.cards.map((card, idx) => {
                        const code = card.title
                            .split(' ')[0]
                            .slice(0, 3)
                            .toUpperCase();

                        return (
                            <div key={card.title} className="feat">
                                <div className="feat-icon">{code}</div>
                                <h3>{card.title}</h3>
                                <p>{card.description}</p>
                                <div className="meta">
                                    <span>MODULE / {code}</span>
                                    <span
                                        className={
                                            idx % 2 === 1 ? 'live' : undefined
                                        }
                                    >
                                        {idx % 2 === 1 ? 'LIVE' : 'READY'}
                                    </span>
                                </div>
                            </div>
                        );
                    })}
                </div>
            </div>
        </section>
    );
}

function Tune({
    control,
    brandName,
}: {
    control: LandingContent['control'];
    brandName: string;
}) {
    return (
        <section className="section" data-screen-label="06 Tune">
            <div className="wrap">
                <div className="tune">
                    <div className="tune-text">
                        <span className="eyebrow">
                            <span className="dot" />
                            <span className="idx">S/06</span>
                            {control.badge.toUpperCase()}
                        </span>
                        <h2 className="h-section" style={{ marginTop: 24 }}>
                            {control.title}
                        </h2>
                        <p className="lead">{control.description}</p>
                        {control.callouts.map((callout) => (
                            <div key={callout.title} className="cred">
                                <div className="lock">★</div>
                                <div>
                                    <strong>{callout.title}</strong>
                                    <span>{callout.description}</span>
                                </div>
                            </div>
                        ))}
                    </div>
                    <div>
                        <div className="settings">
                            <div className="settings-head">
                                <span>
                                    {control.settings_card_title.toUpperCase()}
                                </span>
                                <span className="right">
                                    {brandName.toUpperCase()} / CONFIG
                                </span>
                            </div>
                            {control.settings_rows.map((row, idx) => (
                                <div
                                    key={`${row.label}-${idx}`}
                                    className="field"
                                >
                                    <div className="label">
                                        {row.label}
                                        <span className="sub">{row.hint}</span>
                                    </div>
                                    <div
                                        className={
                                            idx === 0
                                                ? 'control focus'
                                                : 'control'
                                        }
                                    >
                                        <span>{row.value}</span>
                                        <span className="caret">▾</span>
                                    </div>
                                </div>
                            ))}
                            <div className="settings-foot">
                                <button
                                    type="button"
                                    className="cancel"
                                    tabIndex={-1}
                                >
                                    {control.cancel_label}
                                </button>
                                <button
                                    type="button"
                                    className="save"
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
            className="section"
            data-screen-label="07 Steps"
            style={{ paddingTop: 0 }}
        >
            <div className="wrap">
                <div className="steps-head">
                    <div>
                        <span className="eyebrow">
                            <span className="dot" />
                            <span className="idx">S/07</span>
                            {steps.badge.toUpperCase()}
                        </span>
                        <h2 className="h-section">{steps.title}</h2>
                    </div>
                </div>
                <div className="steps">
                    {steps.items.map((step, idx) => (
                        <div key={step.title} className="step">
                            <span className="pip-track" />
                            <div className="num">
                                {`0${idx + 1}`}
                                <small>{`STEP / ${idx + 1}`}</small>
                            </div>
                            <h3>{step.title}</h3>
                            <p>{step.description}</p>
                        </div>
                    ))}
                </div>
            </div>
        </section>
    );
}

function Insights({
    insights,
    spark,
}: {
    insights: LandingContent['insights'];
    spark: ReturnType<typeof buildSparkPath>;
}) {
    return (
        <section
            className="section"
            data-screen-label="08 Insights"
            style={{ paddingTop: 0 }}
        >
            <div className="wrap">
                <div className="pages-head">
                    <div>
                        <span className="eyebrow">
                            <span className="dot" />
                            <span className="idx">S/08</span>
                            {insights.badge.toUpperCase()}
                        </span>
                        <h2 className="h-section">{insights.chart_title}</h2>
                    </div>
                </div>
                <div className="insights">
                    <div className="chart-card">
                        <div className="chart-head">
                            <h3>{insights.chart_title}</h3>
                            <p>{insights.chart_description}</p>
                            <div className="chart-num">
                                <span className="big">
                                    {insights.metric_value}
                                </span>
                                <span className="delta">
                                    {insights.metric_trend.toUpperCase()}
                                </span>
                            </div>
                            <div className="chart-cap">
                                {insights.metric_label.toUpperCase()} / SOURCE:
                                PITCHBAR ANALYTICS
                            </div>
                        </div>
                        <svg
                            className="chart-svg"
                            viewBox={`0 0 ${spark.w} ${spark.h}`}
                            preserveAspectRatio="none"
                        >
                            <defs>
                                <pattern
                                    id="aurora-grid"
                                    width="50"
                                    height="40"
                                    patternUnits="userSpaceOnUse"
                                >
                                    <path
                                        d="M 50 0 L 0 0 0 40"
                                        fill="none"
                                        stroke="var(--hairline)"
                                        strokeWidth="1"
                                    />
                                </pattern>
                            </defs>
                            <rect
                                width={spark.w}
                                height={spark.h}
                                fill="url(#aurora-grid)"
                            />
                            <path
                                d={spark.area}
                                fill="var(--signal)"
                                opacity="0.45"
                            />
                            <path
                                d={spark.line}
                                fill="none"
                                stroke="var(--ink)"
                                strokeWidth="2.5"
                            />
                            {spark.dots.map((c, i) => (
                                <circle
                                    key={`dot-${i}`}
                                    cx={c.x}
                                    cy={c.y}
                                    r="4"
                                    fill="var(--paper)"
                                    stroke="var(--ink)"
                                    strokeWidth="2"
                                />
                            ))}
                        </svg>
                        <div className="chart-x">
                            {insights.chart_labels.map((label) => (
                                <span key={label}>{label.toUpperCase()}</span>
                            ))}
                        </div>
                    </div>
                    <div className="insight-list">
                        {insights.cards.map((card) => (
                            <div key={card.title} className="insight-row">
                                <div className="insight-icon">
                                    {card.title
                                        .split(' ')[0]
                                        .slice(0, 2)
                                        .toUpperCase()}
                                </div>
                                <div>
                                    <h4>{card.title}</h4>
                                    <p>{card.description}</p>
                                </div>
                                <div className="row-arrow">→</div>
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
    index,
    total,
    onPrev,
    onNext,
}: {
    testimonials: LandingContent['testimonials'];
    current: LandingContent['testimonials']['items'][number] | null;
    index: number;
    total: number;
    onPrev: () => void;
    onNext: () => void;
}) {
    if (!current) {
        return null;
    }

    return (
        <section
            className="section"
            data-screen-label="09 Testimonial"
            style={{ paddingTop: 0 }}
        >
            <div className="wrap">
                <div className="testimonial">
                    <div className="t-side">
                        <span className="eyebrow">
                            <span className="dot" />
                            <span className="idx">S/09</span>
                            {testimonials.badge.toUpperCase()}
                        </span>
                        <h2 className="h-section" style={{ marginTop: 24 }}>
                            {testimonials.title}
                        </h2>
                        <p>{testimonials.kicker}</p>
                    </div>
                    <div>
                        <div className="quote-card">
                            <div className="open">&ldquo;</div>
                            <div className="body">{current.quote}</div>
                            <div className="by">
                                <div className="avatar">
                                    {initials(current.name)}
                                </div>
                                <div>
                                    <strong>{current.name}</strong>
                                    <span style={{ display: 'block' }}>
                                        {current.role.toUpperCase()} ·{' '}
                                        {current.company.toUpperCase()}
                                    </span>
                                </div>
                            </div>
                        </div>
                        <div className="quote-pager">
                            <span>
                                {String(index + 1).padStart(2, '0')} /{' '}
                                {String(total).padStart(2, '0')}
                            </span>
                            <button
                                type="button"
                                onClick={onPrev}
                                aria-label="Previous testimonial"
                            >
                                ←
                            </button>
                            <button
                                type="button"
                                onClick={onNext}
                                aria-label="Next testimonial"
                            >
                                →
                            </button>
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
            className="section"
            data-screen-label="10 FAQ"
            style={{ paddingTop: 0 }}
        >
            <div className="wrap">
                <div className="faq-head">
                    <div>
                        <span className="eyebrow">
                            <span className="dot" />
                            <span className="idx">S/10</span>
                            {faq.badge.toUpperCase()}
                        </span>
                        <h2 className="h-section">{faq.title}</h2>
                    </div>
                </div>
                <div className="faq">
                    {faq.items.map((item, i) => (
                        <div
                            key={item.question}
                            className={`faq-row ${open === i ? 'open' : ''}`}
                        >
                            <button
                                type="button"
                                className="faq-q"
                                onClick={() => onToggle(i)}
                            >
                                <span className="qnum">
                                    Q.{String(i + 1).padStart(2, '0')}
                                </span>
                                <span>{item.question}</span>
                                <span className="toggle">
                                    <span className="h" />
                                    <span className="v" />
                                </span>
                            </button>
                            <div className="faq-a">
                                <div className="faq-a-inner">{item.answer}</div>
                            </div>
                        </div>
                    ))}
                </div>
            </div>
        </section>
    );
}

function CTA({
    finalCta,
    primaryHref,
    secondaryHref,
}: {
    finalCta: LandingContent['final_cta'];
    primaryHref: string;
    secondaryHref: string;
}) {
    return (
        <section className="cta" data-screen-label="11 CTA">
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
                                S/11
                            </span>
                            READY?
                        </span>
                        <h2 style={{ marginTop: 24 }}>{finalCta.title}</h2>
                        <p>{finalCta.description}</p>
                    </div>
                    <div className="cta-actions">
                        <Link href={primaryHref} className="btn-big primary">
                            <span>{finalCta.primary_button_label}</span>
                            <span>→</span>
                        </Link>
                        <Link href={secondaryHref} className="btn-big">
                            <span>{finalCta.secondary_button_label}</span>
                            <span>→</span>
                        </Link>
                    </div>
                </div>
            </div>
        </section>
    );
}
