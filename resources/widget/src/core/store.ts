import type { AgentConfig, InitResponse } from './api';

export type Citation = { id: number; url: string | null };

export type CtaPayload = {
    label: string;
    kind: string;
    url?: string | null;
};

export type Block = {
    type: string;
    payload: Record<string, unknown>;
};

export type Message = {
    id: string;
    role: 'user' | 'assistant' | 'human-agent';
    content: string;
    citations?: Citation[];
    blocks?: Block[];
    pending?: boolean;
    error?: boolean;
};

type Listener = (state: WidgetState) => void;

export type WidgetState = {
    open: boolean;
    initialized: boolean;
    init: InitResponse | null;
    agent: AgentConfig | null;
    messages: Message[];
    leadCaptured: boolean;
    leadFormOpen: boolean;
    activeCta: CtaPayload | null;
    /**
     * Phase 2 multi-CTA support. Server emits `ctas: [...]` on every
     * assistant turn — every CTA whose conditions matched, in priority
     * order. Widget renders stacked cards. `activeCta` is kept for
     * legacy bundles + single-card display when `activeCtas` is empty
     * (e.g. an older server that doesn't emit `ctas` yet).
     */
    activeCtas: CtaPayload[];
    isHumanHandling: boolean;
    /**
     * True when window.location.pathname matched a glob in the
     * agent's `restricted_paths`. The render bails to null so the
     * widget UI never mounts on this page. Distinct from `!initialized`
     * — restricted pages have completed init, they're just opted out
     * of the UI.
     */
    restricted: boolean;
    /**
     * Live-handoff state. `humanRequestedAt` is set when the visitor
     * clicked "Connect me with a human" but no operator has claimed
     * yet — drives the "Connecting you with someone…" indicator.
     * `operator` is set once an operator claims; the widget renders
     * "Sarah joined the chat" (or "An agent joined" when the
     * workspace's live_chat_personalize=false).
     */
    humanRequestedAt: string | null;
    operator: { name: string; personalized: boolean } | null;

    /**
     * Phase 2 routing decision returned by /request-human. Null when
     * the visitor hasn't asked yet. Drives the banner copy: queued
     * (green path) vs offline_no_operators / offline_after_hours
     * (visitor sees a "we'll email you" treatment instead of waiting).
     */
    handoffStatus:
        | 'queued'
        | 'offline_no_operators'
        | 'offline_after_hours'
        | null;
    nextOpenAt: string | null;
    /**
     * UNIX-ms timestamp at which the "Connecting you with someone…"
     * banner gives up and locally flips to `offline_no_operators`.
     * Server sends `wait_timeout_seconds` on the /request-human
     * response; the widget converts to an absolute deadline so a tab
     * refresh during the wait still respects the original window
     * instead of restarting the clock at 120s.
     */
    handoffWaitTimeoutAt: number | null;

    /**
     * Phase 3: operator-typing indicator. Surfaced from the server's
     * conversation-messages poll; flips on/off in ~3s buckets as the
     * operator types or stops typing.
     */
    operatorTyping: boolean;

    /**
     * Absolute UNIX-ms deadline until which the one-time "<Name> joined
     * the chat" notice stays visible. Set when `isHumanHandling`
     * transitions false→true; a timer nulls it ~8s later so the notice
     * auto-dismisses instead of sitting pinned above the input forever
     * (the header's persistent "Live agent" pill carries ongoing
     * status). Absolute (not a countdown) so a re-render never restarts
     * the clock. Null = hidden.
     */
    operatorJoinedNoticeUntil: number | null;

    /**
     * Phase 4: did this widget session experience an operator
     * (i.e. has a `human-agent` message ever been received)? When
     * true AND the operator subsequently leaves (`isHumanHandling`
     * flips back to false), the widget shows the satisfaction
     * prompt. We track it client-side to avoid prompting visitors
     * who never spoke to a human.
     */
    sawOperatorReply: boolean;
    /** True once the visitor has submitted a rating this session. */
    ratingSubmitted: boolean;
    /** True while the rating prompt is visible. */
    ratingPromptVisible: boolean;

    /**
     * Server-emitted stage hint while a turn is in flight. Drives the
     * "Searching…" / "Thinking…" label on the pending assistant bubble
     * so visitors see the work happen instead of an unbranded "…".
     * 'searching' / 'thinking' come from stage events; any other string
     * is a ready-to-render label set by the tool_call handler (e.g.
     * "Checking your order…"). Cleared when the first token arrives
     * (or `finalizeMessage` settles, whichever comes first).
     */
    currentStage: string | null;

    /**
     * True once the visitor dismissed the "Thanks — we'll get back to
     * you soon" lead confirmation (via its × or the 5s auto-hide).
     * Session-scoped; a reload shows nothing because leadCaptured
     * banners only follow a fresh submission.
     */
    leadBannerDismissed: boolean;

    /**
     * Count of assistant / human-agent messages that landed while the
     * bar was minimised. Rendered as a red badge over the closed
     * launcher. Resets to 0 the moment the visitor opens the bar.
     * In-memory only — not persisted to localStorage; a refresh
     * resets it (intentional, the visitor sees the thread on open).
     */
    unreadCount: number;
};

