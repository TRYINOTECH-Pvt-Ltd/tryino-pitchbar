import { useEffect, useRef, useState } from 'preact/hooks';
import type { WidgetApi } from '../core/api';
import { t as tr } from '../core/i18n';
import { extractPageContext } from '../core/pageContext';
import type { WidgetState } from '../core/store';
import { store } from '../core/store';
import { CtaCard } from './CtaCard';
import { LeadForm } from './LeadForm';
import { Messages } from './Messages';
import { PreChatGate } from './PreChatGate';

type Props = {
    api: WidgetApi;
    state: WidgetState;
    error: string | null;
    /**
     * Demo mode — renders a "DEMO" pill above the launcher and a notice
     * inside the chat panel header so visitors on a marketing page
     * understand they're talking to a sandbox agent, not a tracked
     * support bot. Driven by the `data-demo` script-tag attribute.
     */
    demo?: boolean;
};

/**
 * Visitor-facing label for the pending bubble while a named tool runs.
 * Server emits `tool_call` before executing the tool — mapping the name
 * here turns the generic "Thinking…" into "Checking your order…" etc.
 * Unknown (e.g. MCP-namespaced) tools get a neutral working label.
 */
function toolStageLabel(name: string): string {
    switch (name) {
        case 'lookup_order':
            return tr('Checking your order…');
        case 'open_ticket':
            return tr('Creating your ticket…');
        case 'send_kb_article':
            return tr('Finding the right article…');
        case 'escalate_to_human':
            return tr('Connecting you with a human…');
        default:
            return tr('Working on it…');
    }
}

const baseStyle = {
    fontFamily: 'system-ui, -apple-system, "Segoe UI", Roboto, sans-serif',
    color: '#0f172a',
    boxSizing: 'border-box' as const,
};

type Theme = {
    primary?: string;
    accent?: string;
    radius?: number | string;
    position?: 'bottom-left' | 'bottom-center' | 'bottom-right' | string;
    launcher_label?: string;
    launcher_icon_url?: string;
    default_open?: boolean;
    launcher_size?: 'sm' | 'md' | 'lg' | string;
    color_scheme?: 'light' | 'dark' | 'auto' | string;
    header_logo_url?: string;
    bar_width?: number | string;
    starter_prompts_position?: 'top' | 'bottom' | string;
};

/**
 * Pull theme values out of agent.theme with sensible fallbacks. The
 * fields are optional and customer-editable from /app/agents/X/customize,
 * so the widget should never assume any of them are present.
 */
function readTheme(raw: Record<string, unknown> | null): Required<Theme> {
    const t = (raw ?? {}) as Theme;

    return {
        primary: typeof t.primary === 'string' ? t.primary : '#111827',
        accent: typeof t.accent === 'string' ? t.accent : '#10b981',
        radius:
            typeof t.radius === 'number'
                ? t.radius
                : typeof t.radius === 'string'
                  ? Number.parseInt(t.radius, 10) || 16
                  : 16,
        position:
            t.position === 'bottom-left' ||
            t.position === 'bottom-center' ||
            t.position === 'bottom-right'
                ? t.position
                : 'bottom-center',
        launcher_label:
            typeof t.launcher_label === 'string' && t.launcher_label.length > 0
                ? t.launcher_label
                : 'Ask anything',
        launcher_icon_url:
            typeof t.launcher_icon_url === 'string' &&
            t.launcher_icon_url.length > 0
                ? t.launcher_icon_url
                : '',
        default_open: t.default_open === false ? false : true,
        launcher_size:
            t.launcher_size === 'sm' ||
            t.launcher_size === 'md' ||
            t.launcher_size === 'lg'
                ? t.launcher_size
                : 'md',
        color_scheme:
            t.color_scheme === 'light' ||
            t.color_scheme === 'dark' ||
            t.color_scheme === 'auto'
                ? t.color_scheme
                : 'light',
        header_logo_url:
            typeof t.header_logo_url === 'string' &&
            t.header_logo_url.length > 0
                ? t.header_logo_url
                : '',
        bar_width: (() => {
            const raw =
                typeof t.bar_width === 'number'
                    ? t.bar_width
                    : typeof t.bar_width === 'string'
                      ? Number.parseInt(t.bar_width, 10)
                      : NaN;

            if (!Number.isFinite(raw)) {
                return 560;
            }

            return Math.min(800, Math.max(320, raw));
        })(),
        starter_prompts_position:
            t.starter_prompts_position === 'bottom' ? 'bottom' : 'top',
    };
}

/**
 * Resolve the active color scheme. When `auto`, follow the visitor's
 * OS preference via `prefers-color-scheme`; falls back to light when
 * unsupported.
 */
function resolveScheme(
    mode: Required<Theme>['color_scheme'],
): 'light' | 'dark' {
    if (mode === 'dark') {
        return 'dark';
    }

    if (mode === 'light') {
        return 'light';
    }

    try {
        if (
            typeof window !== 'undefined' &&
            typeof window.matchMedia === 'function' &&
            window.matchMedia('(prefers-color-scheme: dark)').matches
        ) {
            return 'dark';
        }
    } catch {
        // matchMedia may throw in some sandboxed iframes — fall through
    }

    return 'light';
}

/**
 * Pixel sizes for the launcher orb / pill height per `launcher_size`
 * theme value. Mirrors the customize-page preview.
 */
function launcherDims(size: Required<Theme>['launcher_size']): {
    orb: number;
    pillPadding: string;
} {
    // Vertical padding must be ≥ `(orb_diameter - input_line_height) / 2`
    // so the pill encloses the orb cleanly. Before 2026-05-22 the sm
    // variant used `6px 14px` which clipped the right edge of the
    // trailing action buttons and made the white background look
    // misaligned next to the rounded corner.
    if (size === 'sm') {
        return { orb: 30, pillPadding: '8px 16px' };
    }

    if (size === 'lg') {
        return { orb: 48, pillPadding: '12px 22px' };
    }

    return { orb: 36, pillPadding: '10px 18px' };
}

/**
 * Apply the i18n translator to the launcher label. Custom labels set
 * by the admin (e.g. "Browse our shop") stay verbatim — only the
 * built-in default 'Ask anything' is run through `t()` so visitors on
 * non-English locales see a translated default.
 */
function localizedLauncherLabel(label: string): string {
    return label === 'Ask anything' ? tr('Ask anything') : label;
}

/**
 * Pin the outer container to the configured corner / centered bottom.
 * The pill + answer-panel stack inside, with the pill at the bottom.
 * Uses safe-area-inset-bottom so the pill clears the iPhone home
 * indicator.
 */
function positionStyles(position: Required<Theme>['position']): {
    bottom: string;
    left?: string | number;
    right?: string | number;
    transform?: string;
    alignItems: 'flex-start' | 'center' | 'flex-end';
} {
    const bottom = 'calc(env(safe-area-inset-bottom, 0px) + 16px)';

    if (position === 'bottom-left') {
        return { bottom, left: 16, alignItems: 'flex-start' };
    }

    if (position === 'bottom-right') {
        return { bottom, right: 16, alignItems: 'flex-end' };
    }

    return {
        bottom,
        left: '50%',
        transform: 'translateX(-50%)',
        alignItems: 'center',
    };
}

/**
 * Post-conversation satisfaction prompt. Renders inline above the
 * composer once the operator has released the conversation. Two-step:
 * thumbs choice → optional comment. Locked once submitted (server
 * also locks the rating after the first POST).
 */
