import { useCallback, useEffect, useRef, useState } from 'react';

type Message = {
    role: 'user' | 'assistant';
    text: string;
};

type Props = {
    agentId: string | null;
    title: string;
    badge: string;
    placeholder: string;
    starters: string[];
    seedQuestion: string;
    seedAnswer: string;
    offlineMessage: string;
};

type InitResponse = {
    data: {
        conversation_id: string;
        jwt: string;
        agent: { name?: string };
    };
};

export default function InteractiveChat({
    agentId,
    title,
    badge,
    placeholder,
    starters,
    seedQuestion,
    seedAnswer,
    offlineMessage,
}: Props) {
    const [jwt, setJwt] = useState<string | null>(null);
    const [conversationId, setConversationId] = useState<string | null>(null);
    const [messages, setMessages] = useState<Message[]>([
        { role: 'user', text: seedQuestion },
        { role: 'assistant', text: seedAnswer },
    ]);
    const [input, setInput] = useState('');
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const bodyRef = useRef<HTMLDivElement | null>(null);
    const initStarted = useRef(false);

    useEffect(() => {
        if (bodyRef.current) {
            bodyRef.current.scrollTop = bodyRef.current.scrollHeight;
        }
    }, [messages, busy]);

    const ensureSession = useCallback(async () => {
        if (jwt && conversationId) {
            return { jwt, conversationId };
        }

        if (!agentId) {
            throw new Error('no_agent');
        }

        const anonKey = 'aurora-anon';
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
            const aId =
                payload.data &&
                (payload.data as { anonymous_id?: string }).anonymous_id;

            if (aId) {
                window.localStorage.setItem(anonKey, aId);
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
    }, [agentId, conversationId, jwt]);

    const streamReply = useCallback(
        async (token: string, _conv: string, prompt: string) => {
            const response = await fetch('/api/v1/widget/messages/stream', {
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
        [],
    );

    const send = useCallback(
        async (text?: string) => {
            const trimmed = (text ?? input).trim();

            if (!trimmed || busy) {
                return;
            }

            setError(null);
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
        if (!agentId || initStarted.current) {
            return;
        }

        initStarted.current = true;
        ensureSession().catch(() => {
            // silent — surface error when visitor actually submits
        });
    }, [agentId, ensureSession]);

    return (
        <div className="demo">
            <div className="demo-tag">{badge.toUpperCase()}</div>
            <div className="demo-head">
                <div className="left">
                    <div className="dots">
                        <span />
                        <span />
                        <span />
                    </div>
                    <span>{title.toUpperCase()}</span>
                </div>
                <div className="live">
                    <span className="pulse" />
                    {agentId ? 'SESSION OPEN' : 'PREVIEW'}
                </div>
            </div>
            <div className="demo-body" ref={bodyRef}>
                {messages.map((m, i) => (
                    <div
                        key={i}
                        className={`msg ${m.role === 'user' ? 'you' : 'bot'}`}
                    >
                        <div className="msg-content">
                            <div className="who">
                                {m.role === 'user' ? 'You' : title}
                            </div>
                            <div className="bubble">{m.text || '…'}</div>
                        </div>
                    </div>
                ))}
                {busy && (
                    <div className="msg bot">
                        <div className="msg-content">
                            <div className="who">{title}</div>
                            <div className="bubble">
                                <div className="typing">
                                    <span />
                                    <span />
                                    <span />
                                </div>
                            </div>
                        </div>
                    </div>
                )}
            </div>
            <div className="demo-suggest">
                {starters.slice(0, 4).map((s) => (
                    <button
                        key={s}
                        type="button"
                        onClick={() => send(s)}
                        disabled={busy || !agentId}
                    >
                        {s}
                    </button>
                ))}
            </div>
            <form
                className="demo-input"
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
                    disabled={busy || !agentId}
                />
                <button type="submit" disabled={busy || !agentId}>
                    {busy ? 'Thinking…' : 'Send →'}
                </button>
            </form>
            {error ? (
                <div
                    style={{
                        padding: '8px 14px',
                        borderTop: '1px solid var(--hairline)',
                        fontFamily: 'var(--mono)',
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
        return { name, data: JSON.parse(joined) as Record<string, unknown> };
    } catch {
        return { name, data: { text: joined } };
    }
}
