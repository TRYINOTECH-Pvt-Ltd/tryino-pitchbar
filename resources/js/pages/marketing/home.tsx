import { Form, Head, Link, usePage } from '@inertiajs/react';
import {
    BarChart3,
    BookOpen,
    Box,
    Check,
    ChevronDown,
    CircleCheck,
    ClipboardList,
    Clock3,
    Globe2,
    LockKeyhole,
    Minus,
    Play,
    Plus,
    Quote,
    ShieldCheck,
    Sparkles,
    Tags,
    Target,
    TrendingUp,
    Zap,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';
import type { ReactNode } from 'react';
import AppLogoIcon from '@/components/app-logo-icon';
import { DirArrow } from '@/components/dir-icon';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Dialog, DialogContent } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Separator } from '@/components/ui/separator';
import { useBranding } from '@/hooks/use-branding';
import { MarketingShell } from '@/layouts/marketing-shell';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { dashboard, login, register } from '@/routes';
import { start } from '@/routes/marketing';

type ContentLink = {
    label: string;
    href: string;
};

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
    stats: Array<{
        icon: string;
        value: string;
        label: string;
    }>;
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
        items: Array<{
            question: string;
            answer: string;
        }>;
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
        groups: Array<{
            title: string;
            links: ContentLink[];
        }>;
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
    auth: {
        user?: {
            id: number | string;
        } | null;
    };
};

const iconMap: Record<string, LucideIcon> = {
    BarChart3,
    BookOpen,
    Box,
    CircleCheck,
    ClipboardList,
    Clock3,
    LockKeyhole,
    ShieldCheck,
    Sparkles,
    Tags,
    Target,
    TrendingUp,
    Zap,
};

const pineButtonClass =
    'bg-[#173f2c] text-[#f7f4ec] hover:bg-[#123322] hover:text-[#f7f4ec]';

function resolveIcon(
    name: string,
    fallback: LucideIcon = Sparkles,
): LucideIcon {
    return iconMap[name] ?? fallback;
}

/**
 * Convert a Vimeo / YouTube URL into the matching embeddable iframe
 * URL plus a public thumbnail URL we can render as the card preview.
 * Falls back gracefully for anything we don't recognise.
 */
function toEmbedUrl(href: string): {
    url: string;
    isEmbed: boolean;
    thumbnail: string | null;
} {
    if (!href) {
        return { url: href, isEmbed: false, thumbnail: null };
    }

    // Vimeo: vimeo.com/{id} → player.vimeo.com/video/{id}
    // vumbnail.com is a public Vimeo thumbnail proxy that doesn't
    // require an API key; serves the largest available poster.
    const vimeo = href.match(/vimeo\.com\/(?:video\/)?(\d+)/);

    if (vimeo) {
        return {
            url: `https://player.vimeo.com/video/${vimeo[1]}?autoplay=1&muted=1&playsinline=1`,
            isEmbed: true,
            thumbnail: `https://vumbnail.com/${vimeo[1]}.jpg`,
        };
    }

    // YouTube: watch?v={id} or youtu.be/{id} → youtube.com/embed/{id}
    const youtube = href.match(
        /(?:youtu\.be\/|youtube\.com\/(?:watch\?v=|embed\/))([\w-]{11})/,
    );

    if (youtube) {
        return {
            url: `https://www.youtube.com/embed/${youtube[1]}?autoplay=1&mute=1&playsinline=1&rel=0`,
            isEmbed: true,
            thumbnail: `https://i.ytimg.com/vi/${youtube[1]}/maxresdefault.jpg`,
        };
    }

    return { url: href, isEmbed: false, thumbnail: null };
}

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

function buildChartPath(points: number[]): string {
    const resolvedPoints = points.length > 0 ? points : [0];
    const maxValue = Math.max(...resolvedPoints);
    const minValue = Math.min(...resolvedPoints);
    const chartTop = 42;
    const chartBottom = 205;
    const xStep =
        resolvedPoints.length > 1 ? 640 / (resolvedPoints.length - 1) : 0;

    return resolvedPoints
        .map((point, index) => {
            const ratio =
                maxValue === minValue
                    ? 0.5
                    : (point - minValue) / (maxValue - minValue);
            const x = index * xStep;
            const y = chartBottom - ratio * (chartBottom - chartTop);

            return `${index === 0 ? 'M' : 'L'}${x.toFixed(2)} ${y.toFixed(2)}`;
        })
        .join(' ');
}

function SectionLabel({ children }: { children: ReactNode }) {
    return (
        <Badge
            variant="secondary"
            className="rounded-md border border-[#e2ddcf] bg-[#eff1e8] px-3 py-1 text-[10px] font-bold text-[#7d826f] uppercase"
        >
            {children}
        </Badge>
    );
}

function LogoMark({ className }: { className?: string }) {
    return (
        <span
            className={cn(
                'flex items-center justify-center text-[#173f2c]',
                className,
            )}
        >
            <AppLogoIcon className="size-full" />
        </span>
    );
}

function FeatureIcon({
    icon: Icon,
    dark = false,
}: {
    icon: LucideIcon;
    dark?: boolean;
}) {
    return (
        <span
            className={cn(
                'flex size-12 items-center justify-center rounded-full border',
                dark
                    ? 'border-[#3d6147] bg-[#254b35] text-[#dcebd4]'
                    : 'border-[#e3dccb] bg-[#f4f1e8] text-[#214a36]',
            )}
        >
            <Icon className="size-5" />
        </span>
    );
}

