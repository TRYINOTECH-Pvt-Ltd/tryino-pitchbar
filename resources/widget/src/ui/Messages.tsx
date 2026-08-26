import { Fragment } from 'preact';
import { useEffect, useRef, useState } from 'preact/hooks';
import { t } from '../core/i18n';
import { safeHref } from '../core/safeUrl';
import type { Message, WidgetState } from '../core/store';
import { BlockList } from './blocks';

type Scheme = 'light' | 'dark';

type Props = {
    messages: Message[];
    starterPrompts?: string[];
    onRetry?: (failedAssistantId: string) => void;
    onPromptClick?: (prompt: string) => void;
    onLeadCapture?: () => void;
    onApplyCoupon?: (
        code: string,
    ) => Promise<{ ok: boolean; message?: string }>;
    /**
     * Fired when the visitor taps a `<suggestions>` chip rendered under
     * the assistant's last reply. Bar routes the prompt back through its
     * `ask()` so the chip text becomes the next visitor turn.
     */
    onAsk?: (prompt: string) => void;
    /**
     * Mint a Stripe Checkout session for an in-chat <checkout/> block.
     * Bar wires this to `api.createCheckout`.
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
     * Server-emitted progress hint for the pending bubble: 'searching'
     * while retrieval runs, 'thinking' while the LLM composes. Bar
     * passes this through from the store so the indicator copy reflects
     * what's actually happening before the first token lands.
     */
    currentStage?: WidgetState['currentStage'];
    /**
     * Active color scheme — Bar resolves auto → light|dark from the OS
     * setting and forwards the concrete value so message bubbles can
     * pick a readable palette instead of hard-coding light-mode hexes.
     */
    scheme?: Scheme;
};

type BubblePalette = {
    assistantBg: string;
    assistantText: string;
    cursor: string;
    errorBg: string;
    errorText: string;
    errorBorder: string;
    userBg: string;
    userText: string;
    humanBg: string;
    humanText: string;
    humanBorder: string;
    emptyText: string;
    chipBg: string;
    chipText: string;
    chipBorder: string;
    retryBg: string;
};

const LIGHT_BUBBLE: BubblePalette = {
    assistantBg: '#f3f4f6',
    assistantText: '#0f172a',
    cursor: '#0f172a',
    errorBg: '#fef2f2',
    errorText: '#991b1b',
    errorBorder: '#fecaca',
    userBg: '#111827',
    userText: '#ffffff',
    humanBg: '#ecfdf5',
    humanText: '#0f172a',
    humanBorder: '#a7f3d0',
    emptyText: '#6b7280',
    chipBg: '#ffffff',
    chipText: '#0f172a',
    chipBorder: '#e5e7eb',
    retryBg: '#ffffff',
};

const DARK_BUBBLE: BubblePalette = {
    assistantBg: '#1e293b',
    assistantText: '#e2e8f0',
    cursor: '#e2e8f0',
    errorBg: '#3f1d1d',
    errorText: '#fca5a5',
    errorBorder: '#7f1d1d',
    userBg: '#e2e8f0',
    userText: '#0f172a',
    humanBg: '#064e3b',
    humanText: '#a7f3d0',
    humanBorder: '#065f46',
    emptyText: '#94a3b8',
    chipBg: '#1e293b',
    chipText: '#e2e8f0',
    chipBorder: '#1f2937',
    retryBg: '#1e293b',
};

function paletteFor(scheme: Scheme | undefined): BubblePalette {
    return scheme === 'dark' ? DARK_BUBBLE : LIGHT_BUBBLE;
}

type Citation = { id: number; url: string | null };

// One left-to-right scan that linkifies, in priority order:
//   1. markdown links            [label](https://… | mailto:… | tel:…)
//   2. bare URLs                 https://example.com/x
//   3. citation refs             [3]  (resolved against `citations`)
// Everything else passes through as text. Every href is run through
// safeHref (http/https/mailto/tel allowlist) so a model can't emit a
// javascript: link. Streaming-safe: a half-streamed "[label](https://ac"
// simply doesn't match yet and renders literal until the closing ")".
const RICH_TOKEN =
    /\[([^\]\n]+)\]\(((?:https?:\/\/|mailto:|tel:)[^\s)]+)\)|((?:https?:\/\/)[^\s<]+)|\[(\d+)\]/g;

function linkStyle(): preact.JSX.CSSProperties {
    return {
        color: 'inherit',
        textDecoration: 'underline',
        textUnderlineOffset: 2,
        fontWeight: 600,
        wordBreak: 'break-word',
    };
}

