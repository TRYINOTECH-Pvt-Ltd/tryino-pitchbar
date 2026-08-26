import { Head, Link } from '@inertiajs/react';
import {
    BookOpen,
    CreditCard,
    FileText,
    Globe2,
    Map,
    MessageSquare,
    Rss,
    Sparkles,
    Type,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { MarketingShell } from '@/layouts/marketing-shell';
import type { MarketingShellContent } from '@/layouts/marketing-shell';
import { useT } from '@/lib/i18n';

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
    shell: MarketingShellContent;
    brand: string;
    native: Native[];
    data_sources: DataSource[];
    roadmap: RoadmapItem[];
};

const iconMap: Record<string, LucideIcon> = {
    BookOpen,
    CreditCard,
    FileText,
    Globe2,
    Map,
    MessageSquare,
    Rss,
    Sparkles,
    Type,
};

const accentClass: Record<Native['accent'], string> = {
    slate: 'bg-slate-100 text-slate-700 ring-slate-200/80',
    sky: 'bg-sky-100 text-sky-700 ring-sky-200/80',
    violet: 'bg-violet-100 text-violet-700 ring-violet-200/80',
    indigo: 'bg-indigo-100 text-indigo-700 ring-indigo-200/80',
    emerald: 'bg-emerald-100 text-emerald-700 ring-emerald-200/80',
    amber: 'bg-amber-100 text-amber-700 ring-amber-200/80',
};

function Icon({ name, className }: { name: string; className?: string }) {
    const Cmp = iconMap[name] ?? Sparkles;

    return <Cmp className={className} />;
}

