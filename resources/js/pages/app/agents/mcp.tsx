import { Head, Link, router, useForm } from '@inertiajs/react';
import {
    Activity,
    CheckCircle2,
    ExternalLink,
    Loader2,
    Plug,
    RefreshCw,
    TestTube2,
    TriangleAlert,
    Unplug,
} from 'lucide-react';
import { useState } from 'react';
import { useConfirm } from '@/components/confirm-dialog-provider';
import { EmptyState } from '@/components/empty-state';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { useT } from '@/lib/i18n';
import { relativeTime } from '@/lib/relative-time';
import { cn } from '@/lib/utils';
import { index as agentsIndex, show as showAgent } from '@/routes/agents';
import type { BreadcrumbItem } from '@/types';
import {
    activity as agentMcpActivity,
    destroy as destroyMcpServer,
    index as agentMcpIndex,
    refresh as refreshMcpServer,
    store as storeMcpServer,
    test as testMcpServer,
    tools as agentMcpTools,
} from '@/routes/agents/mcp';

type ServerStatus =
    | 'pending_auth'
    | 'active'
    | 'degraded'
    | 'disabled'
    | 'revoked';

type AuthType = 'none' | 'bearer' | 'oauth2_pkce';

type ServerRow = {
    id: string;
    label: string;
    server_url: string;
    auth_type: AuthType;
    status: ServerStatus;
    last_used_at: string | null;
    tools_synced_at: string | null;
    tools_sync_error: string | null;
    tools_count: number;
    granted_count: number;
    created_at: string | null;
    server_name?: string | null;
    server_version?: string | null;
};

type Props = {
    agent: { id: string; name: string };
    servers: ServerRow[];
};

const statusConfig: Record<
    ServerStatus,
    {
        labelKey: string;
        className: string;
        icon: typeof CheckCircle2;
        helpKey: string;
    }
> = {
    pending_auth: {
        labelKey: 'Pending auth',
        className:
            'bg-amber-500/15 text-amber-700 dark:text-amber-300 border-amber-300/50',
        icon: TriangleAlert,
        helpKey: 'OAuth flow not finished. Complete it to enable tools.',
    },
    active: {
        labelKey: 'Active',
        className:
            'bg-emerald-500/15 text-emerald-700 dark:text-emerald-300 border-emerald-300/50',
        icon: CheckCircle2,
        helpKey: 'Handshake succeeded and tools were discovered.',
    },
    degraded: {
        labelKey: 'Degraded',
        className:
            'bg-amber-500/15 text-amber-700 dark:text-amber-300 border-amber-300/50',
        icon: TriangleAlert,
        helpKey:
            'Recent calls failed. Hit Test to confirm the server is reachable.',
    },
    disabled: {
        labelKey: 'Disabled',
        className: 'bg-muted text-muted-foreground border-border',
        icon: Unplug,
        helpKey: 'Server attached but no tools enabled yet.',
    },
    revoked: {
        labelKey: 'Revoked',
        className:
            'bg-red-500/15 text-red-700 dark:text-red-300 border-red-300/50',
        icon: Unplug,
        helpKey:
            'Credentials were rejected. Re-attach the server with a fresh key.',
    },
};

function StatusBadge({ status }: { status: ServerStatus }) {
    const { t } = useT();
    const cfg = statusConfig[status];
    const Icon = cfg.icon;

    return (
        <span
            className={cn(
                'inline-flex items-center gap-1 rounded-full border px-2 py-0.5 text-[11px] font-medium',
                cfg.className,
            )}
            title={t(cfg.helpKey)}
        >
            <Icon className="size-3" />
            {t(cfg.labelKey)}
        </span>
    );
}