function renderRichText(text: string, citations: Citation[]) {
    const byId = new Map((citations ?? []).map((c) => [c.id, c.url] as const));
    const parts: (string | preact.JSX.Element)[] = [];
    const regex = new RegExp(RICH_TOKEN);
    let lastIndex = 0;
    let match: RegExpExecArray | null;

    while ((match = regex.exec(text)) !== null) {
        if (match.index > lastIndex) {
            parts.push(text.slice(lastIndex, match.index));
        }

        const [whole, mdLabel, mdUrl, bareUrl, citeId] = match;

        if (mdLabel !== undefined && mdUrl !== undefined) {
            const href = safeHref(mdUrl);
            parts.push(
                href ? (
                    <a
                        key={`md-${match.index}`}
                        href={href}
                        target="_blank"
                        rel="noopener noreferrer"
                        title={href}
                        style={linkStyle()}
                    >
                        {mdLabel}
                    </a>
                ) : (
                    whole
                ),
            );
        } else if (bareUrl !== undefined) {
            // Trailing sentence punctuation isn't part of the URL.
            const trail = bareUrl.match(/[).,;:!?]+$/)?.[0] ?? '';
            const url = trail ? bareUrl.slice(0, -trail.length) : bareUrl;
            const href = safeHref(url);

            if (href) {
                parts.push(
                    <a
                        key={`url-${match.index}`}
                        href={href}
                        target="_blank"
                        rel="noopener noreferrer"
                        title={href}
                        style={linkStyle()}
                    >
                        {url}
                    </a>,
                );

                if (trail) {
                    parts.push(trail);
                }
            } else {
                parts.push(whole);
            }
        } else if (citeId !== undefined) {
            const id = Number(citeId);
            const url = safeHref(byId.get(id) ?? null);
            parts.push(
                url ? (
                    <a
                        key={`cite-${match.index}`}
                        href={url}
                        target="_blank"
                        rel="noopener noreferrer"
                        title={url}
                        style={{
                            color: 'inherit',
                            textDecoration: 'none',
                            background: 'rgba(99, 102, 241, 0.15)',
                            borderRadius: 4,
                            padding: '0 4px',
                            fontSize: '0.85em',
                            fontWeight: 600,
                            margin: '0 1px',
                        }}
                    >
                        [{id}]
                    </a>
                ) : (
                    whole
                ),
            );
        }

        lastIndex = match.index + whole.length;
    }

    if (parts.length === 0) {
        return text;
    }

    if (lastIndex < text.length) {
        parts.push(text.slice(lastIndex));
    }

    return parts.map((p, i) => <Fragment key={i}>{p}</Fragment>);
}

/**
 * Pick a (chars-per-tick, ms-per-tick) pair based on how far the
 * displayed text is behind the actual streamed content. The further
 * behind, the more aggressively we catch up — we don't want a long
 * reply to leave the visitor staring at a half-typed message ten
 * seconds after the stream is done.
 */
function typewriterPace(charsBehind: number): { step: number; delay: number } {
    if (charsBehind > 240) {
        return { step: 32, delay: 8 };
    }

    if (charsBehind > 60) {
        return { step: 4, delay: 12 };
    }

    return { step: 1, delay: 22 };
}

type AssistantBubbleProps = {
    message: Message;
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
    /**
     * True when this assistant message is the trailing message in the
     * thread. Forwarded to `BlockList` → `SuggestionChips` so chips only
     * render on the latest reply (they vanish once the visitor sends
     * the next turn).
     */
    isTrailing?: boolean;
    /** See Messages props. Drives the pre-token indicator copy. */
    currentStage?: WidgetState['currentStage'];
    palette: BubblePalette;
};

function stageLabel(stage: WidgetState['currentStage']): string {
    if (stage === 'searching') {
        return t('Searching your site…');
    }

    if (stage === 'thinking') {
        return t('Thinking…');
    }

    // Any other non-empty value is a ready-to-render tool label set by
    // the tool_call handler ("Checking your order…").
    if (typeof stage === 'string' && stage !== '') {
        return stage;
    }

    return t('thinking…');
}