function ChatPreview({
    content,
    brandName,
}: {
    content: LandingContent['chat_preview'];
    brandName: string;
}) {
    return (
        <Card className="mx-auto w-full max-w-[540px] overflow-hidden rounded-[28px] border-[#e3dccf] bg-[#fbf9f3] shadow-[0_18px_70px_rgba(28,37,29,0.10)]">
            <CardHeader className="flex-row items-center justify-between border-b border-[#ebe4d8] px-7 py-6">
                <div className="flex items-center gap-3">
                    <span className="size-2.5 rounded-full bg-[#52ad6a]" />
                    <CardTitle className="text-[15px] font-bold text-[#2a3028]">
                        {content.title}
                    </CardTitle>
                </div>
                <Badge className="rounded-full bg-[#e9f2e3] px-3 py-1 text-xs font-bold text-[#62805a]">
                    {content.badge}
                </Badge>
            </CardHeader>
            <CardContent className="flex flex-col gap-5 px-7 py-7">
                <div className="ms-auto flex max-w-[360px] items-start gap-3">
                    <div className="rounded-2xl rounded-se-sm border border-[#e7dfd2] bg-[#f7f2ea] px-5 py-4 text-sm leading-6 font-medium text-[#4f514c] shadow-sm">
                        {content.question}
                    </div>
                    <div className="size-10 shrink-0 rounded-full border-2 border-[#f5eee3] bg-[linear-gradient(135deg,#744026,#e2b98d)]" />
                </div>

                <div className="flex items-start gap-4">
                    <LogoMark className="mt-3 size-11 rounded-full border border-[#d8d2c4] bg-[#fbf8ef] p-2.5 shadow-sm" />
                    <div className="flex max-w-[350px] flex-col gap-4">
                        <div className="rounded-2xl rounded-ss-sm border border-[#e7dfd2] bg-white px-5 py-4 text-sm leading-6 font-medium text-[#40443d] shadow-sm">
                            {content.answer}
                        </div>
                        <div className="rounded-[18px] border border-[#e2dbcd] bg-[#fbf8ef] p-5 shadow-[0_18px_42px_rgba(48,43,34,0.08)]">
                            <Badge className="rounded-md bg-[#e9ebe1] px-2 py-1 text-[10px] font-bold text-[#77806f]">
                                {content.plan_badge}
                            </Badge>
                            <div className="mt-4">
                                <p className="text-lg font-bold text-[#3d403b]">
                                    {content.plan_name}
                                </p>
                                <div className="mt-1 flex items-end gap-1 text-[#2e332d]">
                                    <span className="text-4xl font-semibold tracking-normal">
                                        {content.plan_price}
                                    </span>
                                    <span className="pb-1 text-sm text-[#777a70]">
                                        {content.plan_interval}
                                    </span>
                                </div>
                                <p className="mt-2 text-xs text-[#777a70]">
                                    {content.plan_note}
                                </p>
                            </div>
                            <div className="mt-4 flex flex-col gap-2 text-xs font-medium text-[#536154]">
                                {content.plan_features.map((item) => (
                                    <div
                                        key={item}
                                        className="flex items-center gap-2"
                                    >
                                        <Check className="size-3.5 text-[#315f42]" />
                                        <span>{item}</span>
                                    </div>
                                ))}
                            </div>
                            <Button
                                className={cn(
                                    'mt-5 h-10 w-full rounded-md text-sm font-bold',
                                    pineButtonClass,
                                )}
                            >
                                {content.plan_button_label}
                            </Button>
                        </div>
                    </div>
                </div>

                <div className="ms-20 flex w-fit items-center gap-2 rounded-lg border border-[#e6dfd2] bg-white px-4 py-2 text-xs font-medium text-[#818477] shadow-xs">
                    <span className="flex gap-1">
                        <span className="size-1.5 rounded-full bg-[#95c780]" />
                        <span className="size-1.5 rounded-full bg-[#95c780]" />
                        <span className="size-1.5 rounded-full bg-[#95c780]" />
                    </span>
                    {content.typing_label}
                </div>
            </CardContent>
            <div className="border-t border-[#ebe4d8] py-4 text-center text-xs font-medium text-[#aaa394]">
                {content.powered_by_prefix}{' '}
                <span className="font-bold text-[#7e8176]">{brandName}</span>
                <LogoMark className="ms-1 inline-flex size-3 align-[-2px]" />
            </div>
        </Card>
    );
}

function GrowthChart({ content }: { content: LandingContent['insights'] }) {
    const { t } = useT();
    const linePath = buildChartPath(content.chart_points);
    const areaPath = `${linePath} L640 250 L0 250 Z`;
    const labels =
        content.chart_labels.length > 0 ? content.chart_labels : [t('Now')];

    return (
        <Card className="rounded-xl border-[#ded8ca] bg-[#eef2e8] shadow-none">
            <CardContent className="p-8">
                <h3 className="marketing-display max-w-md text-4xl leading-none text-[#252a24]">
                    {content.chart_title}
                </h3>
                <p className="mt-5 max-w-md text-sm leading-6 font-medium text-[#6c7068]">
                    {content.chart_description}
                </p>
                <div className="mt-7">
                    <p className="text-xs font-medium text-[#6c7068]">
                        {content.metric_label}
                    </p>
                    <div className="mt-1 flex items-end gap-3">
                        <span className="text-4xl font-semibold text-[#212720]">
                            {content.metric_value}
                        </span>
                        <span className="pb-1 text-xs font-bold text-[#76816e]">
                            {content.metric_trend}
                        </span>
                    </div>
                </div>
                <div className="mt-8 overflow-hidden rounded-lg">
                    <svg
                        viewBox="0 0 640 250"
                        role="img"
                        aria-label={content.chart_title}
                        className="h-auto w-full"
                    >
                        <defs>
                            <linearGradient
                                id="growthFill"
                                x1="0"
                                x2="0"
                                y1="0"
                                y2="1"
                            >
                                <stop
                                    offset="0%"
                                    stopColor="#5d7f5d"
                                    stopOpacity="0.2"
                                />
                                <stop
                                    offset="100%"
                                    stopColor="#5d7f5d"
                                    stopOpacity="0"
                                />
                            </linearGradient>
                        </defs>
                        {[40, 90, 140, 190].map((y) => (
                            <line
                                key={y}
                                x1="0"
                                x2="640"
                                y1={y}
                                y2={y}
                                stroke="#d6ddce"
                                strokeWidth="1"
                            />
                        ))}
                        <path d={areaPath} fill="url(#growthFill)" />
                        <path
                            d={linePath}
                            fill="none"
                            stroke="#4d704e"
                            strokeLinecap="round"
                            strokeLinejoin="round"
                            strokeWidth="5"
                        />
                        {labels.map((label, index) => (
                            <text
                                key={label}
                                x={
                                    labels.length > 1
                                        ? 64 +
                                          (index * 520) / (labels.length - 1)
                                        : 320
                                }
                                y="238"
                                fill="#7a8174"
                                fontSize="14"
                                textAnchor="middle"
                            >
                                {label}
                            </text>
                        ))}
                    </svg>
                </div>
            </CardContent>
        </Card>
    );
}

