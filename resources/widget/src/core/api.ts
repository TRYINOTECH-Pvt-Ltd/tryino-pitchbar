export type AgentConfig = {
    id: string;
    name: string;
    persona: Record<string, unknown> | null;
    theme: Record<string, unknown> | null;
    starter_prompts: string[] | null;
    language_default: string;
    // Vertical-adaptive Phase 1: server returns these so the widget can
    // tag telemetry and (in Phase 3) gate rich-message rendering. Both
    // are optional + string-typed so a future server-added vertical or
    // capability never crashes a deployed bundle.
    site_type?: string;
    capabilities?: string[];
    /**
     * URL paths the widget should NOT mount on. Each entry may include
     * `*` wildcards (e.g. `/admin/*`). Compared against
     * `window.location.pathname` case-insensitively at boot.
     * Optional: legacy bundles that don't know about this field still
     * work — server returns `[]` when no paths are set.
     */
    restricted_paths?: string[];
    /**
     * When true, the widget gates the chat surface behind a Name +
     * Email form. Renders <PreChatGate/> first; once submitted (and
     * the lead is created server-side) the chat panel unlocks.
     * Optional: legacy bundles default to the unlocked behaviour.
     */
    require_lead_before_chat?: boolean;
    /**
     * Custom lead-form schema (#34). Per-agent JSON list of field
     * definitions; the widget renders these in both the inline
     * lead form and the pre-chat gate. Null / undefined / empty =
     * fall back to the hard-coded Name + Email shape so older
     * agents keep working.
     */
    lead_form_fields?: LeadFormField[] | null;
    /**
     * i18n: ISO 639-1 locale resolved by the server (priority: agent
     * default → visitor Accept-Language → "en"). Drives `<html lang>`
     * and copy lookup.
     */
    locale?: string;
    /**
     * i18n: key→translated-string map for every visitor-facing label
     * the widget renders. Server materialises this once at /init time
     * (no per-message lookup, no extra DB query). Keys are English
     * source strings; missing keys fall back to the key itself.
     */
    copy?: Record<string, string>;
};

export type LeadFormFieldType =
    | 'text'
    | 'email'
    | 'tel'
    | 'textarea'
    | 'select'
    | 'checkbox';

export type LeadFormField = {
    key: string;
    label: string;
    type: LeadFormFieldType;
    required?: boolean;
    placeholder?: string | null;
    maxlength?: number | null;
    options?: string[];
};

export type ReverbConfig = {
    app_key: string;
    host: string;
    port: number;
    scheme: 'http' | 'https';
};

export type InitMessage = {
    id: string;
    role: 'user' | 'assistant' | 'human-agent';
    content: string;
    citations: { id: number; url: string | null }[];
};

export type Branding = {
    show: boolean;
    label: string;
    url: string;
    logo_url?: string | null;
    display_mode?: 'logo_text' | 'logo_only' | 'text_only';
};

export type InitResponse = {
    conversation_id: string;
    visitor_id: string;
    anonymous_id: string;
    jwt: string;
    expires_at: number;
    agent: AgentConfig;
    branding?: Branding;
    reverb: ReverbConfig;
    messages?: InitMessage[];
    /**
     * True when the visitor has already captured a lead for this
     * conversation — used by the pre-chat-gate runtime to avoid
     * re-prompting on every page refresh. Server resolves it via a
     * single indexed existence check; legacy bundles (and gate-off
     * agents) ignore the field.
     */
    lead_captured?: boolean;
};

export class WidgetApi {
    constructor(private readonly baseUrl: string) {}

    /**
     * Arguments of the last successful init(), kept so an expired token can be
     * re-minted transparently. The widget JWT lives 60 minutes; a tab left open
     * past that got a 401 on every send, and because the retry button re-used
     * the same dead token the chat stayed broken until a page reload.
     */
    private lastInitArgs: {
        agentId: string;
        pageUrl: string;
        anonId?: string;
        shopperToken?: string | null;
        locale?: string | null;
    } | null = null;

    /**
     * Called with the fresh init payload after a silent re-authentication, so
     * the app can store the new JWT for subsequent turns.
     */
    private onReauth?: (init: InitResponse) => void;

    /**
     * Register the re-authentication listener. A setter rather than a public
     * field so callers don't mutate the (hook-owned) client instance directly.
     */
    setOnReauth(listener: (init: InitResponse) => void): void {
        this.onReauth = listener;
    }

