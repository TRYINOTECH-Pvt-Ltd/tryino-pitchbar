import type { ComponentType } from 'preact';
import { useState } from 'preact/hooks';
import { t } from '../core/i18n';
import { safeHref, safeImageSrc } from '../core/safeUrl';
import type { Block } from '../core/store';

type BlockRendererProps = {
    block: Block;
    onLeadCapture?: () => void;
    onApplyCoupon?: (
        code: string,
    ) => Promise<{ ok: boolean; message?: string }>;
    /**
     * Fired when a follow-up suggestion chip is tapped. Bar wires this to
     * its `ask()` so the chip text becomes the next visitor turn.
     */
    onAsk?: (prompt: string) => void;
    /**
     * Mint a Stripe Checkout session for an in-chat <checkout/> block.
     * Returns the hosted `checkout_url` or an error object.
     */
    onStartCheckout?: (body: {
        title: string;
        amount: string;
        currency: string;
        description?: string;
        product_id?: string;
    }) => Promise<
        | { checkout_url: string; session_id: string; id: string }
        | { error: { code: string; message?: string } }
    >;
    /**
     * True while this block is attached to the trailing (most recent)
     * assistant message. Used by `SuggestionChips` to hide chips once
     * the visitor has moved on (sent the next turn or received another
     * assistant reply).
     */
    isTrailing?: boolean;
};

/**
 * "Connect me with a human" button. Triggers the existing lead-capture
 * form so the operator gets the visitor's email and can pick up the
 * conversation in Inbox.
 */
function EscalationButton({ block, onLeadCapture }: BlockRendererProps) {
    const label =
        typeof block.payload.label === 'string'
            ? block.payload.label
            : t('Connect me with a human');

    return (
        <div
            style={{
                display: 'flex',
                marginTop: 8,
            }}
        >
            <button
                type="button"
                onClick={() => onLeadCapture?.()}
                style={{
                    appearance: 'none',
                    border: '1px solid #cbd5e1',
                    background: '#0f172a',
                    color: '#ffffff',
                    fontSize: 13,
                    fontWeight: 600,
                    padding: '8px 14px',
                    borderRadius: 999,
                    cursor: 'pointer',
                    boxShadow: '0 1px 2px rgba(15, 23, 42, 0.12)',
                }}
            >
                {label}
            </button>
        </div>
    );
}

/**
 * Product card — server emits this when the LLM has been instructed by
 * the e-commerce preset to recommend a specific product. The buyer sees
 * an image, title, price, and a clear "Buy" / "View" button.
 *
 * Payload shape:
 *   { title, price, currency, url, image, summary }
 *
 * Every field is optional — we render whichever ones the LLM provided.
 */
function ProductCard({ block }: BlockRendererProps) {
    const p = block.payload as Record<string, string>;
    const title = p.title || t('Product');
    const price = p.price ? formatPrice(p.price, p.currency) : null;
    const url = safeHref(p.url);
    const image = safeImageSrc(p.image);
    const summary = p.summary;

    return (
        <a
            href={url ?? '#'}
            target={url ? '_blank' : undefined}
            rel={url ? 'noopener noreferrer' : undefined}
            style={{
                display: 'flex',
                gap: 12,
                marginTop: 8,
                padding: 10,
                borderRadius: 12,
                border: '1px solid #e2e8f0',
                background: '#ffffff',
                textDecoration: 'none',
                color: 'inherit',
                boxShadow: '0 1px 2px rgba(15,23,42,0.04)',
                cursor: url ? 'pointer' : 'default',
            }}
        >
            {image && (
                <img
                    src={image}
                    alt=""
                    style={{
                        width: 64,
                        height: 64,
                        objectFit: 'cover',
                        borderRadius: 8,
                        flexShrink: 0,
                        background: '#f8fafc',
                    }}
                    onError={(e) => {
                        (e.currentTarget as HTMLImageElement).style.display =
                            'none';
                    }}
                />
            )}
            <div
                style={{
                    flex: 1,
                    minWidth: 0,
                    display: 'flex',
                    flexDirection: 'column',
                    gap: 2,
                }}
            >
                <div
                    style={{
                        fontSize: 13,
                        fontWeight: 600,
                        lineHeight: 1.3,
                        overflow: 'hidden',
                        textOverflow: 'ellipsis',
                        whiteSpace: 'nowrap',
                    }}
                >
                    {title}
                </div>
                {summary && (
                    <div
                        style={{
                            fontSize: 12,
                            color: '#475569',
                            lineHeight: 1.4,
                            overflow: 'hidden',
                            display: '-webkit-box',
                            WebkitLineClamp: 2,
                            WebkitBoxOrient: 'vertical',
                        }}
                    >
                        {summary}
                    </div>
                )}
                <div
                    style={{
                        marginTop: 4,
                        display: 'flex',
                        alignItems: 'center',
                        justifyContent: 'space-between',
                        gap: 8,
                    }}
                >
                    {price && (
                        <span
                            style={{
                                fontSize: 14,
                                fontWeight: 700,
                                color: '#0f172a',
                            }}
                        >
                            {price}
                        </span>
                    )}
                    {url && (
                        <span
                            style={{
                                fontSize: 12,
                                fontWeight: 600,
                                color: '#2563eb',
                                whiteSpace: 'nowrap',
                            }}
                        >
                            {t('View')} →
                        </span>
                    )}
                </div>
            </div>
        </a>
    );
}