/**
 * Initials fallback when a testimonial has no avatar image. Two
 * letters max, uppercase. Stable across renders for a given name so
 * the swatch colour stays the same.
 */
function initialsFor(name: string): string {
    const parts = name.trim().split(/\s+/).filter(Boolean);

    if (parts.length === 0) {
        return '–';
    }

    if (parts.length === 1) {
        return parts[0].slice(0, 2).toUpperCase();
    }

    return (parts[0][0] + parts[parts.length - 1][0]).toUpperCase();
}

/**
 * Auto-advancing testimonial carousel. Pure-Preact, no Embla / Splide
 * dependency — keeps the marketing bundle small. Auto-advances every
 * 7 s, pauses on hover/focus, supports arrow keys and the visible
 * prev/next buttons. Dot pagination at the bottom.
 *
 * Renders one card at a time on every viewport — the testimonial
 * lives or dies on the quote being readable, so we don't squeeze two
 * onto a phone screen.
 */
function TestimonialCarousel({
    content,
}: {
    content: LandingContent['testimonials'];
}) {
    const { t } = useT();
    const items = content.items;
    const [active, setActive] = useState(0);
    const [paused, setPaused] = useState(false);
    const containerRef = useRef<HTMLDivElement | null>(null);

    const count = items.length;

    useEffect(() => {
        if (count <= 1 || paused) {
            return;
        }

        const id = window.setInterval(() => {
            setActive((i) => (i + 1) % count);
        }, 7000);

        return () => window.clearInterval(id);
    }, [count, paused]);

    if (count === 0) {
        return null;
    }

    const prev = () => setActive((i) => (i - 1 + count) % count);
    const next = () => setActive((i) => (i + 1) % count);

    const handleKey = (e: React.KeyboardEvent<HTMLDivElement>) => {
        if (e.key === 'ArrowLeft') {
            e.preventDefault();
            prev();
        } else if (e.key === 'ArrowRight') {
            e.preventDefault();
            next();
        }
    };

    const current = items[active];

    return (
        <section className="bg-[#f4f0e8]">
            <div className="mx-auto max-w-[1200px] px-6 py-20 lg:px-10">
                <div className="grid gap-10 lg:grid-cols-[0.78fr_1.45fr] lg:items-start">
                    <div>
                        <SectionLabel>{content.badge}</SectionLabel>
                        <h2 className="marketing-display mt-5 max-w-[520px] text-4xl leading-none text-[#173f2c] sm:text-5xl">
                            {content.title}
                        </h2>
                        <p className="mt-5 max-w-[420px] text-base leading-7 font-medium text-[#3d6a4d]">
                            {content.kicker}
                        </p>
                    </div>

                    <div
                        ref={containerRef}
                        role="region"
                        aria-label={t('Customer testimonials')}
                        aria-roledescription={t('carousel')}
                        tabIndex={0}
                        onMouseEnter={() => setPaused(true)}
                        onMouseLeave={() => setPaused(false)}
                        onFocus={() => setPaused(true)}
                        onBlur={() => setPaused(false)}
                        onKeyDown={handleKey}
                        className="rounded-3xl border border-[#173f2c]/10 bg-white p-7 shadow-[0_24px_48px_-32px_rgba(23,63,44,0.45)] focus:outline-none focus-visible:ring-2 focus-visible:ring-[#173f2c]/40 sm:p-9"
                    >
                        <Quote
                            className="size-7 text-[#173f2c]/30"
                            aria-hidden="true"
                        />
                        <blockquote
                            className="mt-4 text-lg leading-8 text-[#173f2c] sm:text-xl sm:leading-9"
                            key={`quote-${active}`}
                        >
                            {current.quote}
                        </blockquote>
                        <div className="mt-7 flex items-center gap-4">
                            <span
                                aria-hidden="true"
                                className="flex size-11 shrink-0 items-center justify-center rounded-full bg-[#173f2c] text-sm font-bold text-[#f4f0e8]"
                            >
                                {initialsFor(current.name)}
                            </span>
                            <div className="min-w-0">
                                <p className="text-sm font-bold text-[#173f2c]">
                                    {current.name}
                                </p>
                                <p className="truncate text-xs font-medium text-[#3d6a4d]">
                                    {current.role}
                                    {current.company
                                        ? ` · ${current.company}`
                                        : ''}
                                </p>
                            </div>
                        </div>

                        {count > 1 && (
                            <div className="mt-7 flex items-center justify-between gap-4 border-t border-[#173f2c]/10 pt-5">
                                <div
                                    role="tablist"
                                    aria-label={t('Testimonial pagination')}
                                    className="flex items-center gap-1.5"
                                >
                                    {items.map((_, i) => (
                                        <button
                                            key={i}
                                            type="button"
                                            role="tab"
                                            aria-selected={i === active}
                                            aria-label={t(
                                                'Show testimonial :index of :count',
                                                { index: i + 1, count },
                                            )}
                                            onClick={() => setActive(i)}
                                            className={cn(
                                                'h-1.5 rounded-full transition-all',
                                                i === active
                                                    ? 'w-8 bg-[#173f2c]'
                                                    : 'w-3 bg-[#173f2c]/20 hover:bg-[#173f2c]/40',
                                            )}
                                        />
                                    ))}
                                </div>
                                <div className="flex items-center gap-2">
                                    <button
                                        type="button"
                                        onClick={prev}
                                        aria-label={t('Previous testimonial')}
                                        className="flex size-9 items-center justify-center rounded-full border border-[#173f2c]/15 text-[#173f2c] transition hover:bg-[#173f2c] hover:text-[#f4f0e8]"
                                    >
                                        <DirArrow
                                            direction="back"
                                            style="chevron"
                                            className="size-4"
                                        />
                                    </button>
                                    <button
                                        type="button"
                                        onClick={next}
                                        aria-label={t('Next testimonial')}
                                        className="flex size-9 items-center justify-center rounded-full border border-[#173f2c]/15 text-[#173f2c] transition hover:bg-[#173f2c] hover:text-[#f4f0e8]"
                                    >
                                        <DirArrow
                                            direction="forward"
                                            style="chevron"
                                            className="size-4"
                                        />
                                    </button>
                                </div>
                            </div>
                        )}
                    </div>
                </div>
            </div>
        </section>
    );
}

