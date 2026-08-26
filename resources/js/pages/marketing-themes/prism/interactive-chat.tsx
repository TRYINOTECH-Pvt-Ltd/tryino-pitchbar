import { useCallback, useEffect, useRef, useState } from 'react';

type Message = {
    role: 'user' | 'assistant';
    text: string;
};

type PriceMini = {
    plan: string;
    price: string;
    interval: string;
    features: string[];
    cta: string;
};

export type PrismChatHandle = {
    ask: (text: string) => void;
};

type Props = {
    agentId: string | null;
    title: string;
    placeholder: string;
    starters: string[];
    seedQuestion: string;
    seedAnswer: string;
    offlineMessage: string;
    priceCard: PriceMini | null;
    intent?: string | null;
    seedAsAssistantOnly?: boolean;
    handleRef?: React.MutableRefObject<PrismChatHandle | null>;
    /**
     * When set, the chat operates in "try-now" mode against a
     * cached page ingest instead of a published agent. Token comes
     * from POST /api/v1/widget/try-now. See app/Services/TryNow.
     */
    tryToken?: string | null;
    tryIntro?: string;
    tryStarters?: string[];
};

type InitResponse = {
    data: {
        conversation_id: string;
        jwt: string;
        anonymous_id?: string;
    };
};

export default function PrismChat({
    agentId,
    title,
    placeholder,
    starters,
    seedQuestion,
    seedAnswer,
    offlineMessage,
    priceCard,
    intent = null,
    seedAsAssistantOnly = false,
    handleRef,
    tryToken = null,
    tryIntro,
    tryStarters,
}: Props) {
    const [jwt, setJwt] = useState<string | null>(null);
    const [conversationId, setConversationId] = useState<string | null>(null);
    const [messages, setMessages] = useState<Message[]>(() => {
        if (tryToken && tryIntro) {
            return [{ role: 'assistant', text: tryIntro }];
        }

        return seedAsAssistantOnly
            ? [
                  {
                      role: 'assistant',
                      text: `${seedQuestion} ${seedAnswer}`.trim(),
                  },
              ]
            : [
                  { role: 'user', text: seedQuestion },
                  { role: 'assistant', text: seedAnswer },
              ];
    });
    const [input, setInput] = useState('');
    const [busy, setBusy] = useState(false);
    const [showPriceCard, setShowPriceCard] = useState(true);
    const [showIntent, setShowIntent] = useState(true);
    const [error, setError] = useState<string | null>(null);
    const bodyRef = useRef<HTMLDivElement | null>(null);
    const initStarted = useRef(false);

    useEffect(() => {
        if (bodyRef.current) {
            bodyRef.current.scrollTop = bodyRef.current.scrollHeight;
        }
    }, [messages, busy]);

    const ensureSession = useCallback(async () => {
        if (tryToken) {
            return { jwt: '', conversationId: '' };
        }

        if (jwt && conversationId) {
            return { jwt, conversationId };
        }

        if (!agentId) {
            throw new Error('no_agent');
        }

        const anonKey = 'prism-anon';
        let anonId: string | null = null;

        try {
            anonId = window.localStorage.getItem(anonKey);
        } catch {
            anonId = null;
        }

        const response = await fetch('/api/v1/widget/init', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
            },
            credentials: 'omit',
            body: JSON.stringify({
                agent_id: agentId,
                page_url: window.location.href,
                page_title: document.title,
                referrer: document.referrer || null,
                anon_id: anonId,
            }),
        });

        if (!response.ok) {
            throw new Error(`init_${response.status}`);
        }

        const payload = (await response.json()) as InitResponse;

        try {
            if (payload.data.anonymous_id) {
                window.localStorage.setItem(anonKey, payload.data.anonymous_id);
            }
        } catch {
            // ignore quota errors
        }

        setJwt(payload.data.jwt);
        setConversationId(payload.data.conversation_id);

        return {
            jwt: payload.data.jwt,
            conversationId: payload.data.conversation_id,
        };
    }, [agentId, conversationId, jwt, tryToken]);

    const messagesRef = useRef<Message[]>(messages);
    useEffect(() => {
        messagesRef.current = messages;
    }, [messages]);

    const streamReply = useCallback(
        async (token: string, _conv: string, prompt: string) => {
            const response = tryToken
                ? await fetch('/api/v1/widget/try-now/stream', {
                      method: 'POST',
                      headers: {
                          'Content-Type': 'application/json',
                          Accept: 'text/event-stream',
                      },
                      body: JSON.stringify({
                          token: tryToken,
                          message: prompt,
                          history: messagesRef.current
                              .slice(-10)
                              .map((m) => ({ role: m.role, text: m.text })),
                      }),
                  })
                : await fetch('/api/v1/widget/messages/stream', {
                      method: 'POST',
                      headers: {
                          'Content-Type': 'application/json',
                          Accept: 'text/event-stream',
                          Authorization: `Bearer ${token}`,
                      },
                      body: JSON.stringify({
                          message: prompt,
                          page_context: {
                              title: document.title,
                              url: window.location.href,
                          },
                      }),
                  });

            if (!response.ok || !response.body) {
                throw new Error(`stream_${response.status}`);
            }

            const reader = response.body.getReader();
            const decoder = new TextDecoder();
            let buffer = '';
            let assistant = '';
            setMessages((m) => [...m, { role: 'assistant', text: '' }]);

            const flush = () => {
                let idx;

                while ((idx = buffer.indexOf('\n\n')) !== -1) {
                    const raw = buffer.slice(0, idx);
                    buffer = buffer.slice(idx + 2);
                    const event = parseSseEvent(raw);

                    if (!event) {
                        continue;
                    }

                    if (event.name === 'token') {
                        const piece =
                            (typeof event.data?.t === 'string' &&
                                event.data.t) ||
                            (typeof event.data?.text === 'string' &&
                                event.data.text) ||
                            '';

                        if (piece) {
                            assistant += piece;
                            setMessages((m) => {
                                const next = [...m];
                                next[next.length - 1] = {
                                    role: 'assistant',
                                    text: assistant,
                                };

                                return next;
                            });
                        }
                    }

                    if (event.name === 'done') {
                        const finalText =
                            (typeof event.data?.text === 'string' &&
                                event.data.text) ||
                            '';

                        if (finalText) {
                            assistant = finalText;
                            setMessages((m) => {
                                const next = [...m];
                                next[next.length - 1] = {
                                    role: 'assistant',
                                    text: assistant,
                                };

                                return next;
                            });
                        }
                    }

                    if (event.name === 'error') {
                        const errText =
                            (typeof event.data?.message === 'string' &&
                                event.data.message) ||
                            'Stream error.';
                        assistant = errText;
                        setMessages((m) => {
                            const next = [...m];
                            next[next.length - 1] = {
                                role: 'assistant',
                                text: assistant,
                            };

                            return next;
                        });
                    }
                }
            };

            while (true) {
                const { value, done } = await reader.read();

                if (done) {
                    buffer += '\n\n';
                    flush();
                    break;
                }

                buffer += decoder.decode(value, { stream: true });
                flush();
            }
        },
        [tryToken],
    );

    const send = useCallback(
        async (text?: string) => {
            const trimmed = (text ?? input).trim();

            if (!trimmed || busy) {
                return;
            }

            setError(null);
            setShowPriceCard(false);
            setShowIntent(false);
            setMessages((m) => [...m, { role: 'user', text: trimmed }]);
            setInput('');
            setBusy(true);

            try {
                const session = await ensureSession();
                await streamReply(session.jwt, session.conversationId, trimmed);
            } catch {
                setError(offlineMessage);
                setMessages((m) => [
                    ...m,
                    { role: 'assistant', text: offlineMessage },
                ]);
            } finally {
                setBusy(false);
            }
        },
        [busy, ensureSession, input, offlineMessage, streamReply],
    );

    useEffect(() => {
        if (tryToken || !agentId || initStarted.current) {
            return;
        }

        initStarted.current = true;
        ensureSession().catch(() => {
            // silent — surface error when visitor actually submits
        });
    }, [agentId, ensureSession, tryToken]);

    useEffect(() => {
        if (!handleRef) {
            return;
        }

        handleRef.current = {
            ask: (text: string) => {
                void send(text);
            },
        };

        return () => {
            handleRef.current = null;
        };
    }, [handleRef, send]);

    return (
        <div
            className="chat"
            role="dialog"
            aria-label={`${title} conversation`}
        >
            <div className="chat-hd">
                <div className="chat-title">
                    <span className="dot" /> {title}
                </div>
                <span className="chat-live">
                    {tryToken ? 'Your site' : agentId ? 'Live' : 'Preview'}
                </span>
            </div>
            <div className="chat-body" ref={bodyRef}>
                {messages.map((m, i) => (
                    <div
                        key={i}
                        className={`bbl ${m.role === 'user' ? 'bbl-u' : 'bbl-b'}`}
                    >
                        {m.text || '…'}
                    </div>
                ))}
                {busy && (
                    <div className="bbl bbl-b">
                        <div className="typing">
                            <span />
                            <span />
                            <span />
                        </div>
                    </div>
                )}
                {showIntent && intent && (
                    <div className="intent-card">
                        <div className="intent-left">
                            <span className="intent-ic">
                                <svg
                                    width="14"
                                    height="14"
                                    viewBox="0 0 24 24"
                                    fill="none"
                                    stroke="currentColor"
                                    strokeWidth="2"
                                >
                                    <circle cx="12" cy="12" r="9" />
                                    <circle cx="12" cy="12" r="3" />
                                </svg>
                            </span>
                            <div>
                                <span className="intent-label">
                                    Detected intent:
                                </span>
                                <span className="intent-value">{intent}</span>
                            </div>
                        </div>
                        <div className="intent-right">
                            <span>
                                <svg
                                    width="12"
                                    height="12"
                                    viewBox="0 0 24 24"
                                    fill="none"
                                    stroke="currentColor"
                                    strokeWidth="2.5"
                                >
                                    <path d="M5 12l5 5L20 7" />
                                </svg>
                                High intent
                            </span>
                            <span>
                                <svg
                                    width="12"
                                    height="12"
                                    viewBox="0 0 24 24"
                                    fill="none"
                                    stroke="currentColor"
                                    strokeWidth="2.5"
                                >
                                    <path d="M5 12l5 5L20 7" />
                                </svg>
                                Active 2m 18s
                            </span>
                        </div>
                    </div>
                )}
                {showPriceCard && priceCard && (
                    <div className="price-mini price-mini-h">
                        <div className="price-mini-left">
                            <p className="price-mini-desc">
                                The {priceCard.plan} plan includes everything
                                you need to scale your business.
                            </p>
                            <ul>
                                {priceCard.features.map((f) => (
                                    <li key={f}>
                                        <svg
                                            width="16"
                                            height="16"
                                            viewBox="0 0 24 24"
                                            fill="none"
                                            stroke="currentColor"
                                            strokeWidth="2.5"
                                        >
                                            <circle
                                                cx="12"
                                                cy="12"
                                                r="11"
                                                fill="rgba(140,91,255,0.12)"
                                                stroke="none"
                                            />
                                            <path d="M7 12l3 3 7-7" />
                                        </svg>
                                        <span>{f}</span>
                                    </li>
                                ))}
                            </ul>
                        </div>
                        <div className="price-mini-right">
                            <div className="plan">{priceCard.plan}</div>
                            <div className="amt">
                                {priceCard.price}
                                <small>
                                    {priceCard.interval
                                        ? `/${priceCard.interval}`
                                        : ''}
                                </small>
                            </div>
                        </div>
                    </div>
                )}
            </div>
            {(() => {
                const activeStarters =
                    tryToken && tryStarters ? tryStarters : starters;
                const sendDisabled = busy || (!agentId && !tryToken);

                return activeStarters.length > 0 ? (
                    <div className="chat-suggest">
                        {activeStarters.slice(0, 4).map((s) => (
                            <button
                                key={s}
                                type="button"
                                onClick={() => send(s)}
                                disabled={sendDisabled}
                            >
                                {s}
                            </button>
                        ))}
                    </div>
                ) : null;
            })()}
            <form
                className="chat-form"
                onSubmit={(e) => {
                    e.preventDefault();
                    void send();
                }}
                aria-label={placeholder}
            >
                <input
                    placeholder={placeholder}
                    value={input}
                    onChange={(e) => setInput(e.target.value)}
                    disabled={busy || (!agentId && !tryToken)}
                />
                <button
                    type="submit"
                    disabled={busy || (!agentId && !tryToken)}
                    aria-label="Send"
                    className="chat-send"
                >
                    <svg
                        width="16"
                        height="16"
                        viewBox="0 0 24 24"
                        fill="none"
                        stroke="currentColor"
                        strokeWidth="2"
                        strokeLinecap="round"
                        strokeLinejoin="round"
                    >
                        <path d="M22 2L11 13" />
                        <path d="M22 2l-7 20-4-9-9-4z" />
                    </svg>
                </button>
            </form>
            {error ? (
                <div
                    style={{
                        padding: '8px 16px 12px',
                        fontSize: 11,
                        color: 'var(--muted)',
                    }}
                >
                    {error}
                </div>
            ) : null}
        </div>
    );
}

function parseSseEvent(
    raw: string,
): { name: string; data: Record<string, unknown> } | null {
    let name = 'message';
    const dataLines: string[] = [];

    for (const line of raw.split('\n')) {
        if (line.startsWith('event:')) {
            name = line.slice(6).trim();
        } else if (line.startsWith('data:')) {
            dataLines.push(line.slice(5).trim());
        }
    }

    if (dataLines.length === 0) {
        return { name, data: {} };
    }

    const joined = dataLines.join('\n');

    try {
        return {
            name,
            data: JSON.parse(joined) as Record<string, unknown>,
        };
    } catch {
        return { name, data: { text: joined } };
    }
}