/**
 * Pricing card — emitted by the SaaS preset when the LLM mentions a
 * specific plan. Compact, focuses on plan name + price + a clear CTA.
 */
function PricingCard({ block }: BlockRendererProps) {
    const p = block.payload as Record<string, string>;
    const title = p.title || t('Plan');
    const price = p.price ? formatPrice(p.price, p.currency) : null;
    const period = p.period ? `/ ${p.period}` : '';
    const cta = p.cta || t('Get started');
    const url = safeHref(p.url);

    return (
        <a
            href={url ?? '#'}
            target={url ? '_blank' : undefined}
            rel={url ? 'noopener noreferrer' : undefined}
            style={{
                display: 'block',
                marginTop: 8,
                padding: 12,
                borderRadius: 12,
                border: '1px solid #c7d2fe',
                background: 'linear-gradient(135deg,#eef2ff 0%,#ffffff 100%)',
                textDecoration: 'none',
                color: 'inherit',
                cursor: url ? 'pointer' : 'default',
            }}
        >
            <div
                style={{
                    fontSize: 11,
                    fontWeight: 600,
                    color: '#4338ca',
                    textTransform: 'uppercase',
                    letterSpacing: 0.5,
                }}
            >
                {title}
            </div>
            {price && (
                <div
                    style={{
                        marginTop: 4,
                        fontSize: 22,
                        fontWeight: 700,
                        color: '#0f172a',
                        lineHeight: 1,
                    }}
                >
                    {price}
                    {period && (
                        <span
                            style={{
                                fontSize: 13,
                                fontWeight: 500,
                                color: '#64748b',
                                marginInlineStart: 4,
                            }}
                        >
                            {period}
                        </span>
                    )}
                </div>
            )}
            <div
                style={{
                    marginTop: 10,
                    display: 'inline-block',
                    padding: '6px 12px',
                    borderRadius: 999,
                    background: '#4338ca',
                    color: '#ffffff',
                    fontSize: 12,
                    fontWeight: 600,
                }}
            >
                {cta} →
            </div>
        </a>
    );
}

/**
 * Case study card — emitted by the marketing preset to surface a proof
 * point the LLM mentioned in its reply.
 */
function CaseStudyCard({ block }: BlockRendererProps) {
    const p = block.payload as Record<string, string>;
    const title = p.title || t('Case study');
    const outcome = p.outcome;
    const url = safeHref(p.url);

    return (
        <a
            href={url ?? '#'}
            target={url ? '_blank' : undefined}
            rel={url ? 'noopener noreferrer' : undefined}
            style={{
                display: 'block',
                marginTop: 8,
                padding: 12,
                borderRadius: 12,
                border: '1px solid #fde68a',
                background: '#fffbeb',
                textDecoration: 'none',
                color: 'inherit',
                cursor: url ? 'pointer' : 'default',
            }}
        >
            <div
                style={{
                    fontSize: 11,
                    fontWeight: 600,
                    color: '#b45309',
                    textTransform: 'uppercase',
                    letterSpacing: 0.5,
                }}
            >
                {t('Case study')}
            </div>
            <div
                style={{
                    marginTop: 4,
                    fontSize: 14,
                    fontWeight: 600,
                    color: '#0f172a',
                }}
            >
                {title}
            </div>
            {outcome && (
                <div
                    style={{
                        marginTop: 4,
                        fontSize: 13,
                        color: '#475569',
                        lineHeight: 1.4,
                    }}
                >
                    {outcome}
                </div>
            )}
            {url && (
                <div
                    style={{
                        marginTop: 8,
                        fontSize: 12,
                        fontWeight: 600,
                        color: '#b45309',
                    }}
                >
                    {t('Read more')} →
                </div>
            )}
        </a>
    );
}