const OPEN_STATE_KEY = 'pitchbar:open';
const MESSAGES_KEY_PREFIX = 'pitchbar:msgs:';

/**
 * Local-storage cache of the last N messages for a given conversation
 * id. Acts as a fallback when the visitor reloads BEFORE PersistTurnJob
 * has flushed the turn to Postgres — the server-side resume path then
 * returns `messages: []` and we'd otherwise lose the in-flight thread.
 *
 * Capped to 50 entries (matches the server's resume window) and keyed
 * by conversation_id so a returning visitor on a different conversation
 * doesn't see stale history. Best-effort: any localStorage failure
 * (private mode, quota) is silently ignored.
 */
const MAX_CACHED_MESSAGES = 50;

function messagesKeyFor(conversationId: string): string {
    return MESSAGES_KEY_PREFIX + conversationId;
}

export function persistMessages(
    conversationId: string | undefined,
    messages: Message[],
): void {
    if (!conversationId) {
        return;
    }

    try {
        const snapshot = messages
            .filter((m) => !m.pending && m.content !== '')
            .slice(-MAX_CACHED_MESSAGES);

        if (snapshot.length === 0) {
            window.localStorage.removeItem(messagesKeyFor(conversationId));

            return;
        }

        window.localStorage.setItem(
            messagesKeyFor(conversationId),
            JSON.stringify({ at: Date.now(), messages: snapshot }),
        );
    } catch {
        // ignore — non-essential cache
    }
}

export function loadCachedMessages(conversationId: string): Message[] {
    try {
        const raw = window.localStorage.getItem(messagesKeyFor(conversationId));

        if (!raw) {
            return [];
        }

        const parsed = JSON.parse(raw) as {
            at?: number;
            messages?: Message[];
        };
        // Drop anything older than 14 days; visitor's session would
        // normally have expired by then anyway.
        const at = typeof parsed.at === 'number' ? parsed.at : 0;

        if (Date.now() - at > 14 * 24 * 60 * 60 * 1000) {
            window.localStorage.removeItem(messagesKeyFor(conversationId));

            return [];
        }

        return Array.isArray(parsed.messages) ? parsed.messages : [];
    } catch {
        return [];
    }
}

/**
 * In the omnibar redesign the pill is visible by default — visitors
 * shouldn't have to hunt for an open button. Only persist a value
 * when the visitor explicitly closes via the menu, in which case we
 * remember they don't want to see the bar across reloads.
 */
function loadInitialOpen(): boolean {
    try {
        return window.localStorage.getItem(OPEN_STATE_KEY) !== '0';
    } catch {
        return true;
    }
}

/**
 * Whether the visitor has ever explicitly set the open/closed state.
 * Used by the post-init override so the admin's `theme.default_open`
 * only applies on first load — once the visitor has expressed a
 * preference, that wins across reloads.
 */
export function hasUserOpenPreference(): boolean {
    try {
        return window.localStorage.getItem(OPEN_STATE_KEY) !== null;
    } catch {
        return false;
    }
}

function persistOpen(open: boolean): void {
    try {
        if (!open) {
            window.localStorage.setItem(OPEN_STATE_KEY, '0');
        } else {
            window.localStorage.removeItem(OPEN_STATE_KEY);
        }
    } catch {
        // ignore
    }
}

const initial: WidgetState = {
    open: loadInitialOpen(),
    initialized: false,
    init: null,
    agent: null,
    messages: [],
    leadCaptured: false,
    leadFormOpen: false,
    activeCta: null,
    activeCtas: [],
    isHumanHandling: false,
    restricted: false,
    humanRequestedAt: null,
    operator: null,
    handoffStatus: null,
    handoffWaitTimeoutAt: null,
    nextOpenAt: null,
    operatorTyping: false,
    operatorJoinedNoticeUntil: null,
    sawOperatorReply: false,
    ratingSubmitted: false,
    ratingPromptVisible: false,
    currentStage: null,
    leadBannerDismissed: false,
    unreadCount: 0,
};

/**
 * Wipe the conversation thread from the visitor's view. Server-side
 * the conversation row stays intact (analytics, lead linkage, audit)
 * — this only clears what's on screen, until the visitor sends the
 * next turn.
 */

export class WidgetStore {
    private state: WidgetState = { ...initial };

    private listeners: Set<Listener> = new Set();

    get(): WidgetState {
        return this.state;
    }