function AssistantBubble({
    message,
    onLeadCapture,
    onApplyCoupon,
    onAsk,
    onStartCheckout,
    isTrailing,
    currentStage,
    palette,
}: AssistantBubbleProps) {
    const target = message.content.length;
    // Capture at mount whether this message was streaming. Hydrated
    // history from /init lands here non-pending and renders instantly;
    // freshly-streamed turns mount with pending=true and animate.
    const [animate] = useState(() => message.pending === true);
    const [displayed, setDisplayed] = useState(animate ? 0 : target);

    useEffect(() => {
        if (displayed >= target) {
            return;
        }

        const { step, delay } = typewriterPace(target - displayed);
        const id = window.setTimeout(() => {
            setDisplayed((d) => Math.min(target, d + step));
        }, delay);

        return () => window.clearTimeout(id);
    }, [displayed, target]);

    const visible = message.content.slice(0, displayed);
    const stillTyping = displayed < target;
    const showCursor = message.pending === true || stillTyping;

    return (
        <div
            style={{
                padding: '10px 14px',
                borderRadius: 12,
                background: message.error
                    ? palette.errorBg
                    : palette.assistantBg,
                color: message.error
                    ? palette.errorText
                    : palette.assistantText,
                border: message.error
                    ? `1px solid ${palette.errorBorder}`
                    : 'none',
                fontSize: 14,
                whiteSpace: 'pre-wrap',
            }}
        >
            {message.pending && message.content === '' ? (
                <em style={{ opacity: 0.7 }}>
                    {isTrailing ? stageLabel(currentStage) : t('thinking…')}
                </em>
            ) : (
                <>
                    {renderRichText(visible, message.citations ?? [])}
                    {showCursor && (
                        <span
                            aria-hidden="true"
                            style={{
                                display: 'inline-block',
                                width: 2,
                                height: '1em',
                                marginInlineStart: 2,
                                background: palette.cursor,
                                verticalAlign: '-0.15em',
                                animation:
                                    'pitchbar-cursor 1s steps(2, end) infinite',
                            }}
                        />
                    )}
                </>
            )}
            {message.blocks && message.blocks.length > 0 && (
                <BlockList
                    blocks={message.blocks}
                    onLeadCapture={onLeadCapture}
                    onApplyCoupon={onApplyCoupon}
                    onAsk={onAsk}
                    onStartCheckout={onStartCheckout}
                    isTrailing={isTrailing}
                />
            )}
            {!stillTyping &&
                message.citations &&
                message.citations.length > 0 && (
                    <div
                        style={{
                            marginTop: 6,
                            fontSize: 11,
                            opacity: 0.7,
                        }}
                    >
                        {t('Sources')}:{' '}
                        {message.citations.map((c, idx) => (
                            <a
                                key={idx}
                                href={safeHref(c.url) ?? '#'}
                                target="_blank"
                                rel="noopener noreferrer"
                                style={{
                                    color: 'inherit',
                                    textDecoration: 'underline',
                                    marginInlineEnd: 6,
                                }}
                            >
                                [{c.id}]
                            </a>
                        ))}
                    </div>
                )}
        </div>
    );
}