/**
 * FAQ accordion. Multi-open allowed (one item being open doesn't
 * collapse the others) — visitors are allowed to compare two answers
 * side by side. Dependency-free: just a Set<number> in state and
 * controlled <details>-style toggle.
 */
function FaqAccordion({ content }: { content: LandingContent['faq'] }) {
    const items = content.items;
    const [open, setOpen] = useState<Set<number>>(() => new Set([0]));

    if (items.length === 0) {
        return null;
    }

    const toggle = (i: number) => {
        setOpen((prev) => {
            const next = new Set(prev);

            if (next.has(i)) {
                next.delete(i);
            } else {
                next.add(i);
            }

            return next;
        });
    };

    return (
        <section className="mx-auto max-w-[1200px] px-6 py-20 lg:px-10">
            <div className="grid gap-12 lg:grid-cols-[0.78fr_1.45fr] lg:items-start">
                <div>
                    <SectionLabel>{content.badge}</SectionLabel>
                    <h2 className="marketing-display mt-5 max-w-[480px] text-4xl leading-none text-[#173f2c] sm:text-5xl">
                        {content.title}
                    </h2>
                    <p className="mt-5 max-w-[420px] text-base leading-7 font-medium text-[#3d6a4d]">
                        {content.kicker}
                    </p>
                </div>

                <ul className="grid gap-3">
                    {items.map((item, i) => {
                        const isOpen = open.has(i);

                        return (
                            <li
                                key={i}
                                className={cn(
                                    'rounded-2xl border bg-white transition-colors',
                                    isOpen
                                        ? 'border-[#173f2c]/40 shadow-[0_12px_36px_-28px_rgba(23,63,44,0.5)]'
                                        : 'border-[#173f2c]/12 hover:border-[#173f2c]/25',
                                )}
                            >
                                <button
                                    type="button"
                                    onClick={() => toggle(i)}
                                    aria-expanded={isOpen}
                                    aria-controls={`faq-panel-${i}`}
                                    id={`faq-trigger-${i}`}
                                    className="flex w-full items-start gap-4 px-5 py-4 text-start transition-colors hover:bg-[#173f2c]/[0.03] sm:px-6 sm:py-5"
                                >
                                    <span className="flex-1 text-base font-semibold text-[#173f2c] sm:text-lg">
                                        {item.question}
                                    </span>
                                    <span
                                        aria-hidden="true"
                                        className={cn(
                                            'mt-0.5 flex size-7 shrink-0 items-center justify-center rounded-full border transition-colors',
                                            isOpen
                                                ? 'border-[#173f2c] bg-[#173f2c] text-[#f4f0e8]'
                                                : 'border-[#173f2c]/25 text-[#173f2c]',
                                        )}
                                    >
                                        {isOpen ? (
                                            <Minus className="size-3.5" />
                                        ) : (
                                            <Plus className="size-3.5" />
                                        )}
                                    </span>
                                </button>
                                {isOpen && (
                                    <div
                                        role="region"
                                        id={`faq-panel-${i}`}
                                        aria-labelledby={`faq-trigger-${i}`}
                                        className="px-5 pb-5 text-sm leading-7 text-[#3d6a4d] sm:px-6 sm:pb-6 sm:text-base"
                                    >
                                        {item.answer}
                                    </div>
                                )}
                            </li>
                        );
                    })}
                </ul>
            </div>
        </section>
    );
}

