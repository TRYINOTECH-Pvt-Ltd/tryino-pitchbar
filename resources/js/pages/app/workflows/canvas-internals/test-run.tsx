import {
    GitBranch,
    Play,
    RotateCcw,
    Tag,
    UserCheck,
    Webhook,
} from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { useT } from '@/lib/i18n';
import { simulate as workflowsSimulate } from '@/routes/workflows';
import type { Step } from './types';

type SimulationEvent = {
    step: number;
    type: string;
    text?: string;
    var?: string;
    tags?: string[];
    url?: string;
    method?: string;
    matched_case?: number | 'default' | null;
    go_to?: number;
};

type SimulationResult = {
    triggered: boolean;
    status: string;
    events: SimulationEvent[];
    vars: Record<string, unknown>;
    waiting_var: string | null;
};

export type TestRunPayload = {
    keywords: string[];
    match_mode: 'any' | 'all' | 'exact';
    steps: Step[];
    stepOrder: string[];
};

function csrf(): string {
    return (
        (
            document.querySelector(
                'meta[name="csrf-token"]',
            ) as HTMLMetaElement | null
        )?.content ?? ''
    );
}

/**
 * Right-panel "Test run" — dry-runs the CURRENT canvas (saved or not)
 * against the stateless simulate endpoint and renders the trace as a
 * mini chat transcript. Question steps pause exactly like the live
 * engine; typing a reply appends to messages[] and re-runs the whole
 * simulation (stateless by design — no run rows are ever created).
 */
