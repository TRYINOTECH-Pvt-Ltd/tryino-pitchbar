import { ExternalLink, MessageSquare } from 'lucide-react';
import type { ReactNode } from 'react';
import { useT } from '@/lib/i18n';

/**
 * Admin-side renderers for the four block types the LLM emits via XML
 * markers in vertical presets:
 *
 *   - product_card  (ecommerce)
 *   - pricing_card  (saas)
 *   - case_study_card (marketing)
 *   - escalation_button (help_center / tool calls)
 *
 * Visual parity with the widget's `resources/widget/src/ui/blocks.tsx`
 * but built with shadcn/Tailwind so it inherits the admin theme. The
 * widget keeps its inline-style implementation because the widget
 * bundle can't depend on Tailwind.
 */
export type InlineBlock = {
    type: string;
    payload: Record<string, unknown>;
};

function ProductCard({ block }: { block: InlineBlock }) {
    const { t } = useT();
    const p = block.payload as Record<string, string | undefined>;
    const title = p.title ?? t('Product');
    const url = p.url;
    const image = p.image;
    const summary = p.summary;
    const price = formatPrice(p.price, p.currency);

    return (
        <a
            href={url ?? undefined}
            target={url ? '_blank' : undefined}
            rel={url ? 'noopener noreferrer' : undefined}
            className="mt-2 flex gap-3 rounded-xl border bg-card p-3 text-sm shadow-sm transition hover:border-primary/40 hover:shadow"
        >
            {image && (
                <img
                    src={image}
                    alt=""
                    className="h-16 w-16 flex-shrink-0 rounded-md bg-muted object-cover"
                    onError={(e) => {
                        (e.currentTarget as HTMLImageElement).style.display =
                            'none';
                    }}
                />
            )}
            <div className="flex min-w-0 flex-1 flex-col gap-0.5">
                <div className="truncate leading-tight font-semibold">
                    {title}
                </div>
                {summary && (
                    <div className="line-clamp-2 text-xs text-muted-foreground">
                        {summary}
                    </div>
                )}
                <div className="mt-1 flex items-center justify-between gap-2">
                    {price && (
                        <span className="font-bold text-foreground">
                            {price}
                        </span>
                    )}
                    {url && (
                        <span className="flex items-center gap-1 text-xs font-semibold text-primary">
                            {t('View')}
                            <ExternalLink className="h-3 w-3" />
                        </span>
                    )}
                </div>
            </div>
        </a>
    );
}

function PricingCard({ block }: { block: InlineBlock }) {
    const { t } = useT();
    const p = block.payload as Record<string, string | undefined>;
    const title = p.title ?? t('Plan');
    const price = formatPrice(p.price, p.currency);
    const period = p.period ? ` / ${p.period}` : '';
    const cta = p.cta ?? t('Get started');
    const url = p.url;

    return (
        <a
            href={url ?? undefined}
            target={url ? '_blank' : undefined}
            rel={url ? 'noopener noreferrer' : undefined}
            className="mt-2 block rounded-xl border border-indigo-200 bg-gradient-to-br from-indigo-50 to-background p-3 transition hover:shadow"
        >
            <div className="text-[11px] font-semibold tracking-wider text-indigo-700 uppercase">
                {title}
            </div>
            {price && (
                <div className="mt-1 text-2xl leading-none font-bold">
                    {price}
                    {period && (
                        <span className="ms-1 text-sm font-medium text-muted-foreground">
                            {period}
                        </span>
                    )}
                </div>
            )}
            <div className="mt-2 inline-flex rounded-full bg-indigo-700 px-3 py-1 text-xs font-semibold text-white">
                {cta} →
            </div>
        </a>
    );
}

function CaseStudyCard({ block }: { block: InlineBlock }) {
    const { t } = useT();
    const p = block.payload as Record<string, string | undefined>;
    const title = p.title ?? t('Case study');
    const outcome = p.outcome;
    const url = p.url;

    return (
        <a
            href={url ?? undefined}
            target={url ? '_blank' : undefined}
            rel={url ? 'noopener noreferrer' : undefined}
            className="mt-2 block rounded-xl border border-amber-200 bg-amber-50 p-3 transition hover:shadow"
        >
            <div className="text-[11px] font-semibold tracking-wider text-amber-800 uppercase">
                {t('Case study')}
            </div>
            <div className="mt-1 font-semibold text-foreground">{title}</div>
            {outcome && (
                <div className="mt-1 text-sm leading-snug text-muted-foreground">
                    {outcome}
                </div>
            )}
            {url && (
                <div className="mt-2 text-xs font-semibold text-amber-800">
                    {t('Read more →')}
                </div>
            )}
        </a>
    );
}

function EscalationButton({
    block,
    onEscalationClick,
}: {
    block: InlineBlock;
    onEscalationClick?: () => void;
}) {
    const { t } = useT();
    const label =
        typeof block.payload.label === 'string'
            ? block.payload.label
            : t('Connect me with a human');

    return (
        <div className="mt-2 flex">
            <button
                type="button"
                onClick={onEscalationClick}
                className="inline-flex items-center gap-2 rounded-full bg-foreground px-3.5 py-2 text-xs font-semibold text-background shadow-sm hover:opacity-90"
            >
                <MessageSquare className="h-3.5 w-3.5" />
                {label}
            </button>
        </div>
    );
}

type RendererProps = {
    block: InlineBlock;
    onEscalationClick?: () => void;
};

const RENDERERS: Record<string, (props: RendererProps) => ReactNode> = {
    product_card: ProductCard,
    pricing_card: PricingCard,
    case_study_card: CaseStudyCard,
    escalation_button: EscalationButton,
};

export function InlineBlockList({
    blocks,
    onEscalationClick,
}: {
    blocks: InlineBlock[];
    onEscalationClick?: () => void;
}) {
    const { t } = useT();

    if (!blocks.length) {
        return null;
    }

    return (
        <div className="mt-1 flex flex-col gap-1">
            {blocks.map((block, i) => {
                const Renderer = RENDERERS[block.type];

                if (!Renderer) {
                    return (
                        <div
                            key={i}
                            className="mt-2 rounded-md border border-dashed border-muted-foreground/30 px-2.5 py-1.5 text-xs text-muted-foreground"
                        >
                            {t('Unknown block type:')}{' '}
                            <code className="font-mono">{block.type}</code>
                        </div>
                    );
                }

                return (
                    <Renderer
                        key={i}
                        block={block}
                        onEscalationClick={onEscalationClick}
                    />
                );
            })}
        </div>
    );
}

function formatPrice(amount?: string, currency?: string): string | null {
    if (!amount) {
        return null;
    }

    const num = parseFloat(amount.replace(/[^0-9.]/g, ''));

    if (!Number.isFinite(num)) {
        return currency ? `${amount} ${currency}` : amount;
    }

    if (currency) {
        try {
            return new Intl.NumberFormat(undefined, {
                style: 'currency',
                currency: currency.toUpperCase(),
            }).format(num);
        } catch {
            return `${num.toFixed(2)} ${currency.toUpperCase()}`;
        }
    }

    return `$${num.toFixed(2)}`;
}