export default function MarketingHome({
    canRegister,
    demoAgentId,
    content,
}: Props) {
    const branding = useBranding();
    const [videoOpen, setVideoOpen] = useState(false);
    const videoEmbed = useMemo(
        () => toEmbedUrl(content.video.href),
        [content.video.href],
    );
    const { auth, demo } = usePage<AuthProps & { demo?: boolean }>().props;
    const hasUser = Boolean(auth.user);
    const siteTitle = branding.site_title || content.brand_name;
    const primaryHref = hasUser
        ? dashboard()
        : canRegister
          ? register()
          : login();

    // Demo-mode: every CTA on the body of the landing page that points
    // into the app opens in a new tab so the reviewer keeps the
    // marketing surface open while exploring the live demo. Skip when
    // the user is already signed in — that's an admin browsing past
    // their own marketing copy, not a fresh reviewer.
    const demoOpenInNewTab = Boolean(demo) && !hasUser;
    const ctaTargetProps = demoOpenInNewTab
        ? { target: '_blank' as const, rel: 'noopener noreferrer' }
        : {};

    return (
        <>
            <Head title="">
                <meta name="description" content={content.hero.description} />
            </Head>

            <MarketingShell content={content}>
                <div className="bg-[#f7f2e8]">
                    <section className="mx-auto grid max-w-[1240px] gap-14 px-6 pt-14 pb-16 lg:grid-cols-[0.95fr_1fr] lg:items-start lg:px-10 lg:pt-20 lg:pb-20">
                        <div className="flex flex-col gap-8">
                            <SectionLabel>{content.hero.badge}</SectionLabel>

                            <div className="flex flex-col gap-6">
                                <h1 className="marketing-display max-w-[610px] text-[2.75rem] leading-[1.0] break-words text-[#252621] sm:text-[5.35rem] sm:leading-[0.95] lg:text-[5.6rem] xl:text-[5.95rem]">
                                    <span className="block">
                                        {content.hero.line_one}
                                    </span>
                                    <span className="block sm:whitespace-nowrap">
                                        <span className="text-[#1d5139] italic">
                                            {content.hero.accent}
                                        </span>{' '}
                                        <span>
                                            {content.hero.line_two_suffix}
                                        </span>
                                    </span>
                                    <span className="block">
                                        {content.hero.line_three_prefix}{' '}
                                        <span className="relative isolate inline-block w-fit text-[#173f2c]">
                                            <span
                                                aria-hidden="true"
                                                className="absolute inset-x-[-0.03em] bottom-[0.12em] -z-10 h-[0.2em] rounded-full bg-[#dce7ce]"
                                            />
                                            <span className="italic">
                                                {
                                                    content.hero
                                                        .line_three_highlight
                                                }
                                            </span>
                                        </span>
                                    </span>
                                    <span className="block">
                                        <span className="relative isolate inline-block w-fit pe-[0.04em] text-[#173f2c]">
                                            <span
                                                aria-hidden="true"
                                                className="absolute inset-x-[-0.03em] bottom-[0.12em] -z-10 h-[0.2em] rounded-full bg-[#dce7ce]"
                                            />
                                            <span className="italic">
                                                {
                                                    content.hero
                                                        .line_four_highlight
                                                }
                                            </span>
                                        </span>
                                    </span>
                                </h1>
                                <p className="max-w-[560px] text-lg leading-8 font-medium text-[#666b62]">
                                    {content.hero.description}
                                </p>
                            </div>

                            <div className="flex flex-col gap-7">
                                <div className="flex max-w-[520px] flex-col gap-3">
                                    <p className="text-[11px] font-bold text-[#8a8f82] uppercase">
                                        {content.hero.site_test_label}
                                    </p>
                                    <Form
                                        {...start.form()}
                                        className="flex flex-col gap-2"
                                    >
                                        {({ processing, errors }) => (
                                            <>
                                                <div className="flex rounded-lg border border-[#d9d2c3] bg-white p-1 shadow-[0_10px_30px_rgba(30,35,28,0.05)]">
                                                    <div className="flex min-w-0 grow items-center gap-3 px-3">
                                                        <Globe2 className="size-4 shrink-0 text-[#8a8f82]" />
                                                        <Input
                                                            aria-invalid={Boolean(
                                                                errors.domain,
                                                            )}
                                                            className="h-11 border-0 bg-transparent px-0 text-sm font-medium text-[#394039] shadow-none placeholder:text-[#a1a49a] focus-visible:ring-0"
                                                            name="domain"
                                                            placeholder={
                                                                content.hero
                                                                    .site_test_placeholder
                                                            }
                                                            required
                                                            type="url"
                                                        />
                                                    </div>
                                                    <Button
                                                        className={cn(
                                                            'h-11 rounded-md px-5 text-sm font-bold',
                                                            pineButtonClass,
                                                        )}
                                                        disabled={processing}
                                                        type="submit"
                                                    >
                                                        {
                                                            content.hero
                                                                .site_test_button_label
                                                        }
                                                    </Button>
                                                </div>
                                                {errors.domain && (
                                                    <p className="text-sm text-destructive">
                                                        {errors.domain}
                                                    </p>
                                                )}
                                            </>
                                        )}
                                    </Form>
                                    <p className="flex items-center gap-2 text-xs font-medium text-[#85887f]">
                                        <LockKeyhole className="size-3.5" />
                                        {content.hero.site_test_helper}
                                    </p>
                                </div>

                                {demoAgentId && (
                                    <p className="text-sm font-semibold text-[#315f42]">
                                        {content.hero.live_demo_notice}
                                    </p>
                                )}
                            </div>
                        </div>

                        <ChatPreview
                            content={content.chat_preview}
                            brandName={siteTitle}
                        />
                    </section>

                    <section className="mx-auto max-w-[1200px] px-6 lg:px-10">
                        <Card className="rounded-xl border-[#ded8ca] bg-[#fbf8ef] shadow-none">
                            <CardContent className="grid p-0 sm:grid-cols-2 lg:grid-cols-4">
                                {content.stats.map((stat, index) => {
                                    const Icon = resolveIcon(
                                        stat.icon,
                                        Sparkles,
                                    );

                                    return (
                                        <div
                                            key={`${stat.value}-${index}`}
                                            className="relative flex flex-col gap-4 px-10 py-8"
                                        >
                                            <Icon className="size-5 text-[#8a9284]" />
                                            <div>
                                                <p className="text-4xl font-semibold text-[#2b302a]">
                                                    {stat.value}
                                                </p>
                                                <p className="mt-3 max-w-[170px] text-sm leading-5 font-medium text-[#5e655d]">
                                                    {stat.label}
                                                </p>
                                            </div>
                                            {index <
                                                content.stats.length - 1 && (
                                                <Separator
                                                    className="absolute end-0 top-1/2 hidden h-24 -translate-y-1/2 bg-[#e1d9ca] lg:block"
                                                    orientation="vertical"
                                                />
                                            )}
                                        </div>
                                    );
                                })}
                            </CardContent>
                        </Card>
                    </section>

                    <section className="mx-auto grid max-w-[1200px] gap-12 px-6 py-24 lg:grid-cols-[0.78fr_1.22fr] lg:items-center lg:px-10">
                        <div className="flex flex-col gap-6">
                            <SectionLabel>{content.video.badge}</SectionLabel>
                            <h2 className="marketing-display max-w-[470px] text-4xl leading-none text-[#252621] sm:text-5xl">
                                {content.video.title}
                            </h2>
                            <p className="max-w-[470px] text-base leading-7 font-medium text-[#686d64]">
                                {content.video.description}
                            </p>
                            <div className="flex flex-col gap-3 text-sm font-medium text-[#4c554c]">
                                {content.video.bullets.map((item) => (
                                    <div
                                        key={item}
                                        className="flex items-center gap-3"
                                    >
                                        <Check className="size-4 text-[#315f42]" />
                                        <span>{item}</span>
                                    </div>
                                ))}
                            </div>
                        </div>

                        {/* If the configured URL is a recognised Vimeo /
                            YouTube link we open it inline in a modal. For
                            anything else (a direct page link, a .mp4 etc.)
                            we fall back to navigating normally. */}
                        {videoEmbed.isEmbed ? (
                            <button
                                type="button"
                                onClick={() => setVideoOpen(true)}
                                aria-label={content.video.button_label}
                                className="group block w-full text-start"
                            >
                                <Card className="overflow-hidden rounded-[30px] border-[#ded8ca] bg-[#fbf8ef] shadow-[0_24px_80px_rgba(29,37,28,0.10)] transition duration-200 group-hover:-translate-y-1 group-hover:shadow-[0_30px_90px_rgba(29,37,28,0.14)]">
                                    <CardContent className="p-0">
                                        <div className="relative aspect-video overflow-hidden border-b border-[#e9e2d5] bg-[#0a0a0b]">
                                            {/* Real Vimeo / YouTube thumbnail.
                                            Falls back to a transparent
                                            shim if the image fails — the
                                            cream gradient behind it shows
                                            through and the card still
                                            reads cleanly. */}
                                            {videoEmbed.thumbnail && (
                                                <img
                                                    src={videoEmbed.thumbnail}
                                                    alt={content.video.title}
                                                    loading="lazy"
                                                    className="absolute inset-0 size-full object-cover transition duration-300 group-hover:scale-[1.02]"
                                                />
                                            )}
                                            {/* Soft top-to-bottom darken so the
                                            badges + play button stay
                                            readable over any thumbnail. */}
                                            <div className="absolute inset-0 bg-[linear-gradient(180deg,rgba(0,0,0,0.18)_0%,rgba(0,0,0,0)_28%,rgba(0,0,0,0)_60%,rgba(0,0,0,0.32)_100%)]" />

                                            <div className="absolute start-5 top-5 flex flex-wrap items-center gap-2">
                                                <span className="rounded-full bg-white/95 px-3 py-1 text-[11px] font-bold tracking-[0.18em] text-[#2a3327] uppercase shadow-sm">
                                                    {
                                                        content.video
                                                            .duration_label
                                                    }
                                                </span>
                                                <span className="rounded-full bg-[#173f2c] px-3 py-1 text-[11px] font-bold tracking-[0.18em] text-[#eef5e9] uppercase shadow-sm">
                                                    {content.video.tag_label}
                                                </span>
                                            </div>

                                            <span className="absolute inset-0 flex items-center justify-center">
                                                <span className="flex size-20 items-center justify-center rounded-full border border-white/70 bg-white/95 shadow-[0_18px_40px_rgba(0,0,0,0.32)] transition duration-200 group-hover:scale-105">
                                                    <Play className="ms-1 size-8 text-[#173f2c]" />
                                                </span>
                                            </span>
                                        </div>

                                        <div className="grid gap-4 px-6 py-5 md:grid-cols-[1fr_auto] md:items-center">
                                            <div>
                                                <p className="text-sm font-bold text-[#253129]">
                                                    {content.video.footer_title}
                                                </p>
                                                <p className="mt-1 text-sm leading-6 font-medium text-[#6a7169]">
                                                    {
                                                        content.video
                                                            .footer_description
                                                    }
                                                </p>
                                            </div>
                                            <div className="inline-flex items-center gap-2 text-sm font-bold text-[#173f2c]">
                                                {content.video.button_label}
                                                <DirArrow
                                                    direction="forward"
                                                    className="size-4"
                                                />
                                            </div>
                                        </div>
                                    </CardContent>
                                </Card>
                            </button>
                        ) : (
                            <Link
                                href={content.video.href}
                                className="group block"
                            >
                                <Card className="overflow-hidden rounded-[30px] border-[#ded8ca] bg-[#fbf8ef] shadow-[0_24px_80px_rgba(29,37,28,0.10)] transition duration-200 group-hover:-translate-y-1 group-hover:shadow-[0_30px_90px_rgba(29,37,28,0.14)]">
                                    <CardContent className="px-6 py-6">
                                        <p className="text-sm font-bold text-[#253129]">
                                            {content.video.footer_title}
                                        </p>
                                        <p className="mt-1 text-sm leading-6 font-medium text-[#6a7169]">
                                            {content.video.footer_description}
                                        </p>
                                        <div className="mt-4 inline-flex items-center gap-2 text-sm font-bold text-[#173f2c]">
                                            {content.video.button_label}
                                            <DirArrow
                                                direction="forward"
                                                className="size-4"
                                            />
                                        </div>
                                    </CardContent>
                                </Card>
                            </Link>
                        )}

                        <Dialog open={videoOpen} onOpenChange={setVideoOpen}>
                            <DialogContent className="overflow-hidden border-none bg-black p-0 sm:max-w-4xl">
                                {videoEmbed.isEmbed && videoOpen && (
                                    <div
                                        className="relative w-full"
                                        style={{ aspectRatio: '16 / 9' }}
                                    >
                                        <iframe
                                            src={videoEmbed.url}
                                            title={content.video.card_title}
                                            allow="autoplay; fullscreen; picture-in-picture; encrypted-media"
                                            allowFullScreen
                                            className="absolute inset-0 size-full"
                                        />
                                    </div>
                                )}
                            </DialogContent>
                        </Dialog>
                    </section>

                    <section className="mx-auto grid max-w-[1200px] gap-12 px-6 py-24 lg:grid-cols-[0.8fr_1.4fr] lg:items-center lg:px-10">
                        <div className="flex flex-col gap-6">
                            <SectionLabel>
                                {content.where_it_fits.badge}
                            </SectionLabel>
                            <h2 className="marketing-display max-w-[440px] text-4xl leading-none text-[#252621] sm:text-5xl">
                                {content.where_it_fits.title}
                            </h2>
                        </div>
                        <div className="grid gap-5 md:grid-cols-3">
                            {content.where_it_fits.cards.map((item) => {
                                const Icon = resolveIcon(item.icon, Sparkles);

                                return (
                                    <Card
                                        key={item.title}
                                        className="rounded-lg border-[#ded8ca] bg-[#fbf8ef] shadow-none"
                                    >
                                        <CardContent className="flex min-h-[220px] flex-col gap-6 p-7">
                                            <FeatureIcon icon={Icon} />
                                            <div className="flex flex-col gap-3">
                                                <h3 className="text-base font-bold text-[#2d332d]">
                                                    {item.title}
                                                </h3>
                                                <p className="text-sm leading-6 font-medium text-[#676d64]">
                                                    {item.description}
                                                </p>
                                            </div>
                                        </CardContent>
                                    </Card>
                                );
                            })}
                        </div>
                    </section>

                    <section className="bg-[#071b17] text-white">
                        <div
                            className="mx-auto grid max-w-[1240px] gap-12 px-6 py-20 lg:grid-cols-[0.7fr_1.4fr] lg:px-10"
                            style={{
                                backgroundImage:
                                    'repeating-radial-gradient(circle at 8% 56%, rgba(184, 223, 168, 0.12) 0 1px, transparent 1px 16px)',
                            }}
                        >
                            <div className="flex flex-col gap-6 pt-10">
                                <SectionLabel>
                                    {content.feature_grid.badge}
                                </SectionLabel>
                                <h2 className="marketing-display max-w-[360px] text-5xl leading-none text-[#f4f0e8]">
                                    {content.feature_grid.title}
                                </h2>
                            </div>
                            <div className="grid gap-5 md:grid-cols-2 xl:grid-cols-3">
                                {content.feature_grid.cards.map((item) => {
                                    const Icon = resolveIcon(
                                        item.icon,
                                        Sparkles,
                                    );

                                    return (
                                        <Card
                                            key={item.title}
                                            className="rounded-lg border-white/12 bg-white/[0.04] text-white shadow-none backdrop-blur-sm"
                                        >
                                            <CardContent className="flex min-h-[190px] flex-col gap-6 p-7">
                                                <FeatureIcon dark icon={Icon} />
                                                <div className="flex flex-col gap-3">
                                                    <h3 className="text-base font-bold text-[#f5f1e8]">
                                                        {item.title}
                                                    </h3>
                                                    <p className="text-sm leading-6 font-medium text-[#b5c2b7]">
                                                        {item.description}
                                                    </p>
                                                </div>
                                            </CardContent>
                                        </Card>
                                    );
                                })}
                            </div>
                        </div>
                    </section>

                    <section className="mx-auto grid max-w-[1200px] gap-14 px-6 py-20 lg:grid-cols-[0.78fr_1.45fr] lg:px-10">
                        <div className="flex flex-col gap-8">
                            <div className="flex flex-col gap-6">
                                <SectionLabel>
                                    {content.control.badge}
                                </SectionLabel>
                                <h2 className="marketing-display max-w-[430px] text-4xl leading-none text-[#252621] sm:text-5xl">
                                    {content.control.title}
                                </h2>
                                <p className="max-w-[420px] text-base leading-7 font-medium text-[#686d64]">
                                    {content.control.description}
                                </p>
                            </div>

                            <div className="flex flex-col gap-7">
                                {content.control.callouts.map((item) => {
                                    const Icon = resolveIcon(
                                        item.icon,
                                        Sparkles,
                                    );

                                    return (
                                        <div
                                            key={item.title}
                                            className="flex gap-4"
                                        >
                                            <FeatureIcon icon={Icon} />
                                            <div className="max-w-[330px]">
                                                <h3 className="text-sm font-bold text-[#2d332d]">
                                                    {item.title}
                                                </h3>
                                                <p className="mt-1 text-sm leading-6 font-medium text-[#686d64]">
                                                    {item.description}
                                                </p>
                                            </div>
                                        </div>
                                    );
                                })}
                            </div>
                        </div>

                        <Card className="rounded-xl border-[#ded8ca] bg-[#fbf8ef] shadow-none">
                            <CardContent className="p-7 lg:p-10">
                                <h3 className="text-lg font-bold text-[#3d413b]">
                                    {content.control.settings_card_title}
                                </h3>
                                <div className="mt-8 flex flex-col gap-0 overflow-hidden rounded-lg border border-[#e2dbcd]">
                                    {content.control.settings_rows.map(
                                        (item) => (
                                            <div
                                                key={item.label}
                                                className="grid gap-4 border-b border-[#e2dbcd] bg-[#fbf8ef] p-5 last:border-b-0 md:grid-cols-[0.75fr_1.3fr] md:items-center"
                                            >
                                                <div>
                                                    <p className="text-sm font-bold text-[#4a5048]">
                                                        {item.label}
                                                    </p>
                                                    <p className="mt-1 text-xs font-medium text-[#85897e]">
                                                        {item.hint}
                                                    </p>
                                                </div>
                                                <div className="flex min-h-11 items-center justify-between gap-4 rounded-md border border-[#ddd6c8] bg-[#f8f4ed] px-4 text-sm font-medium text-[#6a6f66]">
                                                    <span>{item.value}</span>
                                                    <ChevronDown className="size-4 text-[#9a9d92]" />
                                                </div>
                                            </div>
                                        ),
                                    )}
                                </div>
                                <div className="mt-7 flex items-center justify-end gap-5">
                                    <button
                                        className="text-sm font-semibold text-[#8a8f82]"
                                        type="button"
                                    >
                                        {content.control.cancel_label}
                                    </button>
                                    <Button
                                        className={cn(
                                            'h-12 rounded-md px-6 text-sm font-bold',
                                            pineButtonClass,
                                        )}
                                    >
                                        {content.control.save_button_label}
                                    </Button>
                                </div>
                            </CardContent>
                        </Card>
                    </section>

                    <section className="mx-auto max-w-[1200px] px-6 pb-20 lg:px-10">
                        <div className="flex flex-col gap-6">
                            <SectionLabel>{content.steps.badge}</SectionLabel>
                            <h2 className="marketing-display text-4xl leading-none text-[#252621] sm:text-5xl">
                                {content.steps.title}
                            </h2>
                        </div>
                        <div className="mt-10 grid gap-16 lg:grid-cols-3 lg:gap-16">
                            {content.steps.items.map((item, index) => {
                                const Icon = resolveIcon(item.icon, Sparkles);

                                return (
                                    <Card
                                        key={item.title}
                                        className="relative rounded-lg border-[#ded8ca] bg-[#fbf8ef] shadow-none"
                                    >
                                        {index <
                                            content.steps.items.length - 1 && (
                                            <div className="absolute start-[calc(100%+10px)] top-1/2 hidden h-px w-12 border-t border-dashed border-[#bbc6ad] lg:block" />
                                        )}
                                        <CardContent className="flex min-h-[150px] items-center gap-5 p-7">
                                            <FeatureIcon icon={Icon} />
                                            <div className="flex flex-col gap-2">
                                                <h3 className="text-base font-bold text-[#2d332d]">
                                                    {item.title}
                                                </h3>
                                                <p className="text-sm leading-6 font-medium text-[#676d64]">
                                                    {item.description}
                                                </p>
                                            </div>
                                        </CardContent>
                                    </Card>
                                );
                            })}
                        </div>
                    </section>

                    <section className="mx-auto grid max-w-[1200px] gap-7 px-6 pb-20 lg:grid-cols-[1.25fr_0.85fr] lg:items-stretch lg:px-10">
                        <div className="flex flex-col gap-6">
                            <SectionLabel>
                                {content.insights.badge}
                            </SectionLabel>
                            <GrowthChart content={content.insights} />
                        </div>
                        <div className="flex flex-col gap-5 lg:grid lg:min-h-full lg:grid-rows-3 lg:pt-12">
                            {content.insights.cards.map((item) => {
                                const Icon = resolveIcon(item.icon, Sparkles);

                                return (
                                    <Card
                                        key={item.title}
                                        className="h-full rounded-lg border-[#ded8ca] bg-[#fbf8ef] shadow-none"
                                    >
                                        <CardContent className="flex h-full items-center gap-5 p-7">
                                            <FeatureIcon icon={Icon} />
                                            <div className="min-w-0 grow">
                                                <h3 className="text-base font-bold text-[#2d332d]">
                                                    {item.title}
                                                </h3>
                                                <p className="mt-2 text-sm leading-6 font-medium text-[#676d64]">
                                                    {item.description}
                                                </p>
                                            </div>
                                            <DirArrow
                                                direction="forward"
                                                className="size-5 shrink-0 text-[#566256]"
                                            />
                                        </CardContent>
                                    </Card>
                                );
                            })}
                        </div>
                    </section>

                    <TestimonialCarousel content={content.testimonials} />

                    <FaqAccordion content={content.faq} />

                    <section className="mx-auto max-w-[1200px] px-6 pb-10 lg:px-10">
                        <div
                            className="overflow-hidden rounded-xl bg-[#071b17] px-9 py-9 text-white lg:px-20"
                            style={{
                                backgroundImage:
                                    'repeating-radial-gradient(circle at 82% 120%, rgba(184, 223, 168, 0.16) 0 1px, transparent 1px 15px)',
                            }}
                        >
                            <div className="flex flex-col gap-8 lg:flex-row lg:items-center lg:justify-between">
                                <div>
                                    <h2 className="marketing-display max-w-[520px] text-4xl leading-none text-[#f4f0e8] sm:text-5xl">
                                        {content.final_cta.title}
                                    </h2>
                                    <p className="mt-4 max-w-[520px] text-sm leading-6 font-medium text-[#bdc8bd]">
                                        {content.final_cta.description}
                                    </p>
                                </div>
                                <div className="flex flex-wrap gap-4">
                                    <Button
                                        className="h-[52px] rounded-md bg-[#f5f1e8] px-7 text-sm font-bold text-[#173f2c] hover:bg-white"
                                        asChild
                                    >
                                        <Link
                                            href={resolveHref(
                                                content.final_cta
                                                    .primary_button_href,
                                                primaryHref,
                                            )}
                                            {...ctaTargetProps}
                                        >
                                            {
                                                content.final_cta
                                                    .primary_button_label
                                            }
                                            <DirArrow
                                                direction="forward"
                                                data-icon="inline-end"
                                            />
                                        </Link>
                                    </Button>
                                    <Button
                                        variant="outline"
                                        className="h-[52px] rounded-md border-white/35 bg-transparent px-7 text-sm font-bold text-white hover:bg-white/10 hover:text-white"
                                        asChild
                                    >
                                        <Link
                                            href={resolveHref(
                                                content.final_cta
                                                    .secondary_button_href,
                                                primaryHref,
                                            )}
                                            {...ctaTargetProps}
                                        >
                                            {
                                                content.final_cta
                                                    .secondary_button_label
                                            }
                                        </Link>
                                    </Button>
                                </div>
                            </div>
                        </div>
                    </section>
                </div>
            </MarketingShell>
        </>
    );
}
