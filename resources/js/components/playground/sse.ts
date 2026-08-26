/**
 * Tiny SSE-line buffer + dispatcher for admin-side streams.
 *
 * Mirrors the shape of `resources/widget/src/core/api.ts::streamMessage`
 * but tuned for admin auth (session cookie, CSRF header) and a richer
 * event vocabulary (`retrieval`, `prompt` debug events alongside
 * `start/token/block/tool_call/done`).
 *
 * Kept deliberately small — the playground page composes this rather
 * than importing the widget's bundled fetch client (which assumes a
 * widget JWT and a shared baseUrl).
 */

export type SseHandlers = {
    onStart?: (payload: {
        conversation_id: string;
        message_id: string;
    }) => void;
    onToken?: (text: string) => void;
    onBlock?: (block: {
        type: string;
        payload: Record<string, unknown>;
    }) => void;
    onToolCall?: (event: {
        name: string;
        args: Record<string, unknown>;
    }) => void;
    onRetrieval?: (payload: {
        sources: {
            url: string | null;
            score: number;
            rerank_score: number | null;
            snippet: string;
        }[];
        confidence: number;
        threshold: number;
        page_url_used: string | null;
    }) => void;
    onPrompt?: (payload: {
        system: string;
        history_count: number;
        vertical: string | null;
        language: string | null;
    }) => void;
    onDone?: (payload: {
        text: string;
        citations: { id: number; url: string | null }[];
        low_confidence: boolean;
        confidence?: number;
        latency_ms: number;
        curated?: boolean;
    }) => void;
    onError?: (err: { code: string; message?: string }) => void;
};

export async function consumePlaygroundStream(
    url: string,
    body: Record<string, unknown>,
    handlers: SseHandlers,
    signal?: AbortSignal,
): Promise<void> {
    const csrf =
        (
            document.querySelector(
                'meta[name="csrf-token"]',
            ) as HTMLMetaElement | null
        )?.content ?? '';

    let response: Response;

    try {
        response = await fetch(url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'text/event-stream',
                'X-CSRF-TOKEN': csrf,
                'X-Requested-With': 'XMLHttpRequest',
            },
            credentials: 'same-origin',
            body: JSON.stringify(body),
            signal,
        });
    } catch (err) {
        handlers.onError?.({
            code: 'network_failed',
            message: err instanceof Error ? err.message : 'Network error',
        });

        return;
    }

    if (!response.ok || !response.body) {
        let detail: string | undefined;

        try {
            const data = await response.clone().json();
            detail = data.message ?? data.error ?? undefined;
        } catch {
            // Body wasn't JSON; ignore.
        }

        handlers.onError?.({
            code: response.status === 422 ? 'validation_failed' : 'http_failed',
            message: detail ?? `HTTP ${response.status}`,
        });

        return;
    }

    const reader = response.body.getReader();
    const decoder = new TextDecoder('utf-8');
    let buffer = '';
    let doneSeen = false;
    let errorSeen = false;

    const dispatch = (event: string, data: string) => {
        if (event === '') {
            return;
        }

        let payload: unknown;

        try {
            payload = JSON.parse(data);
        } catch {
            return;
        }

        const p = payload as Record<string, unknown>;

        switch (event) {
            case 'start':
                handlers.onStart?.(
                    p as { conversation_id: string; message_id: string },
                );
                break;
            case 'token':
                handlers.onToken?.((p.t as string) ?? '');
                break;
            case 'retrieval':
                handlers.onRetrieval?.(
                    p as Parameters<NonNullable<SseHandlers['onRetrieval']>>[0],
                );
                break;
            case 'prompt':
                handlers.onPrompt?.(
                    p as Parameters<NonNullable<SseHandlers['onPrompt']>>[0],
                );
                break;
            case 'block':
                handlers.onBlock?.(
                    p as { type: string; payload: Record<string, unknown> },
                );
                break;
            case 'tool_call':
                handlers.onToolCall?.(
                    p as { name: string; args: Record<string, unknown> },
                );
                break;
            case 'done':
                doneSeen = true;
                handlers.onDone?.(
                    p as Parameters<NonNullable<SseHandlers['onDone']>>[0],
                );
                break;
            case 'error':
                errorSeen = true;
                handlers.onError?.(p as { code: string; message?: string });
                break;
        }
    };

    try {
        for (;;) {
            const { value, done } = await reader.read();

            if (done) {
                break;
            }

            buffer += decoder.decode(value, { stream: true });

            let boundary = buffer.indexOf('\n\n');

            while (boundary !== -1) {
                const block = buffer.slice(0, boundary);
                buffer = buffer.slice(boundary + 2);
                let eventName = '';
                let dataLines = '';

                for (const line of block.split('\n')) {
                    if (line.startsWith(':')) {
                        // Heartbeat comment, ignore.
                    } else if (line.startsWith('event:')) {
                        eventName = line.slice(6).trim();
                    } else if (line.startsWith('data:')) {
                        dataLines += line.slice(5).trim();
                    }
                }

                if (eventName && dataLines) {
                    dispatch(eventName, dataLines);
                }

                boundary = buffer.indexOf('\n\n');
            }
        }
    } catch (err) {
        if ((err as Error).name === 'AbortError') {
            return;
        }

        handlers.onError?.({
            code: 'stream_aborted',
            message: err instanceof Error ? err.message : 'Stream interrupted.',
        });

        return;
    }

    if (!doneSeen && !errorSeen) {
        handlers.onError?.({
            code: 'stream_ended_without_done',
            message: 'Server closed the stream without a done event.',
        });
    }
}