    async init(
        agentId: string,
        pageUrl: string,
        anonId?: string,
        shopperToken?: string | null,
        locale?: string | null,
    ): Promise<InitResponse> {
        this.lastInitArgs = { agentId, pageUrl, anonId, shopperToken, locale };

        const body: Record<string, unknown> = {
            agent_id: agentId,
            page_url: pageUrl,
            anon_id: anonId,
        };

        if (typeof shopperToken === 'string' && shopperToken !== '') {
            body.shopper_token = shopperToken;
        }

        // Host page language → widget UI locale. Server treats it as the
        // top-priority signal so the chat follows the site's language.
        if (typeof locale === 'string' && locale !== '') {
            body.locale = locale.slice(0, 12);
        }

        // Trajectory signals — fed to the LeadScoringEngine
        // server-side. Both are best-effort: a missing title or
        // referrer just skips the corresponding field.
        try {
            if (typeof document !== 'undefined') {
                const title = document.title ?? '';

                if (title !== '') {
                    body.page_title = title.slice(0, 500);
                }

                const referrer = document.referrer ?? '';

                if (referrer !== '') {
                    body.referrer = referrer.slice(0, 2000);
                }
            }
        } catch {
            // Sandboxed iframes occasionally throw on document access — ignore.
        }

        const response = await fetch(`${this.baseUrl}/api/v1/widget/init`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(body),
            credentials: 'omit',
        });

        if (!response.ok) {
            throw new Error(`init failed: ${response.status}`);
        }

        const json = await response.json();