    /**
     * Apply the admin's agent.theme.default_open on first load WITHOUT
     * persisting it to localStorage. Going through `set()` would write
     * the override to OPEN_STATE_KEY and effectively impersonate a
     * visitor preference, locking that visitor out of any future
     * default-open change the admin makes. This method bypasses
     * persistOpen for that reason.
     */
    applyAgentDefaultOpen(open: boolean): void {
        if (this.state.open === open) {
            return;
        }

        this.state = { ...this.state, open };
        this.listeners.forEach((l) => l(this.state));
    }

    set(partial: Partial<WidgetState>): void {
        // Reset unreadCount the moment the visitor opens the bar.
        // Callers don't have to remember to clear it themselves.
        if (partial.open === true && this.state.unreadCount > 0) {
            partial = { ...partial, unreadCount: 0 };
        }

        this.state = { ...this.state, ...partial };

        if (partial.open !== undefined) {
            persistOpen(partial.open);
        }

        // Mirror the message list to localStorage whenever it changes.
        // The conversation_id only becomes known once /init resolves,
        // so the first writes after boot are the ones that actually
        // stick — earlier mutations would have happened pre-init when
        // there's no key to persist against.
        if (
            partial.messages !== undefined &&
            this.state.init?.conversation_id
        ) {
            persistMessages(this.state.init.conversation_id, partial.messages);
        }

        this.listeners.forEach((l) => l(this.state));
    }

    addMessage(msg: Message): void {
        // Dedupe by id. The human-operator long-poll seeds its cursor
        // from the hydrated message list and forwards it on every
        // tick; this is the safety net for the (rare) case where the
        // cursor ref slips back to null mid-session and the next
        // poll replays history. Without this dedupe, the visitor
        // sees every operator message twice.
        if (this.state.messages.some((m) => m.id === msg.id)) {
            return;
        }

        // Unread tracking: bump the counter when an assistant or
        // human-agent reply arrives while the bar is closed. The
        // visitor's own messages don't count — they just sent them.
        const shouldBumpUnread = !this.state.open && msg.role !== 'user';

        this.set({
            messages: [...this.state.messages, msg],
            ...(shouldBumpUnread
                ? { unreadCount: this.state.unreadCount + 1 }
                : {}),
        });
    }

    appendToken(messageId: string, token: string): void {
        const messages = this.state.messages.map((m) =>
            m.id === messageId
                ? { ...m, content: m.content + token, pending: false }
                : m,
        );
        // First token arrived — clear the "searching/thinking" stage
        // hint so the indicator copy doesn't linger over real content.
        this.set(
            this.state.currentStage !== null
                ? { messages, currentStage: null }
                : { messages },
        );
    }

    finalizeMessage(
        messageId: string,
        text: string,
        citations: Citation[],
    ): void {
        const messages = this.state.messages.map((m) =>
            m.id === messageId
                ? { ...m, content: text, citations, pending: false }
                : m,
        );
        this.set({ messages, currentStage: null });
    }

    /**
     * Attach a Block to a streaming assistant message. Block events
     * arrive interleaved with token events when a tool produced
     * structured output (e.g. an escalation_button). Renderers in
     * `ui/blocks.tsx` look up each block by type.
     */
    appendBlock(messageId: string, block: Block): void {
        const messages = this.state.messages.map((m) =>
            m.id === messageId
                ? { ...m, blocks: [...(m.blocks ?? []), block] }
                : m,
        );
        this.set({ messages });
    }

    /**
     * Wipe a streamed bubble back to a clean "thinking…" placeholder.
     * Used between auto-retry attempts inside Bar.streamReply so a
     * partial response from a failed first attempt doesn't leak into
     * the second attempt's render. Same id, same array position, just
     * a fresh canvas.
     */
    resetMessageForRetry(messageId: string): void {
        const messages = this.state.messages.map((m) =>
            m.id === messageId
                ? {
                      ...m,
                      content: '',
                      citations: [],
                      pending: true,
                      error: false,
                  }
                : m,
        );
        this.set({ messages });
    }

    /**
     * Mark an assistant message as failed so the UI can show a retry
     * affordance. The user's original turn (the message immediately
     * before this one in the array) is what gets re-sent on retry.
     */
    failMessage(messageId: string, errorText: string): void {
        const messages = this.state.messages.map((m) =>
            m.id === messageId
                ? {
                      ...m,
                      content: errorText,
                      pending: false,
                      error: true,
                  }
                : m,
        );
        this.set({ messages });
    }

    removeMessage(messageId: string): void {
        this.set({
            messages: this.state.messages.filter((m) => m.id !== messageId),
        });
    }

    clearMessages(): void {
        this.set({
            messages: [],
            activeCta: null,
            activeCtas: [],
            leadFormOpen: false,
        });
    }

    openWith(message: string): void {
        const id = `m-${Date.now()}-trigger`;
        this.set({
            open: true,
            messages: [
                ...this.state.messages,
                { id, role: 'assistant', content: message },
            ],
        });
    }

    subscribe(listener: Listener): () => void {
        this.listeners.add(listener);

        return () => this.listeners.delete(listener);
    }
}

export const store = new WidgetStore();