export function Messages({
    messages,
    starterPrompts,
    onRetry,
    onPromptClick,
    onLeadCapture,
    onApplyCoupon,
    onAsk,
    onStartCheckout,
    currentStage,
    scheme,
}: Props) {
    const palette = paletteFor(scheme);
    const containerRef = useRef<HTMLDivElement | null>(null);
    const contentRef = useRef<HTMLDivElement | null>(null);
    const lastSeenIdRef = useRef<string | undefined>(undefined);
    const lastMessageId = messages[messages.length - 1]?.id;

    // Force-scroll to the bottom every time a new message lands (id of
    // the trailing message changes). Covers three cases that the
    // ResizeObserver below can miss or shouldn't fire on:
    //
    //   1. First mount with hydrated history — visitor opens the bar
    //      and should land on the LATEST turn, not scrolled up to the
    //      first message in their thread.
    //   2. Visitor sends a turn — the new user bubble + pending
    //      assistant placeholder must always be visible, even if the
    //      visitor had scrolled up to read older history.
    //   3. Retry — old failed bubble removed, new assistant placeholder
    //      mounts; should anchor to the new turn.
    //
    // The ResizeObserver effect handles streaming-token growth and is
    // intentionally near-bottom-gated so we don't yank the visitor down
    // mid-read.
    useEffect(() => {
        if (!lastMessageId || lastMessageId === lastSeenIdRef.current) {
            return;
        }

        lastSeenIdRef.current = lastMessageId;
        const scroller = containerRef.current;

        if (scroller) {
            scroller.scrollTop = scroller.scrollHeight;
        }
    }, [lastMessageId]);

    // ResizeObserver replaces the per-message-length scroll effect:
    // typewriter advances grow the inner content's height without
    // changing the messages array, so a deps-based effect would miss
    // them. Observing the content div catches every height change
    // (typewriter, citations footer revealing, retry chip mounting).
    useEffect(() => {
        const scroller = containerRef.current;
        const content = contentRef.current;

        if (!scroller || !content) {
            return;
        }

        const onResize = () => {
            const distanceFromBottom =
                scroller.scrollHeight -
                scroller.scrollTop -
                scroller.clientHeight;

            // Only auto-scroll if the visitor was already near the
            // bottom — don't yank them down if they've scrolled up.
            if (distanceFromBottom < 96) {
                scroller.scrollTop = scroller.scrollHeight;
            }
        };

        // ResizeObserver is in every modern engine, but guard anyway so a
        // very old browser degrades to a window-resize listener instead of
        // throwing ReferenceError and killing the message list.
        if (typeof ResizeObserver === 'undefined') {
            onResize();
            window.addEventListener('resize', onResize);

            return () => window.removeEventListener('resize', onResize);
        }

        const ro = new ResizeObserver(onResize);
        ro.observe(content);

        return () => ro.disconnect();
    }, []);

    if (messages.length === 0) {
        const hasPrompts =
            starterPrompts !== undefined &&
            starterPrompts.length > 0 &&
            onPromptClick !== undefined;

        return (
            <div
                role="log"
                style={{
                    padding: hasPrompts ? '24px 16px' : 32,
                    textAlign: 'center',
                    color: palette.emptyText,
                    fontSize: 14,
                }}
            >
                <div style={{ marginBottom: hasPrompts ? 12 : 0 }}>
                    {t('Ask anything about this site.')}
                </div>
                {hasPrompts && (
                    <div
                        style={{
                            display: 'flex',
                            flexWrap: 'wrap',
                            gap: 6,
                            justifyContent: 'center',
                        }}
                    >
                        {starterPrompts.map((prompt, i) => (
                            <button
                                key={`${prompt}-${i}`}
                                type="button"
                                onClick={() => onPromptClick(prompt)}
                                style={{
                                    padding: '6px 12px',
                                    borderRadius: 999,
                                    border: `1px solid ${palette.chipBorder}`,
                                    background: palette.chipBg,
                                    color: palette.chipText,
                                    fontSize: 13,
                                    cursor: 'pointer',
                                    maxWidth: '100%',
                                    whiteSpace: 'normal',
                                    textAlign: 'start',
                                }}
                            >
                                {prompt}
                            </button>
                        ))}
                    </div>
                )}
            </div>
        );
    }

    return (
        <div
            ref={containerRef}
            role="log"
            aria-live="polite"
            style={{
                padding: 16,
                // Fills the flex column in Bar (which is overflow:hidden)
                // and owns the thread's ONLY scrollbar — a fixed
                // maxHeight here nested a second scroller inside Bar's.
                flex: '1 1 auto',
                minHeight: 0,
                overflowY: 'auto',
            }}
        >
            <div
                ref={contentRef}
                style={{
                    display: 'flex',
                    flexDirection: 'column',
                    gap: 10,
                }}
            >
                {messages.map((m, idx) => {
                    const isUser = m.role === 'user';
                    const isHuman = m.role === 'human-agent';
                    const isAssistant = m.role === 'assistant';
                    const isTrailing = idx === messages.length - 1;

                    return (
                        <div
                            key={m.id}
                            style={{
                                display: 'flex',
                                flexDirection: 'column',
                                alignSelf: isUser ? 'flex-end' : 'flex-start',
                                maxWidth: '85%',
                            }}
                        >
                            {isHuman && (
                                <span
                                    style={{
                                        fontSize: 10,
                                        color: '#059669',
                                        fontWeight: 600,
                                        marginBottom: 2,
                                        textTransform: 'uppercase',
                                        letterSpacing: 0.4,
                                    }}
                                >
                                    {t('live agent')}
                                </span>
                            )}
                            {isAssistant ? (
                                <AssistantBubble
                                    message={m}
                                    onLeadCapture={onLeadCapture}
                                    onApplyCoupon={onApplyCoupon}
                                    onAsk={onAsk}
                                    onStartCheckout={onStartCheckout}
                                    isTrailing={isTrailing}
                                    currentStage={currentStage}
                                    palette={palette}
                                />
                            ) : (
                                <div
                                    style={{
                                        padding: '10px 14px',
                                        borderRadius: 12,
                                        background: isUser
                                            ? palette.userBg
                                            : palette.humanBg,
                                        color: isUser
                                            ? palette.userText
                                            : palette.humanText,
                                        border: isHuman
                                            ? `1px solid ${palette.humanBorder}`
                                            : 'none',
                                        fontSize: 14,
                                        whiteSpace: 'pre-wrap',
                                    }}
                                >
                                    {/* Operator-pasted links are clickable
                                        too; the visitor's own message stays
                                        raw. */}
                                    {isHuman
                                        ? renderRichText(m.content, [])
                                        : m.content}
                                </div>
                            )}
                            {m.error && onRetry && (
                                <button
                                    type="button"
                                    onClick={() => onRetry(m.id)}
                                    style={{
                                        alignSelf: 'flex-start',
                                        marginTop: 4,
                                        padding: '4px 10px',
                                        borderRadius: 999,
                                        border: `1px solid ${palette.errorBorder}`,
                                        background: palette.retryBg,
                                        color: palette.errorText,
                                        fontSize: 12,
                                        cursor: 'pointer',
                                    }}
                                >
                                    ↻ {t('Retry')}
                                </button>
                            )}
                        </div>
                    );
                })}
            </div>
        </div>
    );
}