export default function Integrations({
    shell,
    brand,
    native,
    data_sources,
    roadmap,
}: Props) {
    const { t } = useT();

    return (
        <>
            <Head title={t('Integrations — :brand', { brand })}>
                <meta
                    name="description"
                    content={t(
                        'Plug :brand into the tools your team already uses — Notion, Google Docs, Slack, Stripe, webhooks, and more.',
                        { brand },
                    )}
                />
            </Head>

            <MarketingShell content={shell}>
                <section className="mx-auto max-w-5xl px-6 pt-16 pb-14 lg:pt-20">
                    <div className="mx-auto max-w-3xl text-center">
                        <span className="inline-flex items-center gap-2 rounded-full border border-slate-900/10 bg-white/85 px-3 py-1 text-xs font-semibold tracking-[0.2em] text-slate-600 uppercase">
                            {t('Integrations')}
                        </span>
                        <h1 className="mt-6 text-4xl font-bold tracking-tight text-slate-950 sm:text-5xl">
                            {t('Plug :brand into the', { brand })}{' '}
                            <span className="marketing-display italic">
                                {t('tools you already use')}
                            </span>
                        </h1>
                        <p className="mt-5 text-base leading-7 text-slate-600 sm:text-lg">
                            {t(
                                'Native sync where it matters, signed webhooks everywhere else. Bring your knowledge in. Push leads out. Capture human escalations without leaving Slack.',
                            )}
                        </p>
                    </div>
                </section>

                <section className="mx-auto max-w-6xl px-6 pb-16">
                    <div className="grid gap-5 md:grid-cols-2">
                        {native.map((item) => (
                            <div
                                key={item.name}
                                className="rounded-3xl border border-slate-900/10 bg-white/85 p-6 shadow-[0_30px_70px_-60px_rgba(15,23,42,0.4)] backdrop-blur"
                            >
                                <div className="flex items-start gap-4">
                                    <span
                                        className={`flex size-12 shrink-0 items-center justify-center rounded-2xl ring-1 ${accentClass[item.accent]}`}
                                    >
                                        <Icon
                                            name={item.icon}
                                            className="size-5"
                                        />
                                    </span>
                                    <div className="min-w-0 flex-1">
                                        <p className="text-[11px] font-semibold tracking-[0.16em] text-slate-500 uppercase">
                                            {item.category}
                                        </p>
                                        <h3 className="mt-1 text-xl font-semibold tracking-tight text-slate-950">
                                            {item.name}
                                        </h3>
                                    </div>
                                </div>
                                <p className="mt-4 text-sm font-medium text-slate-800">
                                    {item.tagline}
                                </p>
                                <p className="mt-2 text-sm leading-6 text-slate-600">
                                    {item.description}
                                </p>
                            </div>
                        ))}
                    </div>
                </section>

                <section className="mx-auto max-w-6xl px-6 pb-16">
                    <div className="rounded-3xl border border-slate-900/10 bg-white/70 p-6 backdrop-blur sm:p-10">
                        <div className="grid gap-8 lg:grid-cols-[0.9fr_1.1fr]">
                            <div>
                                <span className="inline-flex items-center gap-2 rounded-full border border-slate-900/10 bg-white px-3 py-1 text-[11px] font-semibold tracking-[0.18em] text-slate-600 uppercase">
                                    {t('Knowledge in')}
                                </span>
                                <h2 className="mt-4 text-2xl font-semibold tracking-tight text-slate-950 sm:text-3xl">
                                    {t('Five ways to feed the agent')}
                                </h2>
                                <p className="mt-3 text-sm leading-6 text-slate-600 sm:text-base">
                                    {t(
                                        'Mix and match — most teams start with a sitemap crawl, paste a few FAQs, and turn on auto-index for the long tail.',
                                    )}
                                </p>
                            </div>

                            <ul className="grid gap-3">
                                {data_sources.map((src) => (
                                    <li
                                        key={src.name}
                                        className="flex items-start gap-3 rounded-2xl border border-slate-900/5 bg-white px-4 py-3"
                                    >
                                        <span className="mt-0.5 flex size-8 shrink-0 items-center justify-center rounded-lg bg-emerald-500/10 text-emerald-700">
                                            <Icon
                                                name={src.icon}
                                                className="size-4"
                                            />
                                        </span>
                                        <div className="min-w-0">
                                            <p className="text-sm font-semibold text-slate-950">
                                                {src.name}
                                            </p>
                                            <p className="mt-0.5 text-xs leading-5 text-slate-600">
                                                {src.tagline}
                                            </p>
                                        </div>
                                    </li>
                                ))}
                            </ul>
                        </div>
                    </div>
                </section>

                <section className="mx-auto max-w-5xl px-6 pb-16">
                    <div className="rounded-3xl border border-amber-300/40 bg-gradient-to-br from-amber-50 to-white p-6 backdrop-blur sm:p-10">
                        <div className="flex flex-wrap items-center justify-between gap-3">
                            <div>
                                <span className="inline-flex items-center gap-2 rounded-full border border-amber-400/40 bg-white px-3 py-1 text-[11px] font-semibold tracking-[0.18em] text-amber-700 uppercase">
                                    {t('On the roadmap')}
                                </span>
                                <h2 className="mt-3 text-2xl font-semibold tracking-tight text-slate-950 sm:text-3xl">
                                    {t('Coming soon')}
                                </h2>
                                <p className="mt-2 text-sm text-slate-600">
                                    {t(
                                        'Until these ship natively, the outgoing webhook + a Zapier catch-hook covers every CRM in your stack.',
                                    )}
                                </p>
                            </div>
                        </div>

                        <ul className="mt-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                            {roadmap.map((item) => (
                                <li
                                    key={item.name}
                                    className="rounded-2xl border border-slate-900/5 bg-white p-4"
                                >
                                    <p className="text-sm font-semibold text-slate-950">
                                        {item.name}
                                    </p>
                                    <p className="mt-1 text-xs leading-5 text-slate-600">
                                        {item.tagline}
                                    </p>
                                </li>
                            ))}
                        </ul>
                    </div>
                </section>

                <section className="mx-auto max-w-5xl px-6 pb-16">
                    <div className="rounded-3xl bg-slate-950 px-8 py-12 text-center sm:px-12">
                        <h2 className="text-2xl font-semibold tracking-tight text-white sm:text-3xl">
                            {t('Ready to plug it in?')}
                        </h2>
                        <p className="mt-3 text-sm text-slate-300 sm:text-base">
                            {t(
                                'Free to start. Connect Notion, Slack, and your first webhook in under 5 minutes.',
                            )}
                        </p>
                        <div className="mt-6 flex flex-col items-center justify-center gap-3 sm:flex-row">
                            <Link
                                href="/register"
                                className="inline-flex items-center justify-center rounded-full bg-white px-6 py-3 text-sm font-semibold text-slate-950 transition hover:bg-emerald-100"
                            >
                                {t('Get started free')}
                            </Link>
                            <a
                                href="/documentation/integrations"
                                target="_blank"
                                rel="noopener noreferrer"
                                className="inline-flex items-center justify-center gap-2 rounded-full border border-white/20 px-6 py-3 text-sm font-medium text-white transition hover:bg-white/10"
                            >
                                {t('Read the integration docs')}
                                <svg
                                    viewBox="0 0 24 24"
                                    fill="none"
                                    stroke="currentColor"
                                    strokeWidth="2"
                                    strokeLinecap="round"
                                    strokeLinejoin="round"
                                    className="size-3.5"
                                    aria-hidden="true"
                                >
                                    <path d="M7 17L17 7M9 7h8v8" />
                                </svg>
                            </a>
                        </div>
                    </div>
                </section>
            </MarketingShell>
        </>
    );
}