export function TestRunPanel({
    buildPayload,
    onTraceNodes,
}: {
    buildPayload: () => TestRunPayload;
    onTraceNodes: (nodeIds: string[]) => void;
}) {
    const { t } = useT();
    const [messages, setMessages] = useState<string[]>([]);
    const [input, setInput] = useState('');
    const [result, setResult] = useState<SimulationResult | null>(null);
    const [running, setRunning] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const run = async (allMessages: string[]) => {
        const payload = buildPayload();

        if (payload.steps.length === 0) {
            setError(t('Wire at least one step to the trigger first.'));

            return;
        }

        setRunning(true);
        setError(null);

        try {
            const res = await fetch(workflowsSimulate().url, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': csrf(),
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({
                    keywords: payload.keywords,
                    match_mode: payload.match_mode,
                    steps: payload.steps,
                    messages: allMessages,
                }),
            });

            if (!res.ok) {
                throw new Error(`HTTP ${res.status}`);
            }

            const json = (await res.json()) as { data: SimulationResult };
            setMessages(allMessages);
            setResult(json.data);
            onTraceNodes(
                json.data.events
                    .map((e) => payload.stepOrder[e.step])
                    .filter((id): id is string => typeof id === 'string'),
            );
        } catch {
            setError(t('Test run failed — check the flow and try again.'));
        } finally {
            setRunning(false);
        }
    };

    const submit = () => {
        const value = input.trim();

        if (value === '' || running) {
            return;
        }

        setInput('');
        void run([...messages, value]);
    };

    const reset = () => {
        setMessages([]);
        setResult(null);
        setError(null);
        setInput('');
        onTraceNodes([]);
    };

    const waiting = result?.status === 'waiting_reply';

    return (
        <div className="flex h-full flex-col gap-3">
            <div className="flex items-center justify-between">
                <p className="text-xs font-bold tracking-wide text-muted-foreground uppercase">
                    {t('Test run')}
                </p>
                {(result !== null || messages.length > 0) && (
                    <button
                        type="button"
                        onClick={reset}
                        className="flex items-center gap-1 text-xs text-muted-foreground hover:text-foreground"
                    >
                        <RotateCcw className="size-3" />
                        {t('Reset')}
                    </button>
                )}
            </div>

            <p className="text-xs text-muted-foreground">
                {t(
                    'Dry-runs the flow exactly like the live engine — but nothing is saved, tagged, or sent.',
                )}
            </p>

            <div className="flex-1 space-y-2 overflow-y-auto rounded-md border bg-muted/20 p-2">
                {messages.map((m, i) => (
                    <div key={`m-${i}`} className="flex justify-end">
                        <span className="max-w-[90%] rounded-lg bg-primary px-2.5 py-1.5 text-xs text-primary-foreground">
                            {m}
                        </span>
                    </div>
                ))}

                {result !== null && !result.triggered && (
                    <p className="rounded-md border border-amber-300 bg-amber-50 p-2 text-xs text-amber-900 dark:border-amber-700 dark:bg-amber-900/30 dark:text-amber-200">
                        {t(
                            'The trigger did not fire — the message contains none of the keywords.',
                        )}
                    </p>
                )}

                {result?.events.map((event, i) => {
                    switch (event.type) {
                        case 'message':
                        case 'question':
                        case 'escalate':
                            return (
                                <div key={i} className="flex">
                                    <span className="max-w-[90%] rounded-lg border bg-card px-2.5 py-1.5 text-xs">
                                        {event.text}
                                    </span>
                                </div>
                            );
                        case 'reply':
                            return (
                                <div key={i} className="flex justify-end">
                                    <span className="max-w-[90%] rounded-lg bg-primary px-2.5 py-1.5 text-xs text-primary-foreground">
                                        {event.text}
                                    </span>
                                </div>
                            );
                        case 'branch':
                            return (
                                <p
                                    key={i}
                                    className="flex items-center gap-1 px-1 text-[11px] text-muted-foreground"
                                >
                                    <GitBranch className="size-3" />
                                    {event.matched_case === 'default'
                                        ? t('Branch took the default case')
                                        : event.matched_case === null
                                          ? t(
                                                'Branch fell through (no case matched)',
                                            )
                                          : t('Branch matched case :n', {
                                                n:
                                                    (event.matched_case ?? 0) +
                                                    1,
                                            })}
                                </p>
                            );
                        case 'tag_lead':
                            return (
                                <p
                                    key={i}
                                    className="flex items-center gap-1 px-1 text-[11px] text-muted-foreground"
                                >
                                    <Tag className="size-3" />
                                    {t(
                                        'Would tag the lead: :tags (not applied in test)',
                                        {
                                            tags: (event.tags ?? []).join(', '),
                                        },
                                    )}
                                </p>
                            );
                        case 'webhook':
                            return (
                                <p
                                    key={i}
                                    className="flex items-center gap-1 px-1 text-[11px] text-muted-foreground"
                                >
                                    <Webhook className="size-3" />
                                    {t('Would call :url (not sent in test)', {
                                        url: event.url ?? '',
                                    })}
                                </p>
                            );
                        case 'loop_guard':
                            return (
                                <p
                                    key={i}
                                    className="rounded-md border border-red-300 bg-red-50 p-2 text-xs text-red-900 dark:border-red-700 dark:bg-red-900/30 dark:text-red-200"
                                >
                                    {t(
                                        'Stopped: the branches loop forever (32-jump guard). Fix the wiring.',
                                    )}
                                </p>
                            );
                        default:
                            return null;
                    }
                })}

                {result?.status === 'escalated' && (
                    <p className="flex items-center gap-1 px-1 text-[11px] text-muted-foreground">
                        <UserCheck className="size-3" />
                        {t('Flow ends here — operators would be notified.')}
                    </p>
                )}

                {result?.status === 'completed' && (
                    <p className="px-1 text-[11px] text-muted-foreground">
                        {t('Flow completed.')}
                    </p>
                )}
            </div>

            {error !== null && (
                <p className="text-xs text-red-600 dark:text-red-400">
                    {error}
                </p>
            )}

            <div className="flex gap-2">
                <input
                    className="min-w-0 flex-1 rounded-md border bg-background px-2.5 py-1.5 text-xs"
                    placeholder={
                        waiting
                            ? t('Visitor reply to “:q”…', {
                                  q:
                                      result?.events
                                          .filter((e) => e.type === 'question')
                                          .at(-1)?.text ?? '',
                              })
                            : t('Type a visitor message…')
                    }
                    value={input}
                    onChange={(e) => setInput(e.target.value)}
                    onKeyDown={(e) => {
                        if (e.key === 'Enter') {
                            e.preventDefault();
                            submit();
                        }
                    }}
                />
                <Button
                    type="button"
                    size="sm"
                    onClick={submit}
                    disabled={running || input.trim() === ''}
                >
                    <Play className="me-1 size-3.5" />
                    {waiting ? t('Reply') : t('Run')}
                </Button>
            </div>
        </div>
    );
}