function SatisfactionPrompt({
    submitted,
    onSubmit,
    onDismiss,
}: {
    submitted: boolean;
    onSubmit: (rating: 'positive' | 'negative', comment?: string) => void;
    onDismiss: () => void;
}) {
    const [picked, setPicked] = useState<'positive' | 'negative' | null>(null);
    const [comment, setComment] = useState('');

    if (submitted) {
        return (
            <div
                style={{
                    margin: '6px 12px 0',
                    padding: '8px 12px',
                    borderRadius: 12,
                    background: '#ecfdf5',
                    color: '#065f46',
                    fontSize: 12,
                    display: 'flex',
                    alignItems: 'center',
                    justifyContent: 'space-between',
                }}
            >
                <span>{tr('Thanks for the feedback!')}</span>
                <button
                    type="button"
                    onClick={onDismiss}
                    style={{
                        appearance: 'none',
                        border: 'none',
                        background: 'transparent',
                        color: '#065f46',
                        cursor: 'pointer',
                        fontSize: 11,
                        textDecoration: 'underline',
                    }}
                >
                    {tr('Close')}
                </button>
            </div>
        );
    }

    return (
        <div
            style={{
                margin: '6px 12px 0',
                padding: '10px 12px',
                borderRadius: 12,
                border: '1px solid #cbd5e1',
                background: '#f8fafc',
            }}
        >
            <div
                style={{
                    fontSize: 12,
                    fontWeight: 600,
                    color: '#1e293b',
                    marginBottom: 8,
                }}
            >
                {tr('Was this helpful?')}
            </div>
            <div
                style={{
                    display: 'flex',
                    gap: 8,
                    marginBottom: picked ? 8 : 0,
                }}
            >
                <button
                    type="button"
                    onClick={() => setPicked('positive')}
                    style={{
                        flex: 1,
                        padding: '6px 10px',
                        borderRadius: 8,
                        border: `1px solid ${picked === 'positive' ? '#10b981' : '#cbd5e1'}`,
                        background:
                            picked === 'positive' ? '#ecfdf5' : '#ffffff',
                        cursor: 'pointer',
                        fontSize: 12,
                        fontWeight: 600,
                        color: '#065f46',
                    }}
                >
                    👍 {tr('Yes')}
                </button>
                <button
                    type="button"
                    onClick={() => setPicked('negative')}
                    style={{
                        flex: 1,
                        padding: '6px 10px',
                        borderRadius: 8,
                        border: `1px solid ${picked === 'negative' ? '#ef4444' : '#cbd5e1'}`,
                        background:
                            picked === 'negative' ? '#fef2f2' : '#ffffff',
                        cursor: 'pointer',
                        fontSize: 12,
                        fontWeight: 600,
                        color: '#991b1b',
                    }}
                >
                    👎 {tr('No')}
                </button>
                <button
                    type="button"
                    onClick={onDismiss}
                    style={{
                        appearance: 'none',
                        border: 'none',
                        background: 'transparent',
                        color: '#64748b',
                        cursor: 'pointer',
                        fontSize: 11,
                        padding: '0 4px',
                    }}
                    title={tr('Skip')}
                >
                    ×
                </button>
            </div>
            {picked && (
                <div style={{ display: 'flex', gap: 6 }}>
                    <input
                        type="text"
                        value={comment}
                        onInput={(e) =>
                            setComment((e.target as HTMLInputElement).value)
                        }
                        placeholder={tr('Anything to add? (optional)')}
                        style={{
                            flex: 1,
                            border: '1px solid #cbd5e1',
                            borderRadius: 8,
                            padding: '6px 8px',
                            fontSize: 12,
                            outline: 'none',
                        }}
                    />
                    <button
                        type="button"
                        onClick={() => onSubmit(picked, comment || undefined)}
                        style={{
                            padding: '6px 12px',
                            borderRadius: 8,
                            background: '#0f172a',
                            color: '#ffffff',
                            border: 'none',
                            cursor: 'pointer',
                            fontSize: 12,
                            fontWeight: 600,
                        }}
                    >
                        {tr('Send')}
                    </button>
                </div>
            )}
        </div>
    );
}

/**
 * Three-dot "typing…" indicator. Rendered above the input bar when
 * the operator's `operator_typing_until` window is fresh. Inline
 * styles instead of CSS keyframes because the widget bundle ships
 * without a stylesheet.
 */
function TypingIndicator({ label }: { label: string }) {
    return (
        <div
            style={{
                margin: '6px 12px 0',
                padding: '6px 12px',
                borderRadius: 12,
                background: '#f1f5f9',
                color: '#475569',
                fontSize: 11,
                display: 'flex',
                alignItems: 'center',
                gap: 8,
            }}
        >
            <span
                style={{
                    display: 'inline-flex',
                    gap: 3,
                }}
            >
                {[0, 150, 300].map((delay) => (
                    <span
                        key={delay}
                        style={{
                            width: 5,
                            height: 5,
                            borderRadius: '50%',
                            background: '#94a3b8',
                            animation:
                                'pitchbar-pulse 1.2s ease-in-out infinite',
                            animationDelay: `${delay}ms`,
                        }}
                    />
                ))}
            </span>
            {label}
        </div>
    );
}

/**
 * Slate-blue banner used for the "we're offline" treatment in both
 * Phase 2 status branches (no_operators / after_hours). Buyers wanted
 * the offline copy to look distinct from the amber "still waiting"
 * banner so visitors don't mistake it for a temporary delay.
 */
function OfflineBanner({ title, body }: { title: string; body: string }) {
    return (
        <div
            style={{
                margin: '6px 12px 0',
                padding: '10px 12px',
                borderRadius: 12,
                border: '1px solid #cbd5e1',
                background: '#f8fafc',
                color: '#1e293b',
                fontSize: 12,
            }}
        >
            <div style={{ fontWeight: 600 }}>{title}</div>
            <div style={{ marginTop: 2, color: '#475569' }}>{body}</div>
        </div>
    );
}

/**
 * Coarse "we're back at <time>" formatter. The server hands us an
 * ISO-8601 string in the workspace's timezone; we render it as a short
 * humanized string. Browser locale handles the day/time format so a
 * French visitor sees "lundi à 09:00" instead of "Monday at 9am".
 */
function formatNextOpen(iso: string): string {
    try {
        const d = new Date(iso);
        const now = new Date();
        const sameDay = d.toDateString() === now.toDateString();
        const opts: Intl.DateTimeFormatOptions = sameDay
            ? { hour: 'numeric', minute: '2-digit' }
            : {
                  weekday: 'long',
                  hour: 'numeric',
                  minute: '2-digit',
              };

        return d.toLocaleString(undefined, opts);
    } catch {
        return tr('soon');
    }
}

/**
 * Status banner shown above the input bar while a live-handoff is
 * pending or active. Five states (Phase 2):
 *   - offline_no_operators ("No one's around right now")
 *   - offline_after_hours  ("We're closed — back <time>")
 *   - waiting              ("Connecting you with someone…")
 *   - operatorJoined       ("<Name> joined the chat")
 *   - chatting             (no banner — operator bubbles speak)
 */
function HandoffBanner({
    humanRequestedAt,
    operator,
    isHumanHandling,
    showJoinedNotice,
    handoffStatus,
    nextOpenAt,
}: {
    humanRequestedAt: string | null;
    operator: { name: string; personalized: boolean } | null;
    isHumanHandling: boolean;
    showJoinedNotice: boolean;
    handoffStatus:
        | 'queued'
        | 'offline_no_operators'
        | 'offline_after_hours'
        | null;
    nextOpenAt: string | null;
}) {
    const waitingForOperator = humanRequestedAt !== null && !isHumanHandling;

    // Phase 2: smart routing. The server tells us whether to be
    // optimistic ("Connecting you…") or graceful ("We're offline").
    if (handoffStatus === 'offline_no_operators') {
        return (
            <OfflineBanner
                title={tr("No one's around right now")}
                body={tr(
                    "Drop your email and we'll reach out as soon as someone's free.",
                )}
            />
        );
    }

    if (handoffStatus === 'offline_after_hours') {
        const next = nextOpenAt
            ? tr("We're back :time.", { time: formatNextOpen(nextOpenAt) })
            : tr("We're closed right now.");

        return (
            <OfflineBanner
                title={tr("We're closed right now")}
                body={tr(
                    ":next Drop your email and we'll follow up first thing.",
                    { next },
                )}
            />
        );
    }

    if (waitingForOperator) {
        return (
            <div
                style={{
                    margin: '6px 12px 0',
                    padding: '8px 12px',
                    borderRadius: 12,
                    border: '1px dashed #fcd34d',
                    background: '#fffbeb',
                    color: '#92400e',
                    fontSize: 12,
                    display: 'flex',
                    alignItems: 'center',
                    gap: 8,
                }}
            >
                <span
                    style={{
                        width: 8,
                        height: 8,
                        borderRadius: '50%',
                        background: '#f59e0b',
                        animation: 'pitchbar-pulse 1.5s ease-in-out infinite',
                    }}
                />
                <span>
                    {tr(
                        "Connecting you with someone… We've pinged the team and they'll join shortly.",
                    )}
                </span>
            </div>
        );
    }

    if (operator !== null && isHumanHandling && showJoinedNotice) {
        const greeting = operator.personalized
            ? tr(':name joined the chat', { name: operator.name })
            : tr('An agent joined the chat');

        return (
            <div
                style={{
                    margin: '6px 12px 0',
                    padding: '6px 12px',
                    borderRadius: 12,
                    border: '1px solid #a7f3d0',
                    background: '#ecfdf5',
                    color: '#065f46',
                    fontSize: 11,
                    fontWeight: 600,
                    display: 'flex',
                    alignItems: 'center',
                    gap: 6,
                }}
            >
                <span
                    style={{
                        width: 6,
                        height: 6,
                        borderRadius: '50%',
                        background: '#10b981',
                    }}
                />
                {greeting}
            </div>
        );
    }

    return null;
}

function Orb({ size = 36, iconUrl }: { size?: number; iconUrl?: string }) {
    if (iconUrl) {
        return (
            <img
                src={iconUrl}
                alt=""
                aria-hidden="true"
                style={{
                    width: size,
                    height: size,
                    borderRadius: '50%',
                    objectFit: 'cover',
                    flexShrink: 0,
                    background: '#ffffff',
                    boxShadow:
                        'inset -2px -2px 6px rgba(255,255,255,0.18), inset 2px 2px 4px rgba(0,0,0,0.15)',
                }}
            />
        );
    }

    return (
        <div
            aria-hidden="true"
            style={{
                width: size,
                height: size,
                borderRadius: '50%',
                background:
                    'radial-gradient(circle at 30% 30%, #a78bfa 0%, #6366f1 35%, #1e1b4b 90%)',
                flexShrink: 0,
                boxShadow:
                    'inset -2px -2px 6px rgba(255,255,255,0.18), inset 2px 2px 4px rgba(0,0,0,0.15)',
            }}
        />
    );
}

function Spinner({ accent }: { accent: string }) {
    return (
        <div
            aria-hidden="true"
            style={{
                width: 18,
                height: 18,
                borderRadius: '50%',
                border: '2px solid #e5e7eb',
                borderTopColor: accent,
                animation: 'pitchbar-spin 0.8s linear infinite',
            }}
        />
    );
}