export default function McpServers({ agent, servers }: Props) {
    const { t } = useT();
    const confirm = useConfirm();
    const [pending, setPending] = useState<string | null>(null);

    const breadcrumbs: BreadcrumbItem[] = [
        { title: t('Agents'), href: agentsIndex.url() },
        { title: agent.name, href: showAgent({ agent: agent.id }).url },
        {
            title: t('MCP integrations'),
            href: agentMcpIndex({ agent: agent.id }).url,
        },
    ];

    const form = useForm<{
        label: string;
        server_url: string;
        auth_type: AuthType;
        api_key: string;
    }>({
        label: '',
        server_url: '',
        auth_type: 'bearer',
        api_key: '',
    });

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        form.post(storeMcpServer({ agent: agent.id }).url, {
            preserveScroll: true,
            onSuccess: () => form.reset(),
        });
    };

    const disconnect = async (server: ServerRow) => {
        const ok = await confirm({
            title: t('Disconnect [:label]?', { label: server.label }),
            message: t(
                'Removing this server disables every tool grant tied to it on this agent. Visitor messages that previously triggered these tools will fall back to plain RAG answers.',
            ),
            confirmLabel: t('Disconnect'),
            danger: true,
        });

        if (!ok) {
            return;
        }

        setPending(server.id);
        router.delete(
            destroyMcpServer({ agent: agent.id, mcpServer: server.id }).url,
            {
                preserveScroll: true,
                onFinish: () => setPending(null),
            },
        );
    };

    const testConnection = (server: ServerRow) => {
        setPending(server.id);
        router.post(
            testMcpServer({ agent: agent.id, mcpServer: server.id }).url,
            {},
            {
                preserveScroll: true,
                onFinish: () => setPending(null),
            },
        );
    };

    const refresh = (server: ServerRow) => {
        setPending(server.id);
        router.post(
            refreshMcpServer({ agent: agent.id, mcpServer: server.id }).url,
            {},
            {
                preserveScroll: true,
                onFinish: () => setPending(null),
            },
        );
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('MCP integrations')} />
            <div className="mx-auto w-full max-w-5xl space-y-6 p-4 sm:p-6">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div className="min-w-0">
                        <h1 className="text-2xl font-semibold">
                            {t('MCP integrations')}
                        </h1>
                        <p className="mt-1 max-w-2xl text-sm text-muted-foreground">
                            {t(
                                'Connect your agent to external tool servers (CRM, calendar, inventory, internal APIs) via the Model Context Protocol. The agent only calls tools you explicitly enable for it.',
                            )}
                        </p>
                    </div>
                    <Button variant="outline" size="sm" asChild>
                        <a
                            href="https://modelcontextprotocol.io/docs"
                            target="_blank"
                            rel="noreferrer noopener"
                        >
                            <ExternalLink className="me-1.5 size-4" />
                            {t('MCP docs')}
                        </a>
                    </Button>
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">
                            {t('Attach a new MCP server')}
                        </CardTitle>
                        <CardDescription>
                            {t(
                                'Point us at an HTTPS endpoint that speaks the Streamable HTTP transport. We discover tools immediately after attaching — none are enabled until you whitelist them.',
                            )}
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <form onSubmit={submit} className="space-y-4">
                            <div className="grid gap-4 sm:grid-cols-2">
                                <div className="space-y-1.5">
                                    <Label htmlFor="label">{t('Label')}</Label>
                                    <Input
                                        id="label"
                                        value={form.data.label}
                                        onChange={(e) =>
                                            form.setData(
                                                'label',
                                                e.target.value,
                                            )
                                        }
                                        placeholder={t(
                                            'e.g. Linear, HubSpot CRM',
                                        )}
                                        required
                                        autoFocus
                                    />
                                    <InputError message={form.errors.label} />
                                </div>
                                <div className="space-y-1.5">
                                    <Label htmlFor="server_url">
                                        {t('Server URL')}
                                    </Label>
                                    <Input
                                        id="server_url"
                                        type="url"
                                        value={form.data.server_url}
                                        onChange={(e) =>
                                            form.setData(
                                                'server_url',
                                                e.target.value,
                                            )
                                        }
                                        placeholder="https://example.com/mcp"
                                        required
                                    />
                                    <InputError
                                        message={form.errors.server_url}
                                    />
                                </div>
                            </div>
                            <div className="grid gap-4 sm:grid-cols-2">
                                <div className="space-y-1.5">
                                    <Label htmlFor="auth_type">
                                        {t('Authentication')}
                                    </Label>
                                    <select
                                        id="auth_type"
                                        value={form.data.auth_type}
                                        onChange={(e) =>
                                            form.setData(
                                                'auth_type',
                                                e.target.value as AuthType,
                                            )
                                        }
                                        className="h-9 w-full rounded-md border border-input bg-background px-3 text-sm shadow-xs focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring/40 focus-visible:outline-none"
                                    >
                                        <option value="bearer">
                                            {t('Bearer API key')}
                                        </option>
                                        <option value="none">
                                            {t('No auth')}
                                        </option>
                                    </select>
                                    <p className="text-[11px] text-muted-foreground">
                                        {t(
                                            'Keys are stored encrypted (AES-GCM) and never sent to the LLM.',
                                        )}
                                    </p>
                                </div>
                                {form.data.auth_type === 'bearer' && (
                                    <div className="space-y-1.5">
                                        <Label htmlFor="api_key">
                                            {t('API key')}
                                        </Label>
                                        <Input
                                            id="api_key"
                                            type="password"
                                            value={form.data.api_key}
                                            onChange={(e) =>
                                                form.setData(
                                                    'api_key',
                                                    e.target.value,
                                                )
                                            }
                                            placeholder="sk-…"
                                            autoComplete="off"
                                        />
                                        <InputError
                                            message={form.errors.api_key}
                                        />
                                    </div>
                                )}
                            </div>
                            <div className="flex items-center justify-end gap-2 border-t pt-4">
                                <Button
                                    type="submit"
                                    disabled={form.processing}
                                >
                                    {form.processing ? (
                                        <Loader2 className="me-1.5 size-4 animate-spin" />
                                    ) : (
                                        <Plug className="me-1.5 size-4" />
                                    )}
                                    {t('Attach + discover tools')}
                                </Button>
                            </div>
                        </form>
                    </CardContent>
                </Card>

                <div className="space-y-3">
                    <div className="flex items-center justify-between">
                        <h2 className="text-sm font-semibold tracking-wide text-muted-foreground uppercase">
                            {t('Connected servers')}
                        </h2>
                        {servers.length > 0 && (
                            <span className="text-xs text-muted-foreground">
                                {t(':count connected', {
                                    count: servers.length,
                                })}
                            </span>
                        )}
                    </div>

                    {servers.length === 0 ? (
                        <EmptyState
                            icon={Plug}
                            title={t('No MCP servers connected yet')}
                            description={t(
                                'Attach a server above. Tool calls remain disabled until you flip them on in the Manage tools screen.',
                            )}
                        />
                    ) : (
                        <div className="space-y-3">
                            {servers.map((server) => {
                                const busy = pending === server.id;

                                return (
                                    <Card
                                        key={server.id}
                                        className="overflow-hidden"
                                    >
                                        <CardContent className="p-4">
                                            <div className="flex flex-wrap items-start justify-between gap-3">
                                                <div className="min-w-0 flex-1 space-y-2">
                                                    <div className="flex flex-wrap items-center gap-2">
                                                        <p className="font-medium">
                                                            {server.label}
                                                        </p>
                                                        <StatusBadge
                                                            status={
                                                                server.status
                                                            }
                                                        />
                                                        {server.server_name && (
                                                            <span className="text-[11px] text-muted-foreground">
                                                                {
                                                                    server.server_name
                                                                }
                                                                {server.server_version
                                                                    ? ` · v${server.server_version}`
                                                                    : ''}
                                                            </span>
                                                        )}
                                                    </div>
                                                    <p className="truncate font-mono text-xs text-muted-foreground">
                                                        {server.server_url}
                                                    </p>
                                                    <div className="flex flex-wrap gap-x-4 gap-y-1 text-[11px] text-muted-foreground">
                                                        <span>
                                                            <strong className="font-semibold text-foreground">
                                                                {
                                                                    server.granted_count
                                                                }
                                                            </strong>
                                                            {' / '}
                                                            {
                                                                server.tools_count
                                                            }{' '}
                                                            {t('tools enabled')}
                                                        </span>
                                                        <span>
                                                            {t('Synced')}{' '}
                                                            {relativeTime(
                                                                server.tools_synced_at,
                                                                t('never'),
                                                            )}
                                                        </span>
                                                        <span>
                                                            {t('Last used')}{' '}
                                                            {relativeTime(
                                                                server.last_used_at,
                                                                t('never'),
                                                            )}
                                                        </span>
                                                    </div>
                                                    {server.tools_sync_error && (
                                                        <div className="rounded-md border border-amber-300/50 bg-amber-50 p-2 text-xs text-amber-900 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-200">
                                                            <strong className="font-semibold">
                                                                {t(
                                                                    'Last sync error:',
                                                                )}
                                                            </strong>{' '}
                                                            {
                                                                server.tools_sync_error
                                                            }
                                                        </div>
                                                    )}
                                                </div>
                                                <div className="flex flex-wrap items-center gap-2">
                                                    <Button
                                                        variant="default"
                                                        size="sm"
                                                        disabled={busy}
                                                        asChild
                                                    >
                                                        <Link
                                                            href={
                                                                agentMcpTools({
                                                                    agent: agent.id,
                                                                    mcpServer:
                                                                        server.id,
                                                                }).url
                                                            }
                                                        >
                                                            {t('Manage tools')}
                                                            {server.tools_count >
                                                                0 && (
                                                                <span className="ms-2 rounded-full bg-primary-foreground/15 px-1.5 text-[10px] tabular-nums">
                                                                    {
                                                                        server.granted_count
                                                                    }
                                                                    /
                                                                    {
                                                                        server.tools_count
                                                                    }
                                                                </span>
                                                            )}
                                                        </Link>
                                                    </Button>
                                                    <Button
                                                        variant="outline"
                                                        size="sm"
                                                        disabled={busy}
                                                        onClick={() =>
                                                            testConnection(
                                                                server,
                                                            )
                                                        }
                                                        title={t(
                                                            'Send a ping to the server',
                                                        )}
                                                    >
                                                        {busy ? (
                                                            <Loader2 className="me-1.5 size-4 animate-spin" />
                                                        ) : (
                                                            <TestTube2 className="me-1.5 size-4" />
                                                        )}
                                                        {t('Test')}
                                                    </Button>
                                                    <Button
                                                        variant="outline"
                                                        size="sm"
                                                        disabled={busy}
                                                        onClick={() =>
                                                            refresh(server)
                                                        }
                                                        title={t(
                                                            'Re-run tools/list',
                                                        )}
                                                    >
                                                        <RefreshCw className="me-1.5 size-4" />
                                                        {t('Refresh')}
                                                    </Button>
                                                    <Button
                                                        variant="outline"
                                                        size="sm"
                                                        asChild
                                                    >
                                                        <Link
                                                            href={
                                                                agentMcpActivity(
                                                                    {
                                                                        agent: agent.id,
                                                                        mcpServer:
                                                                            server.id,
                                                                    },
                                                                ).url
                                                            }
                                                        >
                                                            <Activity className="me-1.5 size-4" />
                                                            {t('Activity')}
                                                        </Link>
                                                    </Button>
                                                    <Button
                                                        variant="ghost"
                                                        size="sm"
                                                        disabled={busy}
                                                        onClick={() =>
                                                            disconnect(server)
                                                        }
                                                        className="text-red-600 hover:bg-red-50 hover:text-red-700 dark:text-red-400 dark:hover:bg-red-500/10"
                                                    >
                                                        <Unplug className="me-1.5 size-4" />
                                                        {t('Disconnect')}
                                                    </Button>
                                                </div>
                                            </div>
                                        </CardContent>
                                    </Card>
                                );
                            })}
                        </div>
                    )}
                </div>
            </div>
        </AppLayout>
    );
}