/**
 * Best-effort price formatter. The LLM emits a raw amount like "49.00"
 * and an optional ISO currency code; we render with the visitor's
 * locale-friendly Intl formatter when possible, fall back to a simple
 * "$49.00" / "49.00 USD" join otherwise.
 */
function formatPrice(amount: string, currency?: string): string {
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

/**
 * Promotional coupon emitted by `EcommercePreset` when the visitor's
 * intent + an active store promo line up. Renders the code with a
 * Copy button (clipboard) and an Apply button that wires through to
 * the plugin's `/wp-json/pitchbar/v1/cart/coupon` REST endpoint via
 * the widget's `/coupon/apply` proxy. Apply is best-effort — the
 * code lands as a transient on the WP site and applies on next cart
 * load.
 */
function CouponCard({ block, onApplyCoupon }: BlockRendererProps) {
    const code =
        typeof block.payload.code === 'string'
            ? block.payload.code.toUpperCase()
            : '';
    const label =
        typeof block.payload.label === 'string' ? block.payload.label : '';
    const discount =
        typeof block.payload.discount === 'string'
            ? block.payload.discount
            : '';
    const url = typeof block.payload.url === 'string' ? block.payload.url : '';

    const [copied, setCopied] = useState(false);
    const [applied, setApplied] = useState<null | 'ok' | 'error'>(null);
    const [busy, setBusy] = useState(false);

    if (code === '') {
        return null;
    }

    const copy = async () => {
        const flagCopied = () => {
            setCopied(true);
            window.setTimeout(() => setCopied(false), 1500);
        };
        // Async Clipboard API needs a secure context (https) and isn't in
        // older Safari. Try it, then fall back to the execCommand path so a
        // visitor on http / an older browser can still copy the coupon.
        try {
            if (navigator.clipboard?.writeText) {
                await navigator.clipboard.writeText(code);
                flagCopied();

                return;
            }
        } catch {
            // fall through to the legacy path
        }
        try {
            const ta = document.createElement('textarea');
            ta.value = code;
            ta.style.position = 'fixed';
            ta.style.opacity = '0';
            document.body.appendChild(ta);
            ta.focus();
            ta.select();
            document.execCommand('copy');
            document.body.removeChild(ta);
            flagCopied();
        } catch {
            // give up silently — the code is still visible to copy by hand
        }
    };

    const apply = async () => {
        if (!onApplyCoupon || busy) {
            return;
        }

        setBusy(true);

        try {
            const result = await onApplyCoupon(code);
            setApplied(result.ok ? 'ok' : 'error');
        } catch {
            setApplied('error');
        } finally {
            setBusy(false);
        }
    };

    return (
        <div
            style={{
                marginTop: 8,
                padding: 12,
                borderRadius: 10,
                background: 'linear-gradient(135deg,#fef3c7 0%,#fde68a 100%)',
                border: '1px solid #fbbf24',
            }}
        >
            <div
                style={{
                    fontSize: 11,
                    fontWeight: 700,
                    textTransform: 'uppercase',
                    letterSpacing: 0.6,
                    color: '#92400e',
                }}
            >
                {t('Coupon')}
            </div>
            <div
                style={{
                    marginTop: 4,
                    fontFamily: 'ui-monospace,SFMono-Regular,Menlo,monospace',
                    fontSize: 18,
                    fontWeight: 700,
                    color: '#78350f',
                    letterSpacing: 1.2,
                }}
            >
                {code}
            </div>
            {(label || discount) && (
                <div
                    style={{
                        marginTop: 4,
                        fontSize: 13,
                        color: '#78350f',
                        lineHeight: 1.4,
                    }}
                >
                    {label || discount}
                </div>
            )}
            <div
                style={{
                    marginTop: 10,
                    display: 'flex',
                    gap: 6,
                    flexWrap: 'wrap',
                }}
            >
                <button
                    type="button"
                    onClick={copy}
                    style={{
                        appearance: 'none',
                        border: '1px solid #d97706',
                        background: '#fff',
                        color: '#92400e',
                        padding: '6px 10px',
                        borderRadius: 6,
                        fontSize: 12,
                        fontWeight: 600,
                        cursor: 'pointer',
                    }}
                >
                    {copied ? t('Copied') : t('Copy code')}
                </button>
                {onApplyCoupon && (
                    <button
                        type="button"
                        onClick={apply}
                        disabled={busy || applied === 'ok'}
                        style={{
                            appearance: 'none',
                            border: 0,
                            background:
                                applied === 'ok' ? '#16a34a' : '#92400e',
                            color: '#fff',
                            padding: '6px 10px',
                            borderRadius: 6,
                            fontSize: 12,
                            fontWeight: 600,
                            cursor:
                                busy || applied === 'ok'
                                    ? 'default'
                                    : 'pointer',
                            opacity: busy ? 0.7 : 1,
                        }}
                    >
                        {applied === 'ok'
                            ? t('Applied ✓')
                            : busy
                              ? t('Applying…')
                              : t('Apply to cart')}
                    </button>
                )}
                {url && (
                    <a
                        href={url}
                        target="_blank"
                        rel="noopener noreferrer"
                        style={{
                            textDecoration: 'none',
                            color: '#92400e',
                            padding: '6px 10px',
                            fontSize: 12,
                            fontWeight: 600,
                        }}
                    >
                        {t('Learn more')} →
                    </a>
                )}
            </div>
            {applied === 'error' && (
                <div
                    style={{
                        marginTop: 8,
                        fontSize: 12,
                        color: '#b91c1c',
                    }}
                >
                    {t(
                        "Couldn't apply the coupon. Try opening your cart and pasting the code manually.",
                    )}
                </div>
            )}
        </div>
    );
}

/**
 * Suggestion chips — emitted at the end of every assistant reply via the
 * `<suggestions q1=... q2=... q3=.../>` marker. Tapping a chip re-sends
 * its text as the next visitor turn. Chips disappear once the visitor
 * sends their next message (only the trailing assistant message renders
 * its chips).
 */
function SuggestionChips({ block, onAsk, isTrailing }: BlockRendererProps) {
    if (!isTrailing || !onAsk) {
        return null;
    }

    const p = block.payload as Record<string, string>;
    const prompts = [p.q1, p.q2, p.q3]
        .map((q) => (typeof q === 'string' ? q.trim() : ''))
        .filter((q) => q.length > 0 && q.length <= 120);

    if (prompts.length === 0) {
        return null;
    }

    return (
        <div
            style={{
                display: 'flex',
                flexWrap: 'wrap',
                gap: 6,
                marginTop: 8,
            }}
        >
            {prompts.map((prompt, i) => (
                <button
                    key={`${prompt}-${i}`}
                    type="button"
                    onClick={() => onAsk(prompt)}
                    style={{
                        appearance: 'none',
                        padding: '6px 12px',
                        borderRadius: 999,
                        border: '1px solid #cbd5e1',
                        background: '#ffffff',
                        color: '#0f172a',
                        fontSize: 12,
                        fontWeight: 500,
                        cursor: 'pointer',
                        textAlign: 'start',
                        maxWidth: '100%',
                        lineHeight: 1.3,
                        boxShadow: '0 1px 1px rgba(15, 23, 42, 0.04)',
                    }}
                >
                    {prompt}
                </button>
            ))}
        </div>
    );
}

function CheckoutCard({ block, onStartCheckout }: BlockRendererProps) {
    const payload = block.payload as Record<string, string>;
    const title = (payload.title ?? '').trim();
    const amount = (payload.amount ?? '').trim();
    const currency = (payload.currency ?? 'USD').trim().toUpperCase();
    const description = (payload.description ?? '').trim();
    const [state, setState] = useState<
        'idle' | 'loading' | 'redirecting' | 'error'
    >('idle');
    const [errorMsg, setErrorMsg] = useState<string | null>(null);

    if (!title || !amount) {
        return null;
    }

    const formattedAmount = (() => {
        const n = Number(amount);

        if (!Number.isFinite(n)) {
            return amount;
        }

        try {
            return new Intl.NumberFormat(undefined, {
                style: 'currency',
                currency,
            }).format(n);
        } catch {
            return `${currency} ${n.toFixed(2)}`;
        }
    })();

    const onPay = async () => {
        if (!onStartCheckout) {
            setErrorMsg('Checkout is not available in this widget build.');
            setState('error');

            return;
        }

        setErrorMsg(null);
        setState('loading');

        try {
            const result = await onStartCheckout({
                title,
                amount,
                currency,
                description,
                product_id: payload.product_id,
            });

            if ('error' in result) {
                setErrorMsg(result.error.message ?? 'Could not start payment.');
                setState('error');

                return;
            }

            setState('redirecting');
            window.open(result.checkout_url, '_blank', 'noopener,noreferrer');
            setTimeout(() => setState('idle'), 1200);
        } catch (e) {
            setErrorMsg(e instanceof Error ? e.message : 'Payment failed.');
            setState('error');
        }
    };

    return (
        <div
            style={{
                border: '1px solid rgba(15, 23, 42, 0.1)',
                borderRadius: 12,
                padding: 12,
                background: 'white',
                color: '#0f172a',
                marginTop: 8,
                maxWidth: '100%',
                boxShadow: '0 1px 2px rgba(15, 23, 42, 0.04)',
            }}
        >
            <div
                style={{
                    fontSize: 13,
                    fontWeight: 600,
                    marginBottom: 2,
                }}
            >
                {title}
            </div>
            {description && (
                <div
                    style={{
                        fontSize: 12,
                        color: '#475569',
                        marginBottom: 8,
                    }}
                >
                    {description}
                </div>
            )}
            <div
                style={{
                    display: 'flex',
                    alignItems: 'center',
                    justifyContent: 'space-between',
                    gap: 8,
                    marginTop: 8,
                }}
            >
                <div
                    style={{
                        fontSize: 16,
                        fontWeight: 700,
                    }}
                >
                    {formattedAmount}
                </div>
                <button
                    type="button"
                    onClick={onPay}
                    disabled={state === 'loading' || state === 'redirecting'}
                    style={{
                        padding: '6px 12px',
                        borderRadius: 8,
                        border: 'none',
                        background:
                            state === 'loading' || state === 'redirecting'
                                ? '#64748b'
                                : '#0f172a',
                        color: 'white',
                        fontSize: 12,
                        fontWeight: 600,
                        cursor:
                            state === 'loading' || state === 'redirecting'
                                ? 'wait'
                                : 'pointer',
                    }}
                >
                    {state === 'loading'
                        ? t('Starting…')
                        : state === 'redirecting'
                          ? t('Opening Stripe…')
                          : t('Pay now')}
                </button>
            </div>
            {errorMsg && (
                <div
                    style={{
                        marginTop: 8,
                        fontSize: 11,
                        color: '#b91c1c',
                    }}
                >
                    {errorMsg}
                </div>
            )}
        </div>
    );
}

const RENDERERS: Record<string, ComponentType<BlockRendererProps>> = {
    escalation_button: EscalationButton,
    product_card: ProductCard,
    pricing_card: PricingCard,
    case_study_card: CaseStudyCard,
    coupon_card: CouponCard,
    suggestion_chips: SuggestionChips,
    checkout_card: CheckoutCard,
};

/**
 * Look up the renderer for a block type. Returns null when the bundle
 * doesn't have a renderer (older bundle, newer block type) — the
 * widget silently drops the block rather than rendering raw JSON.
 */
export function rendererFor(
    type: string,
): ComponentType<BlockRendererProps> | null {
    return RENDERERS[type] ?? null;
}

/**
 * Renders every block on a message. Unknown types are silently
 * skipped. Renderers receive a small callback bag so e.g. the
 * escalation button can open the lead form.
 */
export function BlockList({
    blocks,
    onLeadCapture,
    onApplyCoupon,
    onAsk,
    onStartCheckout,
    isTrailing,
}: {
    blocks: Block[];
    onLeadCapture?: () => void;
    onApplyCoupon?: (
        code: string,
    ) => Promise<{ ok: boolean; message?: string }>;
    onAsk?: (prompt: string) => void;
    onStartCheckout?: (body: {
        title: string;
        amount: string;
        currency: string;
        description?: string;
        product_id?: string;
    }) => Promise<
        | { checkout_url: string; session_id: string; id: string }
        | { error: { code: string; message?: string } }
    >;
    isTrailing?: boolean;
}) {
    if (!blocks.length) {
        return null;
    }

    return (
        <>
            {blocks.map((block, i) => {
                const Renderer = rendererFor(block.type);

                if (!Renderer) {
                    return null;
                }

                return (
                    <Renderer
                        key={`${block.type}-${i}`}
                        block={block}
                        onLeadCapture={onLeadCapture}
                        onApplyCoupon={onApplyCoupon}
                        onAsk={onAsk}
                        onStartCheckout={onStartCheckout}
                        isTrailing={isTrailing}
                    />
                );
            })}
        </>
    );
}