function ArrowUpIcon() {
    return (
        <svg
            width="14"
            height="14"
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            stroke-width="2.5"
            stroke-linecap="round"
            stroke-linejoin="round"
        >
            <line x1="12" y1="19" x2="12" y2="5" />
            <polyline points="5 12 12 5 19 12" />
        </svg>
    );
}

function MicIcon() {
    return (
        <svg
            width="16"
            height="16"
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            stroke-width="2"
            stroke-linecap="round"
            stroke-linejoin="round"
            aria-hidden="true"
        >
            <path d="M12 2a3 3 0 0 0-3 3v7a3 3 0 0 0 6 0V5a3 3 0 0 0-3-3z" />
            <path d="M19 10v2a7 7 0 0 1-14 0v-2" />
            <line x1="12" y1="19" x2="12" y2="23" />
            <line x1="8" y1="23" x2="16" y2="23" />
        </svg>
    );
}

function DotsIcon() {
    return (
        <svg
            width="18"
            height="18"
            viewBox="0 0 24 24"
            fill="currentColor"
            aria-hidden="true"
        >
            <circle cx="5" cy="12" r="1.6" />
            <circle cx="12" cy="12" r="1.6" />
            <circle cx="19" cy="12" r="1.6" />
        </svg>
    );
}

function PoweredMark({ logoUrl }: { logoUrl?: string | null }) {
    if (logoUrl) {
        return (
            <img
                src={logoUrl}
                alt=""
                style={{
                    display: 'block',
                    width: 'auto',
                    height: 18,
                    maxWidth: 56,
                    objectFit: 'contain',
                    flexShrink: 0,
                }}
            />
        );
    }

    return (
        <span
            aria-hidden="true"
            style={{
                display: 'inline-flex',
                alignItems: 'center',
                justifyContent: 'center',
                width: 18,
                height: 18,
                borderRadius: 4,
                background: '#0f172a',
                color: 'white',
                fontSize: 11,
                fontWeight: 700,
                flexShrink: 0,
            }}
        >
            P
        </span>
    );
}

function brandingDisplayParts(mode?: 'logo_text' | 'logo_only' | 'text_only') {
    if (mode === 'logo_only') {
        return { showLogo: true, showText: false };
    }

    if (mode === 'text_only') {
        return { showLogo: false, showText: true };
    }

    return { showLogo: true, showText: true };
}

/**
 * Mint a fresh `(userId, assistantId)` pair for one turn. Hoisted out
 * of the component so the impure Date.now() call doesn't trip
 * react-hooks/purity inside the render body.
 */
function nextTurnIds(): { userId: string; assistantId: string } {
    const stamp = Date.now();

    return {
        userId: `m-${stamp}-u`,
        assistantId: `m-${stamp}-a`,
    };
}

/**
 * Browser-native voice input. Web Speech API is unprefixed in modern
 * Chromium and Safari; older Safari + iOS still need webkit. Returns
 * undefined on engines that don't expose either, so the mic button
 * can fall back to disabled.
 */
type SpeechRecognitionCtor = new () => {
    lang: string;
    continuous: boolean;
    interimResults: boolean;
    onresult: ((e: SpeechRecognitionResultEvent) => void) | null;
    onerror: ((e: Event) => void) | null;
    onend: (() => void) | null;
    start: () => void;
    stop: () => void;
};

type SpeechRecognitionResultEvent = {
    results: ArrayLike<{
        0: { transcript: string };
        isFinal: boolean;
        length: number;
    }>;
    resultIndex: number;
};

function getSpeechRecognition(): SpeechRecognitionCtor | undefined {
    const w = window as unknown as {
        SpeechRecognition?: SpeechRecognitionCtor;
        webkitSpeechRecognition?: SpeechRecognitionCtor;
    };

    return w.SpeechRecognition ?? w.webkitSpeechRecognition;
}

const menuItemBase = {
    display: 'flex',
    alignItems: 'center',
    gap: 10,
    width: '100%',
    padding: '8px 12px',
    fontSize: 13,
    color: '#0f172a',
    background: 'none',
    border: 'none',
    cursor: 'pointer',
    textAlign: 'start' as const,
    textDecoration: 'none',
    fontFamily: 'inherit',
};

/**
 * Soft synthetic chime played on the first unread message of a session.
 * Web Audio API beep instead of bundling an mp3/ogg so we stay inside
 * the ≤50KB gz widget budget. Honours `prefers-reduced-motion` (treats
 * it as a proxy for "don't surprise me with sounds") and silently
 * no-ops on engines without AudioContext (older Safari, headless tests).
 */
function playChime(): void {
    try {
        const reduceMotion = window.matchMedia?.(
            '(prefers-reduced-motion: reduce)',
        ).matches;

        if (reduceMotion) {
            return;
        }

        const Ctor =
            (window as unknown as { AudioContext?: typeof AudioContext })
                .AudioContext ??
            (
                window as unknown as {
                    webkitAudioContext?: typeof AudioContext;
                }
            ).webkitAudioContext;

        if (!Ctor) {
            return;
        }

        const ctx = new Ctor();
        const now = ctx.currentTime;
        const osc = ctx.createOscillator();
        const gain = ctx.createGain();

        osc.type = 'sine';
        // Two soft tones (~C6 → E6) so it reads as "ding" not "beep".
        osc.frequency.setValueAtTime(880, now);
        osc.frequency.exponentialRampToValueAtTime(1318, now + 0.12);

        gain.gain.setValueAtTime(0.0001, now);
        gain.gain.exponentialRampToValueAtTime(0.18, now + 0.02);
        gain.gain.exponentialRampToValueAtTime(0.0001, now + 0.42);

        osc.connect(gain).connect(ctx.destination);
        osc.start(now);
        osc.stop(now + 0.45);
        // Release the context so the page doesn't keep an audio
        // process alive after the chime ends.
        window.setTimeout(() => {
            try {
                ctx.close();
            } catch {
                // best-effort cleanup
            }
        }, 600);
    } catch {
        // Audio is non-essential — never surface failures to the visitor.
    }
}