        return json.data as InitResponse;
    }

    async sendMessage(
        token: string,
        message: string,
    ): Promise<{
        message_id: string;
        text: string;
        citations: { id: number; url: string | null }[];
    }> {
        const response = await fetch(`${this.baseUrl}/api/v1/widget/messages`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Authorization: `Bearer ${token}`,
            },
            body: JSON.stringify({ message }),
        });

        if (!response.ok) {
            throw new Error(`message failed: ${response.status}`);
        }

        return (await response.json()).data;
    }

    /**
     * Stream a message turn over SSE. Calls onToken(token) as each token arrives,
     * and onDone(payload) when the stream completes.
     *
     * Reliability hardening for production proxies (cPanel, Cloudflare,
     * shared hosting):
     *  - Stale-stream detector: aborts the fetch when no events arrive
     *    for `STALE_TIMEOUT_MS` so the widget never hangs forever on a
     *    silently-dropped connection.
     *  - "Stream ended without 'done'" is treated as an error so the
     *    visitor sees a proper error bubble + retry, not an empty
     *    "thinking..." that never resolves.
     */
    /**
     * Mint a fresh JWT after a 401. Re-running init with the SAME anon id is
     * safe for continuity: the server resumes the visitor's most recent active
     * conversation rather than starting a new one, so the transcript survives.
     * Returns null when there is nothing to retry with.
     */
    private async reauthenticate(): Promise<string | null> {
        const args = this.lastInitArgs;

        if (args === null) {
            return null;
        }

        try {
            const fresh = await this.init(
                args.agentId,
                args.pageUrl,
                args.anonId,
                args.shopperToken,
                args.locale,
            );

            this.onReauth?.(fresh);

            return fresh.jwt;
        } catch {
            // Re-auth is best-effort — fall through to the original error.
            return null;
        }
    }

    async streamMessage(
        token: string,
        message: string,
        callbacks: {
            onStart?: (msg: {
                conversation_id: string;
                message_id: string;
            }) => void;
            onToken: (text: string) => void;
            onDone?: (payload: {
                text: string;
                citations: { id: number; url: string | null }[];
                low_confidence: boolean;
                latency_ms: number;
                cta?: {
                    label: string;
                    kind: string;
                    url?: string | null;
                } | null;
                ctas?: {
                    label: string;
                    kind: string;
                    url?: string | null;
                }[];
                lead_prompt?: boolean;
                /** True when an operator owns the conversation and the bot stayed silent. */
                human_takeover?: boolean;
            }) => void;
            onError?: (err: { code: string; message?: string }) => void;
            onToolCall?: (event: {
                name: string;
                args: Record<string, unknown>;
            }) => void;
            onBlock?: (block: {
                type: string;
                payload: Record<string, unknown>;
            }) => void;
            /**
             * Stage hint from the server. Currently 'searching' (retrieval
             * running) or 'thinking' (LLM composing). Drives the typing
             * indicator copy so the pre-token wait reads as work-in-progress
             * instead of an unresponsive pill.
             */
            onStage?: (stage: { s: string }) => void;
        },
        pageContext?: unknown,
    ): Promise<void> {
        // Generous timeouts: enough to absorb a Cloudflare Workers AI cold
        // start (≈5–8s on first hit) without giving up too early.
        // Tool-using agents run up to 3 non-streaming completions (5-15s
        // each on Workers AI 70B) before the first token. The server now
        // heartbeats before each hop, but a single slow hop can still gap
        // ~30s, so the stale budget must clear one worst-case completion.
        const STALE_TIMEOUT_MS = 35_000; // no event for 35s -> abort
        const TOTAL_TIMEOUT_MS = 120_000; // hard ceiling on the whole turn

        const controller = new AbortController();
        let abortReason: string | null = null;
        let lastEventAt = Date.now();
        const startedAt = Date.now();

        // Fire-and-forget: tell the server the visitor's stream froze so it
        // surfaces on the admin Widget Monitor. `keepalive` lets the POST
        // survive the page/stream teardown that follows the abort. Telemetry
        // must never break the widget, so every failure is swallowed.
        const reportStall = (reason: string) => {
            try {
                void fetch(`${this.baseUrl}/api/v1/widget/events`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        Authorization: `Bearer ${token}`,
                    },
                    body: JSON.stringify({
                        events: [
                            {
                                kind: 'widget.stream_stalled',
                                payload: {
                                    reason,
                                    elapsed_ms: Date.now() - startedAt,
                                },
                            },
                        ],
                    }),
                    keepalive: true,
                }).catch(() => {});
            } catch {
                // ignore — never let telemetry break the chat
            }
        };

        const staleCheck = window.setInterval(() => {
            if (Date.now() - lastEventAt > STALE_TIMEOUT_MS) {
                abortReason = 'stale_stream';
                reportStall('stale_stream');
                controller.abort();
            } else if (Date.now() - startedAt > TOTAL_TIMEOUT_MS) {
                abortReason = 'total_timeout';
                reportStall('total_timeout');
                controller.abort();
            }
        }, 2_000);

        let response: Response;

        const send = (bearer: string): Promise<Response> =>
            fetch(`${this.baseUrl}/api/v1/widget/messages/stream`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Authorization: `Bearer ${bearer}`,
                    Accept: 'text/event-stream',
                },
                body: JSON.stringify(
                    pageContext
                        ? { message, page_context: pageContext }
                        : { message },
                ),
                signal: controller.signal,
            });

        try {
            response = await send(token);
        } catch (err) {
            window.clearInterval(staleCheck);
            const code = abortReason ?? 'network_failed';
            callbacks.onError?.({
                code,
                message: err instanceof Error ? err.message : code,
            });

            return;
        }

        // The widget JWT expires after 60 minutes. A tab left open past that
        // got a 401 on every send, and the retry button re-used the same dead
        // token — so the chat stayed broken until a page reload, and the
        // visitor only ever saw the generic "something went wrong" line. Mint a
        // fresh token once and replay the same message; init resumes the
        // visitor's existing conversation, so nothing is lost. Retry EXACTLY
        // once, and only on 401, so a genuinely unauthorised agent can't loop.
        if (response.status === 401) {
            const refreshed = await this.reauthenticate();

            if (refreshed !== null) {
                try {
                    response = await send(refreshed);
                } catch (err) {
                    window.clearInterval(staleCheck);
                    const code = abortReason ?? 'network_failed';
                    callbacks.onError?.({
                        code,
                        message: err instanceof Error ? err.message : code,
                    });

                    return;
                }
            }
        }

        if (!response.ok || !response.body) {
            window.clearInterval(staleCheck);
            callbacks.onError?.({
                code: 'http_failed',
                message: `HTTP ${response.status}`,
            });

            return;
        }

        const reader = response.body.getReader();
        const decoder = new TextDecoder('utf-8');
        let buffer = '';
        let eventName = '';
        let doneSeen = false;
        let errorSeen = false;

        const handleEvent = (event: string, data: string) => {
            // SSE comment lines start with ':' (server heartbeats).
            // We just want to update lastEventAt and ignore.
            if (event === '') {
                return;
            }

            try {
                const payload = JSON.parse(data);

                if (event === 'start') {
                    callbacks.onStart?.(payload);
                } else if (event === 'token') {
                    callbacks.onToken(payload.t ?? '');
                } else if (event === 'tool_call') {
                    callbacks.onToolCall?.(payload);
                } else if (event === 'block') {
                    callbacks.onBlock?.(payload);
                } else if (event === 'stage') {
                    callbacks.onStage?.(payload);
                } else if (event === 'done') {
                    doneSeen = true;
                    callbacks.onDone?.(payload);
                } else if (event === 'error') {
                    errorSeen = true;
                    callbacks.onError?.(payload);
                }
            } catch {
                // ignore malformed line
            }
        };

        try {
            for (;;) {
                const { value, done } = await reader.read();

                if (done) {
                    break;
                }

                lastEventAt = Date.now();
                buffer += decoder.decode(value, { stream: true });

                // Split on blank-line message boundaries
                let boundary = buffer.indexOf('\n\n');

                while (boundary !== -1) {
                    const block = buffer.slice(0, boundary);
                    buffer = buffer.slice(boundary + 2);
                    eventName = '';
                    let dataLines = '';
                    let isCommentOnly = false;

                    for (const line of block.split('\n')) {
                        if (line.startsWith(':')) {
                            // SSE heartbeat comment — keep-alive only,
                            // refresh the staleness clock.
                            isCommentOnly = true;
                        } else if (line.startsWith('event:')) {
                            eventName = line.slice(6).trim();
                        } else if (line.startsWith('data:')) {
                            dataLines += line.slice(5).trim();
                        }
                    }

                    if (eventName && dataLines) {
                        handleEvent(eventName, dataLines);
                    } else if (isCommentOnly) {
                        // already updated lastEventAt above
                    }

                    boundary = buffer.indexOf('\n\n');
                }
            }
        } catch (err) {
            window.clearInterval(staleCheck);
            const code = abortReason ?? 'stream_aborted';
            // Fire onError so the widget shows a retry affordance
            // instead of leaving the bubble in pending state.
            callbacks.onError?.({
                code,
                message:
                    err instanceof Error ? err.message : 'Stream interrupted.',
            });

            return;
        }

        window.clearInterval(staleCheck);

        // Stream finished cleanly but no `done` event arrived — common
        // failure mode behind buffering proxies (Cloudflare CDN, cPanel
        // FastCGI, mod_deflate). Treat as an error so the visitor sees a
        // retry affordance instead of an indefinite "thinking…".
        if (!doneSeen && !errorSeen) {
            callbacks.onError?.({
                code: 'stream_ended_without_done',
                message: 'The connection closed before the answer was ready.',
            });
        }
    }

    async captureLead(
        token: string,
        payload: {
            email: string;
            name?: string;
            phone?: string;
            /**
             * Arbitrary key→value pairs from the agent's custom
             * lead_form_fields schema (#34). Server stores them on
             * the Lead's `fields` JSON column. Reserved keys
             * (email/name/phone) ride at the top level so analytics
             * queries still work.
             */
            fields?: Record<string, string | boolean>;
        },
    ): Promise<void> {
        await fetch(`${this.baseUrl}/api/v1/widget/leads`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Authorization: `Bearer ${token}`,
            },
            body: JSON.stringify(payload),
        });
    }

    /**
     * Long-poll for messages typed by a human operator who has claimed
     * this conversation. Bot replies arrive via streamMessage; this
     * picks up the takeover side.
     */
    async pollHumanMessages(
        token: string,
        after?: string | null,
    ): Promise<{
        is_claimed: boolean;
        human_requested_at: string | null;
        operator: { name: string; personalized: boolean } | null;
        operator_typing: boolean;
        messages: {
            id: string;
            role: 'human-agent';
            content: string;
            at: string;
        }[];
    }> {
        const qs = after ? `?after=${encodeURIComponent(after)}` : '';
        const response = await fetch(
            `${this.baseUrl}/api/v1/widget/conversation/messages${qs}`,
            {
                method: 'GET',
                headers: {
                    Authorization: `Bearer ${token}`,
                    Accept: 'application/json',
                },
            },
        );

        if (!response.ok) {
            throw new Error(`poll failed: ${response.status}`);
        }

        return (await response.json()).data;
    }

    /**
     * Post-conversation rating. Visitor picks thumbs up / down after a
     * human chat ends; first rating sticks (server-side lock), later
     * submissions update the comment only.
     */
    async signalSatisfaction(
        token: string,
        rating: 'positive' | 'negative',
        comment?: string,
    ): Promise<void> {
        await fetch(`${this.baseUrl}/api/v1/widget/satisfaction`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Authorization: `Bearer ${token}`,
            },
            body: JSON.stringify({ rating, comment: comment ?? null }),
        }).catch(() => {});
    }

    /**
     * Visitor-side typing indicator hint. Server sets the
     * conversation's `visitor_typing_until` 5 seconds in the future;
     * the operator-side polling read flips a "Visitor is typing…"
     * indicator while the column is fresh. Self-expiring window —
     * stop pinging and the indicator clears in ~5s.
     *
     * The widget debounces fires to one POST every 2 seconds while
     * keystrokes are happening; the route is throttled at the
     * server level too as a safety net.
     */
    async signalTyping(token: string): Promise<void> {
        await fetch(`${this.baseUrl}/api/v1/widget/typing`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Authorization: `Bearer ${token}`,
            },
        }).catch(() => {
            // Best-effort.
        });
    }

    /**
     * Visitor-side "I want to talk to a human" signal. Returns the
     * server's routing decision so the widget can pick between three
     * banner states:
     *   - queued                 — operators online, will be claimed
     *   - offline_no_operators   — within hours but nobody's around
     *   - offline_after_hours    — outside configured business hours
     *
     * Idempotent: multiple clicks during the same waiting window
     * resolve to the same `human_requested_at` timestamp + same status.
     */
    /**
     * Apply a coupon code emitted by a `<coupon/>` block. Proxies to
     * the WordPress plugin via `/api/v1/widget/coupon/apply` so the
     * widget never needs cross-origin access to the merchant's WP
     * site. Resolves with `{ applied, pending, message }` on success
     * or throws on transport / HTTP errors.
     */
    async applyCoupon(
        token: string,
        code: string,
    ): Promise<{
        applied?: boolean;
        pending?: boolean;
        message?: string;
    }> {
        const res = await fetch(`${this.baseUrl}/api/v1/widget/coupon/apply`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Authorization: `Bearer ${token}`,
            },
            body: JSON.stringify({ code }),
        });

        if (!res.ok) {
            const body = await res.json().catch(() => null);
            const error =
                body && typeof body === 'object' && 'error' in body
                    ? (body as { error?: { code?: string; message?: string } })
                          .error
                    : null;

            throw new Error(
                error?.message ?? error?.code ?? `apply_failed_${res.status}`,
            );
        }

        const payload = await res.json();

        return payload?.data ?? {};
    }

    /**
     * Mint a Stripe Checkout session for an in-chat <checkout/> block.
     * The widget POSTs the parsed block attributes; server creates a
     * hosted Stripe Checkout session and returns `checkout_url`.
     */
    async createCheckout(
        token: string,
        body: {
            title: string;
            amount: string;
            currency: string;
            description?: string;
            product_id?: string;
        },
    ): Promise<
        | { checkout_url: string; session_id: string; id: string }
        | { error: { code: string; message?: string } }
    > {
        const res = await fetch(
            `${this.baseUrl}/api/v1/widget/checkout/create`,
            {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Authorization: `Bearer ${token}`,
                },
                body: JSON.stringify(body),
            },
        );

        if (!res.ok) {
            const errBody = await res.json().catch(() => null);
            const err =
                errBody && typeof errBody === 'object' && 'error' in errBody
                    ? (
                          errBody as {
                              error?: { code?: string; message?: string };
                          }
                      ).error
                    : null;

            return {
                error: {
                    code: err?.code ?? `http_${res.status}`,
                    message: err?.message,
                },
            };
        }

        const payload = await res.json();

        return payload?.data ?? { error: { code: 'malformed_response' } };
    }

    async requestHuman(token: string): Promise<{
        status: 'queued' | 'offline_no_operators' | 'offline_after_hours';
        next_open_at: string | null;
        human_requested_at: string | null;
        wait_timeout_seconds?: number;
        active_operator_count?: number;
        claimed?: boolean;
    }> {
        const res = await fetch(`${this.baseUrl}/api/v1/widget/request-human`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Authorization: `Bearer ${token}`,
            },
        });

        if (!res.ok) {
            // Best-effort fallback so a transient outage doesn't leave
            // the widget in a broken state. We assume queued so the
            // visitor sees the optimistic "Connecting you with someone…"
            // — the bot's holding-bubble short-circuit only fires if
            // the server actually persisted the flag, so a failed POST
            // here means the bot will keep responding normally instead.
            return {
                status: 'queued',
                next_open_at: null,
                human_requested_at: null,
                wait_timeout_seconds: 120,
                active_operator_count: 0,
                claimed: false,
            };
        }

        return (await res.json()).data;
    }

    /**
     * Server-side counterpart to the widget's "Clear conversation"
     * menu item. Stamps `cleared_at` on the visitor's current
     * conversation row so /init stops re-hydrating older messages on
     * subsequent page loads. The current JWT keeps working — we don't
     * end the conversation, just hide history.
     */
    async clearConversation(token: string): Promise<void> {
        await fetch(`${this.baseUrl}/api/v1/widget/conversation/clear`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Authorization: `Bearer ${token}`,
            },
        });
    }

    async logEvents(
        token: string,
        events: { kind: string; payload?: Record<string, unknown> }[],
    ): Promise<void> {
        await fetch(`${this.baseUrl}/api/v1/widget/events`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Authorization: `Bearer ${token}`,
            },
            body: JSON.stringify({ events }),
        });
    }
}
