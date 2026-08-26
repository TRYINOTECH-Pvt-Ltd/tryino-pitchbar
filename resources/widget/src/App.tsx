import { useEffect, useMemo, useRef, useState } from 'preact/hooks';
import { WidgetApi } from './core/api';
import { siteType } from './core/capabilities';
import { direction, setI18n } from './core/i18n';
import { isPathRestricted } from './core/path-restrictions';
import { hasUserOpenPreference, loadCachedMessages, store } from './core/store';
import { TriggerEngine } from './triggers/engine';
import type { TriggerRule } from './triggers/engine';
import { Bar } from './ui/Bar';

type Props = {
    agentId: string | null;
    baseUrl: string;
    demo?: boolean;
    shopperToken?: string | null;
    /**
     * Host page's language (the embed's `data-locale`, else the page's
     * `<html lang>`), forwarded to /init so the widget UI follows the
     * site's selected language instead of the agent's pinned default.
     */
    locale?: string | null;
};

const COOKIE = 'pb_anon_id';

function readAnonId(): string | undefined {
    const match = document.cookie.match(/(?:^|; )pb_anon_id=([^;]+)/);

    return match ? decodeURIComponent(match[1]) : undefined;
}

function writeAnonId(id: string): void {
    const oneYear = 60 * 60 * 24 * 365;
    document.cookie = `${COOKIE}=${encodeURIComponent(id)}; max-age=${oneYear}; path=/; SameSite=Lax`;
}

/**
 * First-party cookie on the merchant's domain so CMS adapters (WP
 * plugin's CartCouponController) can correlate the visitor's
 * Pitchbar conversation with their WordPress session. Set after
 * /widget/init succeeds. Day-long lifetime — matches the plugin's
 * 15-minute pending-coupon transient window with plenty of slack.
 */
function writeConversationCookie(conversationId: string): void {
    if (!conversationId) {
        return;
    }

    const oneDay = 60 * 60 * 24;
    document.cookie = `pitchbar_conv_id=${encodeURIComponent(conversationId)}; max-age=${oneDay}; path=/; SameSite=Lax`;
}

