import { Activity, Clock, Database, FileText, Wrench } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { useT } from '@/lib/i18n';

export type RetrievalSource = {
    url: string | null;
    score: number;
    rerank_score: number | null;
    snippet: string;
};

export type ToolInvocation = {
    name: string;
    args: Record<string, unknown>;
    ts: number;
};

export type DiagnosticsState = {
    sources: RetrievalSource[];
    confidence: number | null;
    threshold: number | null;
    pageUrlUsed: string | null;
    systemPrompt: string;
    historyCount: number;
    vertical: string | null;
    language: string | null;
    toolCalls: ToolInvocation[];
    blocks: { type: string }[];
    firstTokenMs: number | null;
    totalMs: number | null;
};

type Props = {
    state: DiagnosticsState;
};

export function DiagnosticsPanel({ state }: Props) {
    const { t } = useT();
    const hasData =
        state.sources.length > 0 ||
        state.systemPrompt.length > 0 ||
        state.totalMs !== null;

    if (!hasData) {
        return (
            <div className="flex flex-col gap-3 rounded-lg border border-dashed p-4">
                <div className="flex items-center gap-2 text-sm font-medium">
                    <Activity className="h-4 w-4 text-muted-foreground" />
                    {t('Diagnostics')}
                </div>
                <p className="text-xs leading-relaxed text-muted-foreground">
                    {t(
                        'After your first turn, this tab shows everything that shaped the reply:',
                    )}
                </p>
                <ul className="ms-4 flex list-disc flex-col gap-0.5 text-xs text-muted-foreground">
                    <li>{t('First-token + total latency')}</li>
                    <li>{t('Retrieved chunks with ANN and rerank scores')}</li>
                    <li>{t('The exact system prompt used')}</li>
                    <li>{t('Tool calls fired during the turn')}</li>
                    <li>{t('Inline blocks the LLM emitted')}</li>
                </ul>
            </div>
        );
    }

    return (
        <div className="flex flex-col gap-5">
            <Section
                icon={<Clock className="h-3.5 w-3.5" />}
                title={t('Latency')}
            >
                <dl className="grid grid-cols-2 gap-x-4 gap-y-1 text-xs">
                    <dt className="text-muted-foreground">
                        {t('First token')}
                    </dt>
                    <dd className="font-mono">
                        {state.firstTokenMs !== null
                            ? `${state.firstTokenMs} ms`
                            : '—'}
                    </dd>
                    <dt className="text-muted-foreground">{t('Total')}</dt>
                    <dd className="font-mono">
                        {state.totalMs !== null ? `${state.totalMs} ms` : '—'}
                    </dd>
                    <dt className="text-muted-foreground">{t('Confidence')}</dt>
                    <dd className="font-mono">
                        {state.confidence !== null
                            ? state.confidence.toFixed(3)
                            : '—'}
                    </dd>
                    <dt className="text-muted-foreground">{t('Threshold')}</dt>
                    <dd className="font-mono">
                        {state.threshold !== null
                            ? state.threshold.toFixed(2)
                            : '—'}
                    </dd>
                </dl>
            </Section>

            <Section
                icon={<Database className="h-3.5 w-3.5" />}
                title={t('Retrieved sources (:count)', {
                    count: state.sources.length,
                })}
            >
                {state.sources.length === 0 ? (
                    <p className="text-xs text-muted-foreground">
                        {t('No chunks retrieved.')}
                    </p>
                ) : (
                    <ol className="flex flex-col gap-2">
                        {state.sources.map((source, i) => (
                            <li
                                key={i}
                                className="rounded-md border bg-muted/40 p-2 text-xs"
                            >
                                <div className="flex items-baseline justify-between gap-2">
                                    <span className="font-mono text-muted-foreground">
                                        [{i + 1}]
                                    </span>
                                    <div className="flex items-center gap-1">
                                        <Badge
                                            variant="outline"
                                            className="font-mono text-[10px]"
                                        >
                                            ann {source.score.toFixed(3)}
                                        </Badge>
                                        {source.rerank_score !== null && (
                                            <Badge
                                                variant="outline"
                                                className="font-mono text-[10px]"
                                            >
                                                rerank{' '}
                                                {source.rerank_score.toFixed(3)}
                                            </Badge>
                                        )}
                                    </div>
                                </div>
                                {source.url && (
                                    <a
                                        href={source.url}
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        className="mt-1 line-clamp-1 text-primary underline-offset-2 hover:underline"
                                    >
                                        {source.url}
                                    </a>
                                )}
                                <p className="mt-1 line-clamp-3 leading-snug text-muted-foreground">
                                    {source.snippet}
                                </p>
                            </li>
                        ))}
                    </ol>
                )}
            </Section>

            {state.toolCalls.length > 0 && (
                <Section
                    icon={<Wrench className="h-3.5 w-3.5" />}
                    title={t('Tool calls (:count)', {
                        count: state.toolCalls.length,
                    })}
                >
                    <ol className="flex flex-col gap-1.5">
                        {state.toolCalls.map((call, i) => (
                            <li
                                key={i}
                                className="rounded-md border bg-muted/40 p-2 text-xs"
                            >
                                <div className="font-semibold">{call.name}</div>
                                <pre className="mt-1 overflow-x-auto rounded bg-background p-1.5 font-mono text-[11px]">
                                    {JSON.stringify(call.args, null, 2)}
                                </pre>
                            </li>
                        ))}
                    </ol>
                </Section>
            )}

            {state.blocks.length > 0 && (
                <Section
                    icon={<Activity className="h-3.5 w-3.5" />}
                    title={t('Inline blocks (:count)', {
                        count: state.blocks.length,
                    })}
                >
                    <div className="flex flex-wrap gap-1">
                        {state.blocks.map((block, i) => (
                            <Badge
                                key={i}
                                variant="secondary"
                                className="font-mono text-[10px]"
                            >
                                {block.type}
                            </Badge>
                        ))}
                    </div>
                </Section>
            )}

            <Section
                icon={<FileText className="h-3.5 w-3.5" />}
                title={t('System prompt')}
            >
                <details className="text-xs">
                    <summary className="cursor-pointer text-muted-foreground hover:text-foreground">
                        {t(':count characters · expand', {
                            count: state.systemPrompt.length.toLocaleString(),
                        })}
                    </summary>
                    <pre className="mt-2 max-h-72 overflow-auto rounded-md border bg-muted/30 p-2 font-mono text-[11px] leading-relaxed whitespace-pre-wrap">
                        {state.systemPrompt}
                    </pre>
                </details>
                <dl className="mt-2 grid grid-cols-2 gap-x-4 gap-y-1 text-xs">
                    <dt className="text-muted-foreground">
                        {t('Vertical applied')}
                    </dt>
                    <dd className="font-mono">{state.vertical ?? t('none')}</dd>
                    <dt className="text-muted-foreground">{t('Language')}</dt>
                    <dd className="font-mono">{state.language ?? t('auto')}</dd>
                    <dt className="text-muted-foreground">
                        {t('History turns')}
                    </dt>
                    <dd className="font-mono">{state.historyCount}</dd>
                </dl>
            </Section>
        </div>
    );
}

function Section({
    icon,
    title,
    children,
}: {
    icon: React.ReactNode;
    title: string;
    children: React.ReactNode;
}) {
    return (
        <section className="flex flex-col gap-2">
            <h3 className="flex items-center gap-1.5 text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                {icon}
                {title}
            </h3>
            {children}
        </section>
    );
}

export const EMPTY_DIAGNOSTICS: DiagnosticsState = {
    sources: [],
    confidence: null,
    threshold: null,
    pageUrlUsed: null,
    systemPrompt: '',
    historyCount: 0,
    vertical: null,
    language: null,
    toolCalls: [],
    blocks: [],
    firstTokenMs: null,
    totalMs: null,
};
