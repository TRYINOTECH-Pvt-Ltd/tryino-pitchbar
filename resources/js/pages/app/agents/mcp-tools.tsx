import { Head, Link, router } from '@inertiajs/react';
import {
    ArrowLeft,
    Code2,
    Loader2,
    Save,
    Search,
    ShieldAlert,
    ToyBrick,
} from 'lucide-react';
import { useMemo, useState } from 'react';
import { useConfirm } from '@/components/confirm-dialog-provider';
import { EmptyState } from '@/components/empty-state';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { index as agentsIndex, show as showAgent } from '@/routes/agents';
import type { BreadcrumbItem } from '@/types';
import {
    index as agentMcpIndex,
    refresh as refreshMcpServer,
    tools as agentMcpTools,
} from '@/routes/agents/mcp';
import { bulk as agentMcpToolsBulk } from '@/routes/agents/mcp/tools';

type Tool = {
    id: string;
    name: string;
    namespaced_name: string;
    description: string | null;
    is_destructive: boolean;
    is_idempotent: boolean;
    input_schema: Record<string, unknown>;
    enabled: boolean;
};

type Props = {
    agent: { id: string; name: string };
    server: { id: string; label: string; status: string };
    tools: Tool[];
};

export default function McpTools({ agent, server, tools }: Props) {
    const { t } = useT();
    const confirm = useConfirm();
    const [state, setState] = useState<Record<string, boolean>>(() =>
        Object.fromEntries(tools.map((tool) => [tool.id, tool.enabled])),
    );
    const [busy, setBusy] = useState(false);
    const [query, setQuery] = useState('');
    const [filter, setFilter] = useState<'all' | 'enabled' | 'destructive'>(
        'all',
    );

    const dirty = useMemo(
        () => tools.some((tool) => state[tool.id] !== tool.enabled),
        [tools, state],
    );

    const counts = useMemo(() => {
        let enabled = 0;
        let destructive = 0;
        let destructiveEnabled = 0;

        for (const tool of tools) {
            if (state[tool.id]) {
                enabled++;
            }

            if (tool.is_destructive) {
                destructive++;

                if (state[tool.id]) {
                    destructiveEnabled++;
                }
            }
        }

        return {
            enabled,
            total: tools.length,
            destructive,
            destructiveEnabled,
        };
    }, [tools, state]);

    const filtered = useMemo(() => {
        const q = query.trim().toLowerCase();

        return tools.filter((tool) => {
            if (filter === 'enabled' && !state[tool.id]) {
                return false;
            }

            if (filter === 'destructive' && !tool.is_destructive) {
                return false;
            }

            if (!q) {
                return true;
            }

            return (
                tool.namespaced_name.toLowerCase().includes(q) ||
                (tool.description ?? '').toLowerCase().includes(q)
            );
        });
    }, [tools, query, filter, state]);

    const toggle = async (tool: Tool) => {
        if (!state[tool.id] && tool.is_destructive) {
            const ok = await confirm({
                title: t('Enable destructive tool?'),
                message: t(
                    '[:name] can modify the external system. Enabling it lets the agent write data based on visitor messages.',
                    { name: tool.namespaced_name },
                ),
                confirmLabel: t('Enable'),
                danger: true,
            });

            if (!ok) {
                return;
            }
        }

        setState((prev) => ({ ...prev, [tool.id]: !prev[tool.id] }));
    };

    const enableAll = (only?: 'safe') => {
        setState((prev) => {
            const next = { ...prev };

            for (const tool of tools) {
                if (only === 'safe' && tool.is_destructive) {
                    continue;
                }

                next[tool.id] = true;
            }

            return next;
        });
    };

    const disableAll = () => {
        setState(Object.fromEntries(tools.map((tool) => [tool.id, false])));
    };

    const reset = () => {
        setState(
            Object.fromEntries(tools.map((tool) => [tool.id, tool.enabled])),
        );
    };

    const save = () => {
        setBusy(true);
        router.patch(
            agentMcpToolsBulk({ agent: agent.id, mcpServer: server.id }).url,
            {
                grants: tools.map((tool) => ({
                    tool_id: tool.id,
                    enabled: state[tool.id] ?? false,
                })),
            },
            {
                preserveScroll: true,
                onFinish: () => setBusy(false),
            },
        );
    };

    const refresh = () => {
        router.post(
            refreshMcpServer({ agent: agent.id, mcpServer: server.id }).url,
            {},
            { preserveScroll: true },
        );
    };

    const breadcrumbs: BreadcrumbItem[] = [
        { title: t('Agents'), href: agentsIndex.url() },
        { title: agent.name, href: showAgent({ agent: agent.id }).url },
        {
            title: t('MCP integrations'),
            href: agentMcpIndex({ agent: agent.id }).url,
        },
        {
            title: server.label,
            href: agentMcpTools({ agent: agent.id, mcpServer: server.id }).url,
        },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`${server.label} · ${t('Tools')}`} />
            <div className="mx-auto w-full max-w-5xl space-y-5 p-4 sm:p-6">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div className="min-w-0">
                        <Button
                            variant="ghost"
                            size="sm"
                            asChild
                            className="-ms-2 mb-2"
                        >
                            <Link href={agentMcpIndex({ agent: agent.id }).url}>
                                <ArrowLeft className="me-1.5 size-4" />
                                {t('Back to MCP servers')}
                            </Link>
                        </Button>
                        <h1 className="text-2xl font-semibold">
                            {server.label} · {t('Tools')}
                        </h1>
                        <p className="mt-1 max-w-2xl text-sm text-muted-foreground">
                            {t(
                                'Enable only the tools you want this agent to call. Destructive tools require explicit confirmation. Schema changes auto-disable a tool so you re-approve the new shape.',
                            )}
                        </p>
                    </div>
                </div>

                {tools.length > 0 && (
                    <Card>
                        <CardContent className="flex flex-wrap items-center justify-between gap-3 p-3">
                            <div className="flex flex-wrap items-center gap-3 text-xs">
                                <span>
                                    <strong className="font-semibold text-foreground tabular-nums">
                                        {counts.enabled}
                                    </strong>
                                    {' / '}
                                    {counts.total} {t('enabled')}
                                </span>
                                <span className="text-muted-foreground">
                                    {counts.destructive > 0 && (
                                        <>
                                            <span className="text-red-600 dark:text-red-400">
                                                {counts.destructiveEnabled}
                                            </span>
                                            {' / '}
                                            {counts.destructive}{' '}
                                            {t('destructive')}
                                        </>
                                    )}
                                </span>
                            </div>
                            <div className="flex flex-wrap items-center gap-2">
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    onClick={() => enableAll('safe')}
                                >
                                    {t('Enable safe')}
                                </Button>
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    onClick={disableAll}
                                >
                                    {t('Disable all')}
                                </Button>
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    onClick={refresh}
                                >
                                    {t('Refresh catalogue')}
                                </Button>
                            </div>
                        </CardContent>
                    </Card>
                )}

                {tools.length > 0 && (
                    <Card>
                        <CardContent className="flex flex-wrap items-center gap-2 p-3">
                            <div className="relative min-w-[200px] flex-1">
                                <Search className="pointer-events-none absolute start-2.5 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
                                <Input
                                    value={query}
                                    onChange={(e) => setQuery(e.target.value)}
                                    placeholder={t('Search tools…')}
                                    className="ps-9"
                                />
                            </div>
                            <div className="flex gap-1 rounded-md border bg-muted/30 p-0.5">
                                {(
                                    ['all', 'enabled', 'destructive'] as const
                                ).map((f) => (
                                    <button
                                        key={f}
                                        type="button"
                                        onClick={() => setFilter(f)}
                                        className={cn(
                                            'rounded px-2.5 py-1 text-xs font-medium transition',
                                            filter === f
                                                ? 'bg-background text-foreground shadow-sm'
                                                : 'text-muted-foreground hover:text-foreground',
                                        )}
                                    >
                                        {f === 'all'
                                            ? t('All')
                                            : f === 'enabled'
                                              ? t('Enabled')
                                              : t('Destructive')}
                                    </button>
                                ))}
                            </div>
                        </CardContent>
                    </Card>
                )}

                {tools.length === 0 ? (
                    <EmptyState
                        icon={ToyBrick}
                        title={t('No tools discovered yet')}
                        description={t(
                            'The server returned an empty tools/list. Use Refresh catalogue to re-discover, or check that the server actually exposes any tools.',
                        )}
                        action={{
                            label: t('Refresh catalogue'),
                            onClick: refresh,
                        }}
                    />
                ) : filtered.length === 0 ? (
                    <EmptyState
                        icon={Search}
                        title={t('No tools match your filter')}
                        description={t(
                            'Try clearing the search box or switching filters.',
                        )}
                    />
                ) : (
                    <div className="space-y-2">
                        {filtered.map((tool) => {
                            const enabled = state[tool.id] ?? false;
                            const checkboxId = `mcp-tool-${tool.id}`;

                            return (
                                <Card
                                    key={tool.id}
                                    className={cn(
                                        'overflow-hidden transition',
                                        enabled
                                            ? 'border-foreground/15'
                                            : 'border-border',
                                    )}
                                >
                                    <CardContent className="p-4">
                                        <div className="flex items-start gap-3">
                                            <Checkbox
                                                id={checkboxId}
                                                checked={enabled}
                                                onCheckedChange={() =>
                                                    toggle(tool)
                                                }
                                                className="mt-1"
                                            />
                                            <div className="min-w-0 flex-1">
                                                <div className="flex flex-wrap items-center gap-2">
                                                    <label
                                                        htmlFor={checkboxId}
                                                        className="cursor-pointer font-mono text-sm font-semibold"
                                                    >
                                                        {tool.namespaced_name}
                                                    </label>
                                                    {tool.is_destructive && (
                                                        <span className="inline-flex items-center gap-1 rounded-full border border-red-300/50 bg-red-50 px-2 py-0.5 text-[10px] font-medium text-red-700 dark:border-red-500/40 dark:bg-red-500/10 dark:text-red-300">
                                                            <ShieldAlert className="size-3" />
                                                            {t('Destructive')}
                                                        </span>
                                                    )}
                                                    {tool.is_idempotent && (
                                                        <span className="rounded-full border bg-muted/50 px-2 py-0.5 text-[10px] font-medium text-muted-foreground">
                                                            {t('Idempotent')}
                                                        </span>
                                                    )}
                                                </div>
                                                {tool.description && (
                                                    <p className="mt-1 text-xs leading-5 text-muted-foreground">
                                                        {tool.description}
                                                    </p>
                                                )}
                                                <details className="group mt-2">
                                                    <summary className="inline-flex cursor-pointer items-center gap-1 text-[11px] text-muted-foreground hover:text-foreground">
                                                        <Code2 className="size-3" />
                                                        {t('Input schema')}
                                                    </summary>
                                                    <pre className="mt-2 max-h-64 overflow-auto rounded bg-muted p-2 text-[10px] leading-snug">
                                                        {JSON.stringify(
                                                            tool.input_schema,
                                                            null,
                                                            2,
                                                        )}
                                                    </pre>
                                                </details>
                                            </div>
                                        </div>
                                    </CardContent>
                                </Card>
                            );
                        })}
                    </div>
                )}

                {dirty && (
                    <div
                        className="sticky bottom-4 mt-4 flex flex-wrap items-center justify-between gap-3 rounded-lg border border-amber-300/50 bg-amber-50/95 p-3 shadow-lg backdrop-blur dark:border-amber-500/30 dark:bg-amber-500/10"
                        role="status"
                    >
                        <p className="text-xs font-medium text-amber-900 dark:text-amber-200">
                            {t(
                                'You have unsaved grant changes. The agent keeps the previous configuration until you save.',
                            )}
                        </p>
                        <div className="flex items-center gap-2">
                            <Button
                                type="button"
                                variant="ghost"
                                size="sm"
                                onClick={reset}
                                disabled={busy}
                            >
                                {t('Discard')}
                            </Button>
                            <Button
                                type="button"
                                size="sm"
                                onClick={save}
                                disabled={busy}
                            >
                                {busy ? (
                                    <Loader2 className="me-1.5 size-4 animate-spin" />
                                ) : (
                                    <Save className="me-1.5 size-4" />
                                )}
                                {t('Save changes')}
                            </Button>
                        </div>
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