export function App({
    agentId,
    baseUrl,
    demo = false,
    shopperToken = null,
    locale = null,
}: Props) {
    const api = useMemo(() => new WidgetApi(baseUrl), [baseUrl]);
    const [state, setState] = useState(store.get());
    const [error, setError] = useState<string | null>(null);

    // Cursor for the long-poll that picks up human-operator replies.
    // Held in a ref so it survives effect re-runs (effect deps only
    // cover bar-open + JWT — see the polling effect below for the bug
    // this guards against: duplicate operator messages every time
    // operator_typing flipped, the old deps re-ran the effect, reset
    // lastSeen, and the next tick re-fetched history from scratch).
    const lastHumanPollCursorRef = useRef<string | null>(null);
    const humanPollJwtRef = useRef<string | null>(null);

    useEffect(() => store.subscribe(setState), []);

    useEffect(() => {
        if (!agentId || state.initialized) {
            return;
        }

        let cancelled = false;
        let engine: TriggerEngine | null = null;

        // The widget JWT expires after 60 minutes. When the API client
        // silently re-mints one mid-conversation, store the fresh init so
        // every later turn uses the live token instead of re-authenticating
        // on each send.
        api.setOnReauth((refreshed) => {
            if (!cancelled) {
                store.set({ init: refreshed });
            }
        });

        api.init(agentId, window.location.href, readAnonId(), shopperToken, locale)
            .then((init) => {
                if (cancelled) {
                    return;
                }

                writeAnonId(init.anonymous_id);
                writeConversationCookie(init.conversation_id);

                // Hydrate the i18n singleton BEFORE any render so the
                // first paint of <Bar/> already speaks the right
                // language. Server resolves locale from agent.language_default
                // → visitor Accept-Language → "en".
                setI18n(init.agent);

                // Mirror the locale's writing direction onto our shadow
                // root so the widget bar/messages/lead-form mirror as a
                // unit when the agent's language is RTL (Arabic, Hebrew,
                // Persian, Urdu, …). The shadow root is the widget's
                // visual viewport — setting `dir` here is enough; every
                // child element inherits it without per-component code.
                const root = (document.getElementById('pitchbar-root')
                    ?.shadowRoot?.firstElementChild ??
                    null) as HTMLElement | null;

                if (root) {
                    root.setAttribute('dir', direction());
                }

                // Restricted-paths gate. The agent's admin lists URL
                // patterns the widget should NOT mount on (their own
                // /admin, /checkout, /account flows). If the current
                // pathname matches any pattern, mark the run
                // initialized + restricted and bail BEFORE registering
                // triggers, opening the bar, or logging widget.ready.
                if (
                    isPathRestricted(
                        window.location.pathname,
                        init.agent.restricted_paths,
                    )
                ) {
                    store.set({
                        init,
                        agent: init.agent,
                        initialized: true,
                        restricted: true,
                    });

                    return;
                }

                // Hydrate any prior turns the server returned for this
                // (visitor, conversation) so chat history persists across
                // page reloads.
                const serverMessages = (init.messages ?? []).map((m) => ({
                    id: m.id,
                    role: m.role,
                    content: m.content,
                    citations: m.citations ?? [],
                }));

                // Server-side persistence rides on PersistTurnJob, which
                // runs ~60s after each turn on the database queue driver.
                // If the visitor reloads before that fires we'd see an
                // empty `messages` array even though the conversation was
                // active. localStorage cache is the bridge: we restore
                // any cached turns the server hasn't acked yet, and
                // server-side messages always win on the dedupe pass.
                const cached = loadCachedMessages(init.conversation_id);
                const seenIds = new Set(serverMessages.map((m) => m.id));
                const mergedMessages = [
                    ...serverMessages,
                    ...cached.filter((m) => !seenIds.has(m.id)),
                ];

                // Auto-open override: when the admin's
                // theme.default_open is explicitly false AND the visitor
                // has never expressed a preference, start the bar
                // closed. The visitor's own open/close choice (persisted
                // to localStorage on click) always wins on subsequent
                // loads. Applied via applyAgentDefaultOpen so the
                // override does NOT itself write to localStorage.
                const themeDefaultOpen = (
                    init.agent.theme as { default_open?: unknown } | null
                )?.default_open;

                if (themeDefaultOpen === false && !hasUserOpenPreference()) {
                    store.applyAgentDefaultOpen(false);
                }

                store.set({
                    init,
                    agent: init.agent,
                    initialized: true,
                    messages: mergedMessages,
                    // Pre-chat gate: server tells us whether this
                    // conversation has already captured a lead, so a
                    // returning visitor doesn't see the gate again on
                    // refresh. Defaults to false on legacy responses.
                    leadCaptured: init.lead_captured === true,
                });

                // Vertical-adaptive Phase 1: tag this load with the
                // resolved site_type so analytics can segment by vertical.
                api.logEvents(init.jwt, [
                    {
                        kind: 'widget.ready',
                        payload: { site_type: siteType(init.agent) },
                    },
                ]).catch(() => {});

                // Start behavior triggers if any are configured
                const rules =
                    (init as { behavior_rules?: TriggerRule[] })
                        .behavior_rules ?? [];

                if (rules.length > 0) {
                    engine = new TriggerEngine(rules, (rule) => {
                        const message =
                            (rule.action as { message?: string } | undefined)
                                ?.message ??
                            'Hi! Can I help you find anything?';
                        store.openWith(message);
                        // Best-effort log
                        api.logEvents(init.jwt, [
                            {
                                kind: 'trigger.fired',
                                payload: { rule_id: rule.id, kind: rule.kind },
                            },
                        ]).catch(() => {});
                    });
                    engine.start();
                }
            })
            .catch((err) => {
                if (cancelled) {
                    return;
                }

                setError(
                    err instanceof Error
                        ? err.message
                        : 'Failed to load assistant.',
                );
            });

        return () => {
            cancelled = true;
            engine?.stop();
        };
        // state.initialized intentionally NOT in deps. It flips from
        // false → true INSIDE api.init().then() above (store.set). If
        // we included it, the moment we attached the trigger listeners
        // (line ~204), React would re-run this effect, run its cleanup,
        // call engine.stop(), and tear the listeners off again — the
        // exact `mouseout` handler that powers behavior triggers would
        // exist for ~1 frame. Caught via real-browser E2E: two adds
        // immediately followed by two removes, then no trigger ever
        // fired even though the rules were configured. The early-bail
        // at the top of the effect (`if (state.initialized) return`)
        // already prevents double-init when agentId/api/shopperToken
        // change in a way that would re-run this hook legitimately.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [agentId, api, shopperToken, locale]);

    // Long-poll for human-operator replies whenever the bar is open and a
    // JWT is available. Bot replies stream over SSE; this picks up the
    // takeover side. 3-second cadence is gentle on the server (one indexed
    // query per active visitor) and feels real-time to the visitor.
    //
    // Cursor + JWT live in refs (NOT effect deps) so that every poll-tick
    // that flips `operator_typing`, `isHumanHandling`, or any other
    // handoff field does NOT tear down + restart this effect. Pre-fix:
    // the effect listed all six handoff fields as deps; the very next
    // poll that bumped `operator_typing` would re-run the effect, reset
    // `lastSeen = null`, and the subsequent tick fetched the FULL
    // operator-message history again — every human reply landed in the
    // store a second, third, fourth time. Operators typing on `/typing`
    // updates `operator_typing_until` and made it even worse.
    useEffect(() => {
        const jwt = state.init?.jwt;

        if (!state.open || !jwt) {
            return;
        }

        // Cursor resets when the JWT changes (new conversation).
        // Otherwise it persists across re-runs of this effect.
        if (humanPollJwtRef.current !== jwt) {
            humanPollJwtRef.current = jwt;
            lastHumanPollCursorRef.current = null;
        }

        // Seed the cursor from the hydrated message list so the first
        // poll on bar-open doesn't replay history that's already on
        // screen (Inertia/init populated the thread with prior
        // human-agent turns before the widget mounted).
        if (lastHumanPollCursorRef.current === null) {
            const humans = store
                .get()
                .messages.filter((m) => m.role === 'human-agent');

            if (humans.length > 0) {
                lastHumanPollCursorRef.current =
                    humans[humans.length - 1]?.id ?? null;
            }
        }

        let stopped = false;

        const tick = async () => {
            // Read CURRENT state on every tick — not closure-captured
            // values from when the effect last ran. Keeps the
            // "operator just left → show rating" check accurate
            // without resubscribing.
            const current = store.get();

            try {
                const out = await api.pollHumanMessages(
                    jwt,
                    lastHumanPollCursorRef.current,
                );

                if (stopped) {
                    return;
                }

                // Sync the four live-handoff fields. We update them in
                // a single store.set so the renderer doesn't see an
                // intermediate state where humanRequestedAt is set
                // but operator is stale, etc.
                const handoffPatch: Record<string, unknown> = {};

                if (out.is_claimed !== current.isHumanHandling) {
                    handoffPatch.isHumanHandling = out.is_claimed;

                    // Operator just claimed (false→true): arm the
                    // one-time "joined the chat" notice for ~8s. A timer
                    // in Bar clears the deadline so it auto-dismisses
                    // instead of sitting pinned above the input forever.
                    if (out.is_claimed) {
                        handoffPatch.operatorJoinedNoticeUntil =
                            Date.now() + 8000;
                    }
                }

                if (out.human_requested_at !== current.humanRequestedAt) {
                    handoffPatch.humanRequestedAt = out.human_requested_at;
                }

                const opChanged =
                    JSON.stringify(out.operator) !==
                    JSON.stringify(current.operator);

                if (opChanged) {
                    handoffPatch.operator = out.operator;
                }

                if (out.operator_typing !== current.operatorTyping) {
                    handoffPatch.operatorTyping = out.operator_typing;
                }

                // Phase 4: detect "the operator just left" so we can
                // show the satisfaction prompt. Trigger when:
                //   - we previously saw an operator reply this session
                //   - is_claimed flipped from true → false
                //   - we haven't already submitted / dismissed
                if (
                    current.sawOperatorReply &&
                    current.isHumanHandling &&
                    !out.is_claimed &&
                    !current.ratingSubmitted &&
                    !current.ratingPromptVisible
                ) {
                    handoffPatch.ratingPromptVisible = true;
                }

                if (Object.keys(handoffPatch).length > 0) {
                    store.set(handoffPatch);
                }

                for (const m of out.messages) {
                    store.addMessage({
                        id: m.id,
                        role: 'human-agent',
                        content: m.content,
                    });
                    lastHumanPollCursorRef.current = m.id;

                    // Phase 4: stamp "I've seen at least one human
                    // reply this session" so the rating prompt
                    // becomes eligible to fire on operator release.
                    if (!store.get().sawOperatorReply) {
                        store.set({ sawOperatorReply: true });
                    }
                }
            } catch {
                // best-effort
            }
        };

        const interval = window.setInterval(tick, 3000);

        // Fire once immediately on bar-open.
        tick();

        return () => {
            stopped = true;
            window.clearInterval(interval);
        };
    }, [api, state.open, state.init?.jwt]);

    if (!agentId) {
        return null;
    }

    // Path-restricted by admin → render nothing. The init has already
    // happened (one HTTP hit, irreducible) but no UI mounts and no
    // background work runs.
    if (state.restricted) {
        return null;
    }

    return <Bar api={api} state={state} error={error} demo={demo} />;
}