export function Bar({ api, state, error, demo = false }: Props) {
    const [open, setOpen] = useState(state.open);
    const [menuOpen, setMenuOpen] = useState(false);
    const [input, setInput] = useState('');
    const [recording, setRecording] = useState(false);
    const [voiceSupported] = useState(
        () => getSpeechRecognition() !== undefined,
    );
    // Captured the last voice-input error so the mic button can surface
    // a one-line hint to the visitor. Buyer JFOC reported the mic
    // failing silently in Brave because Brave's "Block fingerprinting"
    // shield gates Web Speech API even after the visitor grants the
    // browser-level microphone permission. We can't auto-detect Brave
    // reliably (UA is Chromium), but we can map the SpeechRecognition
    // error code into a precise piece of advice.
    const [voiceError, setVoiceError] = useState<
        null | 'not-allowed' | 'service-not-allowed' | 'no-speech' | 'other'
    >(null);
    const inputRef = useRef<HTMLInputElement | null>(null);
    const menuWrapRef = useRef<HTMLDivElement | null>(null);
    const lastTypingFireRef = useRef(0);
    const recognitionRef = useRef<InstanceType<SpeechRecognitionCtor> | null>(
        null,
    );
    // Plays the chime at most once per session — repeat unreads while
    // minimised still bump the badge but stay silent so we don't nag.
    const chimedThisSessionRef = useRef(false);

    // Fire the chime the first time `unreadCount` crosses 0 → 1 while
    // the bar is closed. Side effect only — we don't mutate the store
    // here, just play audio.
    useEffect(() => {
        if (
            state.unreadCount > 0 &&
            !state.open &&
            !chimedThisSessionRef.current
        ) {
            chimedThisSessionRef.current = true;
            playChime();
        }
    }, [state.unreadCount, state.open]);

    const theme = readTheme(
        state.agent?.theme as Record<string, unknown> | null,
    );
    const accent = theme.primary;
    const launcherLabel = localizedLauncherLabel(theme.launcher_label);
    const radius = Math.max(12, Math.min(28, Number(theme.radius)));
    const pos = positionStyles(theme.position);
    const scheme = resolveScheme(theme.color_scheme);
    const sizeDims = launcherDims(theme.launcher_size);
    // Mobile-safe responsive bar width. Operator picks a max (320–800);
    // on small screens we cap at viewport minus 32px gutter so the bar
    // never overflows or gets clipped at the right/left edge — buyer
    // 2026-05-22 reported the bar cropping in bottom-right/left on
    // mobile.
    const barWidthCss = `min(${Number(theme.bar_width) || 560}px, calc(100vw - 32px))`;
    const starterPosition: 'top' | 'bottom' =
        theme.starter_prompts_position === 'bottom' ? 'bottom' : 'top';
    // Single source of truth for the scheme-aware surface colours.
    // Pre-fix every panel surface was hard-coded white — toggling
    // theme.color_scheme to "dark" didn't change anything visible.
    const palette =
        scheme === 'dark'
            ? {
                  surface: '#0f172a',
                  surfaceMuted: '#1e293b',
                  text: '#e2e8f0',
                  textMuted: '#94a3b8',
                  border: '#1f2937',
              }
            : {
                  surface: '#ffffff',
                  surfaceMuted: '#f8fafc',
                  text: '#0f172a',
                  textMuted: '#64748b',
                  border: '#f1f5f9',
              };

    const isStreaming = state.messages.some((m) => m.pending === true);
    const hasMessages = state.messages.length > 0;
    const showSend = input.trim().length > 0;
    const branding = state.init?.branding;
    // Persona display-name override: when the admin set a Persona name
    // ("Aria", "Angela", etc.) in agents/{id}/customize, that's the
    // identity the visitor should see in the panel header — not the
    // generic "AI assistant" placeholder. Falls back to the localized
    // default for legacy agents with no persona configured.
    const personaName = (() => {
        const persona = state.init?.agent.persona;

        if (persona && typeof persona === 'object' && 'name' in persona) {
            const name = (persona as { name?: unknown }).name;

            if (typeof name === 'string' && name.trim() !== '') {
                return name.trim();
            }
        }

        return null;
    })();
    // Client report 2026-05-23: buyer set Agent → Basics → Name = "Kaia"
    // but the widget header rendered "AI assistant" because they never
    // touched the separate Settings → Widget → Persona → Display name
    // field. The two-fields-for-the-same-thing is confusing; until the
    // form is unified, fall back to the agent's primary name so the
    // visitor sees what the buyer configured first.
    const agentName = (() => {
        const name = state.init?.agent.name;

        return typeof name === 'string' && name.trim() !== ''
            ? name.trim()
            : null;
    })();
    const panelTitle = state.isHumanHandling
        ? tr('Live support')
        : (personaName ?? agentName ?? tr('AI assistant'));

    // Single source of truth for "visitor wants a human". Used by the
    // always-visible header button + the LLM-emitted escalation_button
    // block + the in-message lead form's "Talk to a human" path. The
    // server always queues the request now (it sends a Reverb ping +
    // a DB notification + email to every available operator) and tells
    // us how long to wait via `wait_timeout_seconds`. We keep the
    // banner in "Connecting…" until that deadline; after that the
    // widget locally flips to `offline_no_operators` so the visitor
    // can leave an email instead of staring at a spinner.
    const requestHumanHandoff = () => {
        const jwt = state.init?.jwt;

        if (!jwt) {
            return;
        }

        if (state.isHumanHandling) {
            return;
        }

        const now = Date.now();
        store.set({
            handoffStatus: 'queued',
            humanRequestedAt: new Date(now).toISOString(),
            // Optimistic 120s deadline; replaced by the server's value
            // as soon as the response lands.
            handoffWaitTimeoutAt: now + 120 * 1000,
        });
        api.requestHuman(jwt)
            .then((out) => {
                // After-hours short-circuit: server already knows we're
                // closed, no point spinning a timer.
                if (out.status === 'offline_after_hours') {
                    store.set({
                        handoffStatus: 'offline_after_hours',
                        nextOpenAt: out.next_open_at,
                        humanRequestedAt: null,
                        handoffWaitTimeoutAt: null,
                    });

                    return;
                }

                const waitSeconds = out.wait_timeout_seconds ?? 120;
                store.set({
                    handoffStatus: 'queued',
                    nextOpenAt: out.next_open_at,
                    humanRequestedAt:
                        out.human_requested_at ?? new Date(now).toISOString(),
                    handoffWaitTimeoutAt: Date.now() + waitSeconds * 1000,
                });
            })
            .catch(() => {
                // Stay optimistic on network failure — the 120s timer
                // we already set above gives the visitor a fair shot
                // at the operator hearing about it.
            });
        // No lead-form pop here. The lead capture happens only AFTER
        // the wait window elapses (HandoffBanner's offline fallback
        // surfaces its own email field). Pre-fix this opened the form
        // simultaneously which buried the "connecting…" banner.
    };

    // Drive the wait → offline transition. After the deadline lapses
    // with no operator claim, flip `handoffStatus` locally so the
    // banner shows the "no one's around, leave email" copy.
    useEffect(() => {
        if (
            state.handoffWaitTimeoutAt === null ||
            state.handoffStatus !== 'queued' ||
            state.isHumanHandling
        ) {
            return;
        }

        const remaining = state.handoffWaitTimeoutAt - Date.now();

        if (remaining <= 0) {
            store.set({
                handoffStatus: 'offline_no_operators',
                handoffWaitTimeoutAt: null,
                humanRequestedAt: null,
                leadFormOpen: true,
            });

            return;
        }

        const id = window.setTimeout(() => {
            // Recheck state at fire time — a claim may have landed
            // mid-wait, in which case the effect's cleanup already
            // re-ran and this branch never executes.
            if (
                store.get().handoffStatus === 'queued' &&
                !store.get().isHumanHandling
            ) {
                store.set({
                    handoffStatus: 'offline_no_operators',
                    handoffWaitTimeoutAt: null,
                    humanRequestedAt: null,
                    leadFormOpen: true,
                });
            }
        }, remaining);

        return () => window.clearTimeout(id);
    }, [
        state.handoffWaitTimeoutAt,
        state.handoffStatus,
        state.isHumanHandling,
    ]);

    // Operator claimed → cancel the timer, clear the deadline.
    useEffect(() => {
        if (state.isHumanHandling && state.handoffWaitTimeoutAt !== null) {
            store.set({ handoffWaitTimeoutAt: null });
        }
    }, [state.isHumanHandling, state.handoffWaitTimeoutAt]);

    // Auto-dismiss the "<Name> joined the chat" notice. It's a one-time
    // event, not a status — the header's "Live agent" pill carries the
    // ongoing state. Null the deadline when it lapses so the banner
    // stops rendering instead of staying pinned above the input.
    useEffect(() => {
        if (state.operatorJoinedNoticeUntil === null) {
            return;
        }

        const remaining = state.operatorJoinedNoticeUntil - Date.now();

        if (remaining <= 0) {
            store.set({ operatorJoinedNoticeUntil: null });

            return;
        }

        const id = window.setTimeout(() => {
            store.set({ operatorJoinedNoticeUntil: null });
        }, remaining);

        return () => window.clearTimeout(id);
    }, [state.operatorJoinedNoticeUntil]);

    // Close the menu on outside click. The widget renders inside an
    // open shadow root, so the document-level event has its `target`
    // retargeted to the shadow host — `Element.contains(e.target)`
    // returns false for clicks on actual buttons inside the menu, and
    // the dropdown would close before the click registered. Walking
    // `composedPath()` crosses the shadow boundary and gives us the
    // real ancestor chain, including the menu's children.
    useEffect(() => {
        if (!menuOpen) {
            return;
        }

        const onPointer = (e: MouseEvent | TouchEvent) => {
            const wrap = menuWrapRef.current;

            if (!wrap) {
                return;
            }

            const path =
                typeof e.composedPath === 'function'
                    ? (e.composedPath() as EventTarget[])
                    : [];

            if (!path.includes(wrap)) {
                setMenuOpen(false);
            }
        };

        document.addEventListener('mousedown', onPointer);
        document.addEventListener('touchstart', onPointer);

        return () => {
            document.removeEventListener('mousedown', onPointer);
            document.removeEventListener('touchstart', onPointer);
        };
    }, [menuOpen]);

    // Auto-dismiss the "Thanks — we'll get back to you soon" lead
    // confirmation after 5s. The × button covers visitors who want it
    // gone sooner; either path sets the same dismissed flag.
    useEffect(() => {
        if (!state.leadCaptured || state.leadBannerDismissed) {
            return;
        }

        const id = window.setTimeout(() => {
            store.set({ leadBannerDismissed: true });
        }, 5_000);

        return () => window.clearTimeout(id);
    }, [state.leadCaptured, state.leadBannerDismissed]);

    /**
     * Stream the assistant's reply into an existing bubble, with up
     * to three transparent retry attempts (0ms, 800ms, 1800ms backoff
     * between attempts). The visitor sees a single "thinking…" state
     * across all attempts; only when all three fail do we mark the
     * bubble as errored and show the manual Retry pill.
     *
     * Why retry: Cloudflare Workers AI free tier hits transient rate
     * limits and connection blips fairly often, and most resolve in
     * 1–2 seconds. Retrying inline beats showing the visitor an error
     * for something that would have worked on its own.
     */
    const streamReply = async (message: string, assistantId: string) => {
        if (!state.init) {
            return;
        }

        let pageContext: unknown;

        try {
            pageContext = extractPageContext();
        } catch {
            pageContext = undefined;
        }

        const attemptDelays = [0, 800, 1800];

        // Terminal server-error codes: retrying never helps. Surface
        // the server-provided message immediately so the visitor sees
        // an actionable hint ("Monthly limit reached", "Origin not
        // allowed for this agent", "Please refresh") instead of a
        // generic "Sorry" after three pointless retries.
        const TERMINAL_CODES = new Set<string>([
            'agent_not_found',
            'conversation_not_found',
            'invalid_token',
            'missing_token',
            'message_quota_exceeded',
            'origin_forbidden',
            'http_failed',
            'stream_failed',
        ]);

        let lastErrorCode: string | null = null;
        let lastErrorMessage: string | null = null;

        for (let attempt = 0; attempt < attemptDelays.length; attempt++) {
            if (attempt > 0) {
                await new Promise<void>((resolve) =>
                    window.setTimeout(resolve, attemptDelays[attempt]),
                );
                // Wipe whatever partial content the prior attempt
                // produced. Same bubble id, fresh canvas.
                store.resetMessageForRetry(assistantId);
            }

            let attemptDone = false;
            let attemptError = false;
            let attemptTerminal = false;

            try {
                await api.streamMessage(
                    state.init.jwt,
                    message,
                    {
                        onToken: (tok) => store.appendToken(assistantId, tok),
                        onBlock: (block) => {
                            store.appendBlock(assistantId, block);
                        },
                        onStage: (event) => {
                            const s = event.s;

                            if (s === 'searching' || s === 'thinking') {
                                store.set({ currentStage: s });
                            }
                        },
                        onToolCall: (call) => {
                            store.set({
                                currentStage: toolStageLabel(call.name),
                            });
                        },
                        onDone: (payload) => {
                            // Operator has taken over: the bot stays
                            // silent by design, but an empty bubble reads
                            // as broken — show a holding line instead.
                            const text =
                                payload.human_takeover && payload.text === ''
                                    ? tr('An agent will reply here shortly.')
                                    : payload.text;

                            store.finalizeMessage(
                                assistantId,
                                text,
                                payload.citations,
                            );

                            const ctas =
                                payload.ctas && payload.ctas.length > 0
                                    ? payload.ctas
                                    : payload.cta
                                      ? [payload.cta]
                                      : [];

                            if (ctas.length > 0) {
                                store.set({
                                    activeCta: ctas[0],
                                    activeCtas: ctas,
                                });
                            }

                            if (payload.lead_prompt) {
                                store.set({ leadFormOpen: true });
                            }

                            attemptDone = true;
                        },
                        onError: (err) => {
                            attemptError = true;
                            lastErrorCode = err.code ?? null;
                            lastErrorMessage = err.message ?? null;

                            if (
                                lastErrorCode &&
                                TERMINAL_CODES.has(lastErrorCode)
                            ) {
                                attemptTerminal = true;
                            }
                        },
                    },
                    pageContext,
                );
            } catch (err) {
                attemptError = true;
                lastErrorCode = lastErrorCode ?? 'network_failed';
                lastErrorMessage =
                    lastErrorMessage ??
                    (err instanceof Error ? err.message : null);
            }

            if (attemptDone) {
                return;
            }

            if (!attemptError) {
                // Stream closed cleanly with no done event. In production
                // this is almost always a proxy killing an idle SSE
                // (nginx proxy_read_timeout during a slow LLM turn), NOT
                // the visitor navigating — leaving the bubble pending
                // here is the "stuck on Thinking… forever" bug. Treat it
                // as retryable; if every attempt ends the same way the
                // visitor gets the retry pill instead of an endless wait.
                attemptError = true;
                lastErrorCode = lastErrorCode ?? 'stream_closed';
                lastErrorMessage =
                    lastErrorMessage ??
                    'The connection dropped before the reply finished. Please try again.';
            }

            if (attemptTerminal) {
                // Terminal — don't burn two more pointless retries.
                break;
            }
        }

        // Operators keep the original error (browser console → captured by
        // browser-logs / dev tools); the visitor never sees it.
        if (lastErrorCode || lastErrorMessage) {
            console.error('[pitchbar] turn failed', {
                code: lastErrorCode,
                message: lastErrorMessage,
            });
        }

        // A raw transport string ("HTTP 401") or a machine code
        // ("network_failed", "stream_closed") must never reach the visitor.
        // Show the server's message only when it's a human sentence; mask
        // everything technical behind a friendly, localized line.
        const looksTechnical = (m: string): boolean =>
            /^\s*HTTP\s*\d+\s*$/i.test(m) ||
            /^[a-z]+(_[a-z0-9]+)+$/.test(m.trim());

        // Transport / abort failures carry a raw browser exception string
        // ("BodyStreamBuffer was aborted", "Failed to fetch") that reads
        // like a sentence but must never reach the visitor. Always mask
        // these behind the friendly localized line, whatever the raw text.
        const TRANSPORT_CODES = new Set<string>([
            'stale_stream',
            'total_timeout',
            'stream_aborted',
            'stream_closed',
            'stream_ended_without_done',
            'network_failed',
            'http_failed',
        ]);
        const isTransport =
            lastErrorCode !== null && TRANSPORT_CODES.has(lastErrorCode);

        const raw = (lastErrorMessage ?? '').trim();
        const display =
            !isTransport && raw !== '' && !looksTechnical(raw)
                ? raw
                : `${tr('Something went wrong.')} ${tr('Please try again.')}`;
        store.failMessage(assistantId, display);
    };

    const sendTurn = async (message: string) => {
        if (!state.init) {
            return;
        }

        const { userId, assistantId } = nextTurnIds();
        store.addMessage({ id: userId, role: 'user', content: message });
        store.addMessage({
            id: assistantId,
            role: 'assistant',
            content: '',
            pending: true,
        });
        // Clear any leftover stage label from the prior turn so this
        // bubble starts in the unbranded "thinking…" state until the
        // server emits the first stage hint.
        store.set({ currentStage: null });

        await streamReply(message, assistantId);
    };

    const ask = (message: string) => sendTurn(message);

    /**
     * Manual retry from the failed-bubble pill. We do NOT re-add the
     * user's original message — that bubble already exists in the
     * thread, and re-adding would produce a visible duplicate (the
     * exact bug visitors reported). Drop the errored assistant
     * bubble, mount a fresh one with a new id (so the typewriter
     * captures pending=true at mount and animates), then re-stream.
     */
    const retry = (failedAssistantId: string) => {
        const idx = state.messages.findIndex((m) => m.id === failedAssistantId);

        if (idx < 1) {
            return;
        }

        const prior = state.messages[idx - 1];

        if (prior.role !== 'user') {
            return;
        }

        store.removeMessage(failedAssistantId);
        const { assistantId } = nextTurnIds();
        store.addMessage({
            id: assistantId,
            role: 'assistant',
            content: '',
            pending: true,
        });
        void streamReply(prior.content, assistantId);
    };

    const submit = (e: Event) => {
        e.preventDefault();

        // If the mic is live, end the session before sending so it
        // doesn't keep trying to fill an input that's already cleared.
        if (recording) {
            stopVoice();
        }

        const v = input.trim();

        if (!v || !state.initialized) {
            return;
        }

        setInput('');
        void sendTurn(v);
    };

    const closeBar = () => {
        setOpen(false);
        store.set({ open: false });
        setMenuOpen(false);
    };

    const startVoice = () => {
        const Ctor = getSpeechRecognition();

        if (!Ctor) {
            return;
        }

        // Snapshot whatever the visitor has already typed/dictated so
        // the new session APPENDS to it instead of replacing — this is
        // what makes re-dictate-after-stop work the way visitors expect.
        const baseText = input;
        const separator =
            baseText.length === 0 || baseText.endsWith(' ') ? '' : ' ';

        try {
            const recognition = new Ctor();
            const lang = state.init?.agent.language_default ?? 'en';
            // Web Speech accepts "en" but works better with a region;
            // crude mapping for the most common defaults.
            recognition.lang = lang.includes('-')
                ? lang
                : `${lang}-${lang === 'en' ? 'US' : lang.toUpperCase()}`;
            recognition.continuous = false;
            recognition.interimResults = true;
            recognition.onresult = (e) => {
                let transcript = '';

                for (let i = 0; i < e.results.length; i++) {
                    transcript += e.results[i][0].transcript;
                }

                setInput(baseText + separator + transcript);
                // Successful capture clears any prior hint state.
                setVoiceError(null);
            };
            recognition.onerror = (e) => {
                // The SpeechRecognitionErrorEvent isn't in lib.dom for
                // older TS targets — pull `error` defensively.
                const code = (e as unknown as { error?: string }).error;

                if (code === 'not-allowed') {
                    setVoiceError('not-allowed');
                } else if (code === 'service-not-allowed') {
                    setVoiceError('service-not-allowed');
                } else if (code === 'no-speech' || code === 'aborted') {
                    setVoiceError('no-speech');
                } else if (code) {
                    setVoiceError('other');
                }

                setRecording(false);
                recognitionRef.current = null;
            };
            recognition.onend = () => {
                setRecording(false);
                recognitionRef.current = null;
            };
            recognition.start();
            recognitionRef.current = recognition;
            setRecording(true);
            setVoiceError(null);
        } catch {
            // Synchronous throw from .start() — fired by browsers that
            // disable Web Speech entirely (Brave Shields can do this
            // before the recogniser even fires onerror). Treat as a
            // permission-class error so the visitor sees the same
            // actionable hint.
            setRecording(false);
            recognitionRef.current = null;
            setVoiceError('service-not-allowed');
        }
    };

    const stopVoice = () => {
        const r = recognitionRef.current;

        if (r) {
            try {
                r.stop();
            } catch {
                // Already stopped — nothing to do.
            }
        }

        recognitionRef.current = null;
        setRecording(false);
    };

    const toggleVoice = () => {
        if (recording) {
            stopVoice();
        } else {
            startVoice();
        }
    };

    const clearThread = () => {
        store.clearMessages();
        setMenuOpen(false);

        // Best-effort server-side: persist the clear so /init doesn't
        // re-hydrate the old thread on the next page load. Swallow
        // errors — the local wipe already happened, and the user will
        // see the old thread back on reload but can clear again.
        const jwt = state.init?.jwt;

        if (jwt) {
            api.clearConversation(jwt).catch(() => {});
        }
    };

    // Closed state — small floating orb the visitor can click to bring
    // the pill back. Only reachable after they explicitly chose Close
    // from the menu.
    if (!open) {
        const unreadBadge = state.unreadCount > 0;
        const badgeText =
            state.unreadCount > 9 ? '9+' : String(state.unreadCount);
        const reopenAria = unreadBadge
            ? tr(':n new message — open chat', {
                  n: String(state.unreadCount),
              })
            : launcherLabel;

        return (
            <div
                style={{
                    position: 'fixed',
                    ...pos,
                    zIndex: 2147483647,
                    // Smooth corner-to-corner reposition. Client 2026-05-22
                    // reported the bar "jumping" from center to a corner
                    // when the position setting changes in the customize
                    // panel — a 200ms ease makes it feel intentional.
                    transition:
                        'left 220ms ease, right 220ms ease, bottom 220ms ease, transform 220ms ease',
                    ...baseStyle,
                }}
            >
                <button
                    type="button"
                    onClick={() => {
                        setOpen(true);
                        store.set({ open: true });
                    }}
                    aria-label={reopenAria}
                    style={{
                        position: 'relative',
                        width: 56,
                        height: 56,
                        borderRadius: '50%',
                        border: 'none',
                        background: 'white',
                        padding: 6,
                        cursor: 'pointer',
                        boxShadow: '0 12px 32px rgba(0,0,0,0.18)',
                    }}
                >
                    <Orb
                        size={sizeDims.orb + 8}
                        iconUrl={theme.launcher_icon_url}
                    />
                    {unreadBadge && (
                        <span
                            aria-hidden="true"
                            style={{
                                position: 'absolute',
                                top: -4,
                                right: -4,
                                minWidth: 22,
                                height: 22,
                                padding: '0 6px',
                                borderRadius: 999,
                                background: '#dc2626',
                                color: '#ffffff',
                                fontSize: 11,
                                fontWeight: 700,
                                lineHeight: '22px',
                                textAlign: 'center',
                                boxShadow: '0 2px 6px rgba(220, 38, 38, 0.45)',
                                border: '2px solid #ffffff',
                                boxSizing: 'border-box',
                            }}
                        >
                            {badgeText}
                        </span>
                    )}
                </button>
            </div>
        );
    }

    // Pre-chat gate — when the agent has `require_lead_before_chat`
    // on and the visitor hasn't been captured for this conversation
    // yet, the chat surface is hidden behind a Name + Email form.
    // Submitting flips `leadCaptured` and the chat panel unlocks on
    // the same mount (no reload). The launcher pill is suppressed
    // because typing into a hidden chat would be a dead end.
    const gateOn =
        state.agent?.require_lead_before_chat === true && !state.leadCaptured;

    if (gateOn && state.init) {
        return (
            <div
                style={{
                    position: 'fixed',
                    ...pos,
                    zIndex: 2147483647,
                    display: 'flex',
                    flexDirection: 'column',
                    gap: 10,
                    maxWidth: '95vw',
                    transition:
                        'left 220ms ease, right 220ms ease, bottom 220ms ease, transform 220ms ease',
                    ...baseStyle,
                }}
            >
                <style>{`
                    @keyframes pitchbar-rise { from { opacity: 0; transform: translateY(6px); } to { opacity: 1; transform: translateY(0); } }
                `}</style>
                <PreChatGate
                    api={api}
                    jwt={state.init.jwt}
                    accent={accent}
                    radius={radius}
                    agentName={state.agent?.name ?? panelTitle}
                    launcherLabel={launcherLabel}
                    schema={state.agent?.lead_form_fields ?? null}
                    onClose={closeBar}
                    scheme={scheme}
                />
            </div>
        );
    }

    return (
        <div
            style={{
                position: 'fixed',
                ...pos,
                zIndex: 2147483647,
                display: 'flex',
                flexDirection: 'column',
                gap: 10,
                maxWidth: '95vw',
                transition:
                    'left 220ms ease, right 220ms ease, bottom 220ms ease, transform 220ms ease',
                ...baseStyle,
            }}
        >
            <style>{`
                @keyframes pitchbar-pulse { 0%,100%{opacity:1;} 50%{opacity:0.35;} }
                @keyframes pitchbar-cursor { 0%,50%{opacity:1;} 51%,100%{opacity:0;} }
                @keyframes pitchbar-spin { to { transform: rotate(360deg); } }
                @keyframes pitchbar-rise { from { opacity: 0; transform: translateY(6px); } to { opacity: 1; transform: translateY(0); } }
                @keyframes pitchbar-sonar { 0% { transform: scale(1); opacity: 0.6; } 100% { transform: scale(1.7); opacity: 0; } }
            `}</style>

            {/* Answer panel — only mounted once a conversation exists.
                max-height + flex column so the header stays visible even
                when the message thread + an inline LeadForm + the
                composer push the total content past the viewport. Without
                this the panel grew unbounded and the top (header / Close
                button) scrolled off-screen when the lead form opened —
                buyer report 2026-05-18 (Lithuanian customer). */}
            {hasMessages && (
                <div
                    style={{
                        // Operator-configurable max width via theme.bar_width
                        // (320–800). Falls back to 560. On small screens we
                        // cap at `calc(100vw - 32px)` so a 16px gap on each
                        // side keeps the panel from clipping at the
                        // right/left edge in bottom-left / bottom-right
                        // positions — buyer 2026-05-22 (mobile crop).
                        width: barWidthCss,
                        maxHeight: 'calc(100dvh - 80px)',
                        display: 'flex',
                        flexDirection: 'column',
                        background: palette.surface,
                        color: palette.text,
                        borderRadius: radius,
                        boxShadow: '0 24px 48px rgba(0,0,0,0.18)',
                        overflow: 'hidden',
                        animation: 'pitchbar-rise 200ms ease-out',
                    }}
                >
                    <div
                        style={{
                            padding: '10px 14px',
                            borderBottom: `1px solid ${palette.border}`,
                            display: 'flex',
                            alignItems: 'center',
                            justifyContent: 'space-between',
                            gap: 8,
                            flexShrink: 0,
                        }}
                    >
                        <div
                            style={{
                                display: 'flex',
                                alignItems: 'center',
                                gap: 8,
                                minWidth: 0,
                            }}
                        >
                            {theme.header_logo_url && (
                                <img
                                    src={theme.header_logo_url}
                                    alt=""
                                    aria-hidden="true"
                                    style={{
                                        height: 20,
                                        maxWidth: 80,
                                        objectFit: 'contain',
                                    }}
                                />
                            )}
                            <strong style={{ fontSize: 13 }}>
                                {panelTitle}
                            </strong>
                        </div>
                        <div
                            style={{
                                display: 'flex',
                                alignItems: 'center',
                                gap: 8,
                            }}
                        >
                            {demo && (
                                <span
                                    style={{
                                        display: 'inline-flex',
                                        alignItems: 'center',
                                        padding: '2px 8px',
                                        borderRadius: 999,
                                        background: '#fef3c7',
                                        border: '1px solid #fcd34d',
                                        color: '#92400e',
                                        fontSize: 10,
                                        fontWeight: 700,
                                        letterSpacing: 0.5,
                                        textTransform: 'uppercase',
                                    }}
                                    title={tr(
                                        'Sandbox agent — for product demonstration only',
                                    )}
                                >
                                    {tr('Demo')}
                                </span>
                            )}
                            {state.isHumanHandling && (
                                <span
                                    style={{
                                        display: 'inline-flex',
                                        alignItems: 'center',
                                        gap: 4,
                                        padding: '2px 8px',
                                        borderRadius: 999,
                                        background: '#ecfdf5',
                                        border: '1px solid #a7f3d0',
                                        color: '#047857',
                                        fontSize: 10,
                                        fontWeight: 600,
                                        letterSpacing: 0.3,
                                        textTransform: 'uppercase',
                                    }}
                                >
                                    <span
                                        style={{
                                            display: 'inline-block',
                                            width: 6,
                                            height: 6,
                                            borderRadius: '50%',
                                            background: '#10b981',
                                            animation:
                                                'pitchbar-pulse 1.6s ease-in-out infinite',
                                        }}
                                    />
                                    {tr('Live agent')}
                                </span>
                            )}
                            {!state.isHumanHandling &&
                                !state.humanRequestedAt && (
                                    <button
                                        type="button"
                                        onClick={requestHumanHandoff}
                                        aria-label={tr('Talk to a human')}
                                        title={tr(
                                            'Hand this conversation off to a human agent.',
                                        )}
                                        data-pitchbar-talk-to-human="true"
                                        style={{
                                            display: 'inline-flex',
                                            alignItems: 'center',
                                            gap: 4,
                                            padding: '4px 10px',
                                            borderRadius: 999,
                                            border: `1px solid ${accent}`,
                                            background: 'white',
                                            color: accent,
                                            fontSize: 11,
                                            fontWeight: 600,
                                            cursor: 'pointer',
                                            lineHeight: 1,
                                        }}
                                    >
                                        <svg
                                            width="11"
                                            height="11"
                                            viewBox="0 0 24 24"
                                            fill="none"
                                            stroke="currentColor"
                                            strokeWidth="2.4"
                                            strokeLinecap="round"
                                            strokeLinejoin="round"
                                            aria-hidden="true"
                                        >
                                            <path d="M20 11.08V8l-6-5-6 5v3.08" />
                                            <path d="M3 21h18" />
                                            <path d="M5 21V11l6.5-5.43" />
                                            <path d="M19 21V11l-6.5-5.43" />
                                            <path d="M9 9h.01" />
                                            <path d="M9 13h.01" />
                                            <path d="M9 17h.01" />
                                            <path d="M15 13h.01" />
                                            <path d="M15 17h.01" />
                                        </svg>
                                        {tr('Talk to a human')}
                                    </button>
                                )}
                            <button
                                type="button"
                                onClick={closeBar}
                                aria-label={tr('Close')}
                                style={{
                                    display: 'inline-flex',
                                    alignItems: 'center',
                                    justifyContent: 'center',
                                    width: 26,
                                    height: 26,
                                    borderRadius: '50%',
                                    border: 'none',
                                    background: 'transparent',
                                    color: '#6b7280',
                                    fontSize: 18,
                                    lineHeight: 1,
                                    cursor: 'pointer',
                                }}
                            >
                                ×
                            </button>
                        </div>
                    </div>
                    <div
                        style={{
                            flex: '1 1 200px',
                            minHeight: 200,
                            display: 'flex',
                            flexDirection: 'column',
                            // Messages owns the only scrollbar — a second
                            // one here produced the double-scroll buyers
                            // reported.
                            overflow: 'hidden',
                        }}
                    >
                        {error && (
                            <div
                                style={{
                                    padding: 12,
                                    color: '#b91c1c',
                                    fontSize: 13,
                                }}
                            >
                                {error}
                            </div>
                        )}
                        <Messages
                            messages={state.messages}
                            onRetry={retry}
                            onAsk={ask}
                            currentStage={state.currentStage}
                            scheme={scheme}
                            onLeadCapture={requestHumanHandoff}
                            onApplyCoupon={async (code: string) => {
                                const jwt = state.init?.jwt;

                                if (!jwt) {
                                    return { ok: false };
                                }

                                try {
                                    const result = await api.applyCoupon(
                                        jwt,
                                        code,
                                    );

                                    return { ok: true, ...result };
                                } catch (e) {
                                    return {
                                        ok: false,
                                        message:
                                            e instanceof Error
                                                ? e.message
                                                : 'apply_failed',
                                    };
                                }
                            }}
                            onStartCheckout={async (body) => {
                                const jwt = state.init?.jwt;

                                if (!jwt) {
                                    return {
                                        error: {
                                            code: 'missing_token',
                                            message:
                                                'Chat not initialized. Refresh the page.',
                                        },
                                    };
                                }

                                return api.createCheckout(jwt, body);
                            }}
                        />
                        {(state.humanRequestedAt ||
                            state.operator ||
                            state.handoffStatus) && (
                            <HandoffBanner
                                humanRequestedAt={state.humanRequestedAt}
                                operator={state.operator}
                                isHumanHandling={state.isHumanHandling}
                                showJoinedNotice={
                                    state.operatorJoinedNoticeUntil !== null
                                }
                                handoffStatus={state.handoffStatus}
                                nextOpenAt={state.nextOpenAt}
                            />
                        )}
                        {state.operatorTyping && state.isHumanHandling && (
                            <TypingIndicator
                                label={
                                    state.operator?.personalized &&
                                    state.operator?.name
                                        ? tr(':name is typing…', {
                                              name: state.operator.name,
                                          })
                                        : tr('Agent is typing…')
                                }
                            />
                        )}
                        {state.ratingPromptVisible && state.init && (
                            <SatisfactionPrompt
                                submitted={state.ratingSubmitted}
                                onSubmit={(rating, comment) => {
                                    api.signalSatisfaction(
                                        state.init!.jwt,
                                        rating,
                                        comment,
                                    );
                                    store.set({
                                        ratingSubmitted: true,
                                    });
                                }}
                                onDismiss={() =>
                                    store.set({ ratingPromptVisible: false })
                                }
                            />
                        )}
                        {state.init &&
                            (() => {
                                const ctas =
                                    state.activeCtas.length > 0
                                        ? state.activeCtas
                                        : state.activeCta
                                          ? [state.activeCta]
                                          : [];

                                if (ctas.length === 0) {
                                    return null;
                                }

                                return (
                                    <div
                                        style={{
                                            margin: '4px 16px 8px',
                                            display: 'flex',
                                            flexDirection: 'row',
                                            flexWrap: 'wrap',
                                            gap: 8,
                                        }}
                                    >
                                        {ctas.map((cta, i) => (
                                            <CtaCard
                                                key={`${cta.kind}-${cta.label}-${i}`}
                                                cta={cta}
                                                api={api}
                                                jwt={state.init!.jwt}
                                                accent={accent}
                                            />
                                        ))}
                                    </div>
                                );
                            })()}
                    </div>
                    {state.leadFormOpen &&
                        !state.leadCaptured &&
                        state.init && (
                            <LeadForm
                                api={api}
                                jwt={state.init.jwt}
                                accent={accent}
                                schema={state.agent?.lead_form_fields ?? null}
                                scheme={scheme}
                            />
                        )}
                    {state.leadCaptured && !state.leadBannerDismissed && (
                        <div
                            style={{
                                margin: '4px 16px 12px',
                                padding: '10px 8px 10px 10px',
                                border: '1px solid #10b981',
                                borderRadius: 8,
                                background: '#ecfdf5',
                                fontSize: 13,
                                color: '#065f46',
                                flexShrink: 0,
                                display: 'flex',
                                alignItems: 'center',
                                gap: 8,
                            }}
                        >
                            <span style={{ flex: 1 }}>
                                ✓ {tr("Thanks — we'll get back to you soon.")}
                            </span>
                            <button
                                type="button"
                                aria-label={tr('Dismiss')}
                                onClick={() =>
                                    store.set({ leadBannerDismissed: true })
                                }
                                style={{
                                    border: 'none',
                                    background: 'transparent',
                                    color: '#065f46',
                                    fontSize: 16,
                                    lineHeight: 1,
                                    cursor: 'pointer',
                                    padding: '0 4px',
                                    flexShrink: 0,
                                }}
                            >
                                ×
                            </button>
                        </div>
                    )}
                    {branding?.show && (
                        <a
                            href={branding.url}
                            target="_blank"
                            rel="noopener noreferrer"
                            style={{
                                display: 'flex',
                                alignItems: 'center',
                                justifyContent: 'center',
                                gap: 6,
                                padding: '8px 0 10px',
                                borderTop: '1px solid #f1f5f9',
                                fontSize: 11,
                                color: '#9ca3af',
                                textDecoration: 'none',
                                flexShrink: 0,
                            }}
                        >
                            {brandingDisplayParts(branding.display_mode)
                                .showLogo && branding.logo_url ? (
                                <PoweredMark logoUrl={branding.logo_url} />
                            ) : null}
                            {brandingDisplayParts(branding.display_mode)
                                .showText
                                ? branding.label
                                : null}
                        </a>
                    )}
                </div>
            )}

            {/*
             * Demo pill — sits just above the launcher pill while no
             * conversation has started, so anyone landing on the marketing
             * site immediately understands this is a sandbox agent, not a
             * live tracking widget. Once messages exist the answer panel
             * header carries its own DEMO badge so we drop this one.
             */}
            {demo && !hasMessages && (
                <div
                    style={{
                        alignSelf: 'flex-start',
                        display: 'inline-flex',
                        alignItems: 'center',
                        gap: 6,
                        marginBottom: 4,
                        padding: '4px 10px',
                        borderRadius: 999,
                        background: '#fef3c7',
                        border: '1px solid #fcd34d',
                        color: '#92400e',
                        fontSize: 11,
                        fontWeight: 700,
                        letterSpacing: 0.5,
                        textTransform: 'uppercase',
                        boxShadow: '0 6px 16px rgba(234,179,8,0.18)',
                    }}
                    title={tr(
                        'This is a sandbox agent for product demonstration. Conversations are not stored against any live workspace.',
                    )}
                >
                    <span
                        style={{
                            display: 'inline-block',
                            width: 6,
                            height: 6,
                            borderRadius: '50%',
                            background: '#f59e0b',
                        }}
                    />
                    {tr('Live demo · ask the sandbox agent anything')}
                </div>
            )}

            {/* Starter prompt chips — only when there's no thread yet.
                Rendered above the composer when `starterPosition === 'top'`
                (top-left of the bar — client preference 2026-05-22) and
                below the composer when set to `bottom` (legacy layout). */}
            {!hasMessages &&
                starterPosition === 'top' &&
                state.agent?.starter_prompts &&
                state.agent.starter_prompts.length > 0 && (
                    <div
                        style={{
                            display: 'flex',
                            flexWrap: 'wrap',
                            gap: 6,
                            justifyContent: 'flex-start',
                            maxWidth: barWidthCss,
                        }}
                    >
                        {state.agent.starter_prompts.map((prompt, i) => (
                            <button
                                key={`${prompt}-${i}`}
                                type="button"
                                onClick={() => ask(prompt)}
                                disabled={!state.initialized}
                                style={{
                                    padding: '6px 12px',
                                    borderRadius: 999,
                                    border: '1px solid rgba(255,255,255,0.4)',
                                    background: 'rgba(255,255,255,0.85)',
                                    WebkitBackdropFilter: 'blur(6px)',
                                    backdropFilter: 'blur(6px)',
                                    color: '#0f172a',
                                    fontSize: 12,
                                    cursor: state.initialized
                                        ? 'pointer'
                                        : 'not-allowed',
                                    boxShadow: '0 4px 12px rgba(0,0,0,0.08)',
                                }}
                            >
                                {prompt}
                            </button>
                        ))}
                    </div>
                )}

            {/* The pill itself — input + trailing action. */}
            <form
                onSubmit={submit}
                style={{
                    // border-box is critical here: without it the pill's
                    // `padding: 10px 18px` is added on top of the
                    // `barWidthCss` width, so on a 600px viewport at
                    // bottom-right the right edge spills 36px outside
                    // the visible area. With border-box the padding is
                    // absorbed into the width, keeping the pill inside
                    // the 100vw-32px responsive cap. Buyer report
                    // 2026-05-24 (mobile bottom-left/right crop).
                    boxSizing: 'border-box',
                    width: barWidthCss,
                    background: palette.surface,
                    color: palette.text,
                    borderRadius: 9999,
                    boxShadow: '0 14px 32px rgba(0,0,0,0.16)',
                    padding: sizeDims.pillPadding,
                    display: 'flex',
                    alignItems: 'center',
                    gap: 10,
                    position: 'relative',
                }}
            >
                <Orb size={sizeDims.orb} iconUrl={theme.launcher_icon_url} />

                <input
                    ref={inputRef}
                    type="text"
                    value={input}
                    onInput={(e) => {
                        const value = (e.target as HTMLInputElement).value;
                        setInput(value);

                        // Debounced typing indicator while a human
                        // operator is in the conversation. We don't
                        // ping when the bot is handling — there's
                        // nobody to see the indicator. 2s debounce
                        // matches the operator-side ping cadence.
                        const jwt = state.init?.jwt;

                        if (
                            !jwt ||
                            !state.isHumanHandling ||
                            value.trim() === ''
                        ) {
                            return;
                        }

                        const now = Date.now();

                        if (now - lastTypingFireRef.current < 2000) {
                            return;
                        }

                        lastTypingFireRef.current = now;
                        api.signalTyping(jwt);
                    }}
                    placeholder={launcherLabel}
                    aria-label={launcherLabel}
                    disabled={!state.initialized}
                    style={{
                        flex: 1,
                        border: 'none',
                        outline: 'none',
                        background: 'transparent',
                        // 16px keeps iOS Safari from auto-zooming on focus.
                        fontSize: 16,
                        color: '#0f172a',
                        fontFamily: 'inherit',
                        minWidth: 0,
                    }}
                />

                <div
                    ref={menuWrapRef}
                    style={{
                        position: 'relative',
                        flexShrink: 0,
                        display: 'flex',
                        alignItems: 'center',
                        gap: 4,
                    }}
                >
                    {/*
                     * Mic stays mounted across all states (idle, typing,
                     * streaming) so visitors can stop a session and start
                     * a new one — re-dictation lives or dies on this slot
                     * not disappearing the moment the input has content.
                     */}
                    <div style={{ position: 'relative', flexShrink: 0 }}>
                        {recording && (
                            <span
                                aria-hidden="true"
                                style={{
                                    position: 'absolute',
                                    // Explicit edges instead of `inset: 0` —
                                    // the shorthand isn't in older Safari/FF.
                                    top: 0,
                                    right: 0,
                                    bottom: 0,
                                    left: 0,
                                    borderRadius: '50%',
                                    border: '2px solid #dc2626',
                                    animation:
                                        'pitchbar-sonar 1.2s ease-out infinite',
                                    pointerEvents: 'none',
                                }}
                            />
                        )}
                        <button
                            type="button"
                            aria-label={
                                recording
                                    ? tr('Stop voice input')
                                    : voiceSupported
                                      ? tr('Start voice input')
                                      : tr(
                                            'Voice input not supported in this browser',
                                        )
                            }
                            title={
                                recording
                                    ? tr('Stop voice input')
                                    : voiceError === 'not-allowed'
                                      ? tr(
                                            'Microphone blocked. Click the lock icon in your address bar → allow microphone for this site, then retry.',
                                        )
                                      : voiceError === 'service-not-allowed'
                                        ? tr(
                                              'Speech service blocked by your browser. On Brave click the Shields icon → set Block Fingerprinting to Standard for this site, then retry.',
                                          )
                                        : voiceError === 'no-speech'
                                          ? tr(
                                                "Didn't catch that. Click the mic and try again.",
                                            )
                                          : voiceError === 'other'
                                            ? tr(
                                                  'Voice input failed. You can keep typing instead.',
                                              )
                                            : voiceSupported
                                              ? tr('Voice input')
                                              : tr(
                                                    'Voice input not supported in this browser',
                                                )
                            }
                            aria-pressed={recording}
                            disabled={!voiceSupported}
                            onClick={toggleVoice}
                            style={{
                                display: 'inline-flex',
                                alignItems: 'center',
                                justifyContent: 'center',
                                position: 'relative',
                                width: 34,
                                height: 34,
                                borderRadius: '50%',
                                border: 'none',
                                background: recording
                                    ? '#dc2626'
                                    : 'transparent',
                                color: recording
                                    ? 'white'
                                    : voiceSupported
                                      ? '#0f172a'
                                      : '#9ca3af',
                                cursor: voiceSupported
                                    ? 'pointer'
                                    : 'not-allowed',
                                opacity: voiceSupported ? 1 : 0.5,
                                transition:
                                    'background-color 120ms ease, color 120ms ease',
                            }}
                        >
                            <MicIcon />
                        </button>
                    </div>

                    {showSend ? (
                        <button
                            type="submit"
                            aria-label={tr('Send')}
                            disabled={!state.initialized}
                            style={{
                                display: 'inline-flex',
                                alignItems: 'center',
                                justifyContent: 'center',
                                width: 36,
                                height: 36,
                                borderRadius: '50%',
                                border: 'none',
                                background: accent,
                                color: 'white',
                                cursor: state.initialized
                                    ? 'pointer'
                                    : 'not-allowed',
                            }}
                        >
                            <ArrowUpIcon />
                        </button>
                    ) : isStreaming ? (
                        <div
                            style={{
                                display: 'inline-flex',
                                alignItems: 'center',
                                justifyContent: 'center',
                                width: 36,
                                height: 36,
                            }}
                        >
                            <Spinner accent={accent} />
                        </div>
                    ) : (
                        <button
                            type="button"
                            aria-label={tr('Menu')}
                            aria-expanded={menuOpen}
                            onClick={() => setMenuOpen((o) => !o)}
                            style={{
                                display: 'inline-flex',
                                alignItems: 'center',
                                justifyContent: 'center',
                                width: 34,
                                height: 34,
                                borderRadius: '50%',
                                border: 'none',
                                background: 'transparent',
                                color: '#9ca3af',
                                cursor: 'pointer',
                            }}
                        >
                            <DotsIcon />
                        </button>
                    )}

                    {menuOpen && (
                        <div
                            role="menu"
                            style={{
                                position: 'absolute',
                                bottom: '100%',
                                right: 0,
                                marginBottom: 8,
                                minWidth: 220,
                                padding: 4,
                                background: 'white',
                                borderRadius: 12,
                                border: '1px solid #e5e7eb',
                                boxShadow: '0 16px 40px rgba(0,0,0,0.18)',
                                animation: 'pitchbar-rise 140ms ease-out',
                            }}
                        >
                            {branding?.show && (
                                <a
                                    href={branding.url}
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    role="menuitem"
                                    style={{
                                        ...menuItemBase,
                                        borderBottom: '1px solid #f1f5f9',
                                        paddingBottom: 10,
                                        marginBottom: 2,
                                    }}
                                >
                                    {brandingDisplayParts(branding.display_mode)
                                        .showLogo ? (
                                        <PoweredMark
                                            logoUrl={branding.logo_url}
                                        />
                                    ) : null}
                                    {brandingDisplayParts(branding.display_mode)
                                        .showText ? (
                                        <span>
                                            {tr('Powered by :name', {
                                                name:
                                                    branding.label.replace(
                                                        /^Powered by\s*/i,
                                                        '',
                                                    ) || 'Pitchbar',
                                            })}
                                        </span>
                                    ) : null}
                                </a>
                            )}
                            <button
                                type="button"
                                role="menuitem"
                                onClick={closeBar}
                                style={menuItemBase}
                            >
                                <span aria-hidden="true">×</span>
                                <span>{tr('Close')}</span>
                            </button>
                            <button
                                type="button"
                                role="menuitem"
                                disabled={!hasMessages}
                                onClick={clearThread}
                                style={{
                                    ...menuItemBase,
                                    color: hasMessages ? '#0f172a' : '#9ca3af',
                                    cursor: hasMessages
                                        ? 'pointer'
                                        : 'not-allowed',
                                }}
                            >
                                <span aria-hidden="true">⌫</span>
                                <span>{tr('Clear conversation')}</span>
                            </button>
                        </div>
                    )}
                </div>
            </form>

            {/* Legacy below-composer starter chip placement. Operator
                opts in via theme.starter_prompts_position = 'bottom'. */}
            {!hasMessages &&
                starterPosition === 'bottom' &&
                state.agent?.starter_prompts &&
                state.agent.starter_prompts.length > 0 && (
                    <div
                        style={{
                            display: 'flex',
                            flexWrap: 'wrap',
                            gap: 6,
                            justifyContent: 'center',
                            maxWidth: barWidthCss,
                        }}
                    >
                        {state.agent.starter_prompts.map((prompt, i) => (
                            <button
                                key={`${prompt}-${i}`}
                                type="button"
                                onClick={() => ask(prompt)}
                                disabled={!state.initialized}
                                style={{
                                    padding: '6px 12px',
                                    borderRadius: 999,
                                    border: '1px solid rgba(255,255,255,0.4)',
                                    background: 'rgba(255,255,255,0.85)',
                                    WebkitBackdropFilter: 'blur(6px)',
                                    backdropFilter: 'blur(6px)',
                                    color: '#0f172a',
                                    fontSize: 12,
                                    cursor: state.initialized
                                        ? 'pointer'
                                        : 'not-allowed',
                                    boxShadow: '0 4px 12px rgba(0,0,0,0.08)',
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
