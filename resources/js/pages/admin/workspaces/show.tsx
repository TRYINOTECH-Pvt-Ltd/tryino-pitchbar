import { Head, router } from '@inertiajs/react';
import {
    Bot,
    BriefcaseBusiness,
    MessageSquare,
    Sparkles,
    Users,
} from 'lucide-react';
import {
    AdminSurface,
    AdminSurfaceBar,
    AdminSurfaceTitle,
} from '@/components/admin-surface';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import AdminLayout from '@/layouts/admin-layout';
import { useT } from '@/lib/i18n';
import { relativeTime } from '@/lib/relative-time';
import { dashboard as adminDashboard } from '@/routes/admin';
import { start as adminImpersonateStart } from '@/routes/admin/impersonate';
import {
    index as adminWorkspacesIndex,
    show as adminWorkspaceShow,
} from '@/routes/admin/workspaces';

type Workspace = {
    id: string;
    name: string;
    slug: string;
    plan: {
        name: string;
        slug: string;
        monthly_conversations: number;
        price_cents: number;
    } | null;
    owner: { id: number; name: string; email: string } | null;
    created_at: string | null;
};

type Agent = {
    id: string;
    name: string;
    is_published: boolean;
    language_default: string;
    created_at: string;
};

type Props = {
    workspace: Workspace;
    agents: Agent[];
    metrics: { conversations: number; leads: number };
};

export default function AdminWorkspaceShow({
    workspace,
    agents,
    metrics,
}: Props) {
    const { t } = useT();
    const owner = workspace.owner;

    return (
        <AdminLayout
            breadcrumbs={[
                { title: t('Dashboard'), href: adminDashboard() },
                { title: t('Workspaces'), href: adminWorkspacesIndex() },
                {
                    title: workspace.name,
                    href: adminWorkspaceShow(workspace.id),
                },
            ]}
        >
            <Head title={t(':name · Admin', { name: workspace.name })} />
            <AdminSurface>
                <AdminSurfaceBar>
                    <AdminSurfaceTitle
                        title={workspace.name}
                        subtitle={`${workspace.slug} · ${workspace.owner?.email ?? t('No owner assigned')} · ${workspace.plan?.name ?? t('No plan selected')}`}
                        meta={
                            <span className="inline-flex items-center rounded-full border bg-background px-2 py-0.5 text-[11px] font-medium text-muted-foreground">
                                {t('Workspace detail')}
                            </span>
                        }
                    />
                    {owner && (
                        <Button
                            size="sm"
                            onClick={() =>
                                router.post(adminImpersonateStart.url(owner.id))
                            }
                        >
                            {t('Impersonate owner')}
                        </Button>
                    )}
                </AdminSurfaceBar>

                <div className="min-h-0 flex-1 overflow-y-auto p-3 sm:p-4">
                    <div className="grid gap-4 lg:grid-cols-[1.3fr_0.9fr]">
                        <div className="grid gap-4">
                            <div className="grid gap-3 sm:grid-cols-3">
                                <Card className="p-4">
                                    <div className="flex items-center gap-2 text-muted-foreground">
                                        <Bot className="size-4" />
                                        <p className="text-xs tracking-wide uppercase">
                                            {t('Agents')}
                                        </p>
                                    </div>
                                    <p className="mt-3 text-3xl font-semibold tracking-tight tabular-nums">
                                        {agents.length.toLocaleString()}
                                    </p>
                                </Card>
                                <Card className="p-4">
                                    <div className="flex items-center gap-2 text-muted-foreground">
                                        <MessageSquare className="size-4" />
                                        <p className="text-xs tracking-wide uppercase">
                                            {t('Conversations')}
                                        </p>
                                    </div>
                                    <p className="mt-3 text-3xl font-semibold tracking-tight tabular-nums">
                                        {metrics.conversations.toLocaleString()}
                                    </p>
                                </Card>
                                <Card className="p-4">
                                    <div className="flex items-center gap-2 text-muted-foreground">
                                        <Sparkles className="size-4" />
                                        <p className="text-xs tracking-wide uppercase">
                                            {t('Leads')}
                                        </p>
                                    </div>
                                    <p className="mt-3 text-3xl font-semibold tracking-tight tabular-nums">
                                        {metrics.leads.toLocaleString()}
                                    </p>
                                </Card>
                            </div>

                            <Card className="overflow-hidden p-0">
                                <div className="border-b px-4 py-3">
                                    <h2 className="text-sm font-medium">
                                        {t('Agents on this workspace')}
                                    </h2>
                                </div>
                                {agents.length === 0 ? (
                                    <p className="px-4 py-8 text-sm text-muted-foreground">
                                        {t(
                                            'No agents have been created for this workspace yet.',
                                        )}
                                    </p>
                                ) : (
                                    <div className="overflow-x-auto">
                                        <table className="w-full min-w-[720px] border-separate border-spacing-0 text-start text-sm">
                                            <thead className="bg-card text-xs font-medium text-muted-foreground">
                                                <tr>
                                                    <th className="w-9 border-b px-3 py-2">
                                                        <input
                                                            type="checkbox"
                                                            aria-label={t(
                                                                'Select all workspace agents',
                                                            )}
                                                            disabled
                                                            className="size-3.5 rounded border-input"
                                                        />
                                                    </th>
                                                    <th className="border-e border-b px-3 py-2">
                                                        {t('Agent')}
                                                    </th>
                                                    <th className="border-e border-b px-3 py-2">
                                                        {t('Language')}
                                                    </th>
                                                    <th className="border-e border-b px-3 py-2">
                                                        {t('Status')}
                                                    </th>
                                                    <th className="border-b px-3 py-2">
                                                        {t('Created')}
                                                    </th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                {agents.map((agent) => (
                                                    <tr
                                                        key={agent.id}
                                                        className="group hover:bg-muted/35"
                                                    >
                                                        <td className="border-b px-3 py-2">
                                                            <input
                                                                type="checkbox"
                                                                aria-label={t(
                                                                    'Select :name',
                                                                    {
                                                                        name: agent.name,
                                                                    },
                                                                )}
                                                                disabled
                                                                className="size-3.5 rounded border-input"
                                                            />
                                                        </td>
                                                        <td className="border-e border-b px-3 py-2">
                                                            <div className="flex min-w-0 items-center gap-2 text-foreground">
                                                                <span className="flex size-5 shrink-0 items-center justify-center rounded bg-muted text-muted-foreground">
                                                                    <Bot className="size-3.5" />
                                                                </span>
                                                                <span className="truncate font-medium">
                                                                    {agent.name}
                                                                </span>
                                                            </div>
                                                        </td>
                                                        <td className="border-e border-b px-3 py-2 text-muted-foreground uppercase">
                                                            {
                                                                agent.language_default
                                                            }
                                                        </td>
                                                        <td className="border-e border-b px-3 py-2">
                                                            <span
                                                                className={`inline-flex rounded-md border px-2 py-0.5 text-xs font-medium ${
                                                                    agent.is_published
                                                                        ? 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-300'
                                                                        : 'border-amber-200 bg-amber-50 text-amber-700 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-300'
                                                                }`}
                                                            >
                                                                {agent.is_published
                                                                    ? t(
                                                                          'Published',
                                                                      )
                                                                    : t(
                                                                          'Draft',
                                                                      )}
                                                            </span>
                                                        </td>
                                                        <td className="border-b px-3 py-2 text-muted-foreground">
                                                            {relativeTime(
                                                                agent.created_at,
                                                                '—',
                                                            )}
                                                        </td>
                                                    </tr>
                                                ))}
                                            </tbody>
                                        </table>
                                    </div>
                                )}
                            </Card>
                        </div>

                        <div className="grid gap-4">
                            <Card className="p-4">
                                <div className="flex items-center gap-2 text-muted-foreground">
                                    <BriefcaseBusiness className="size-4" />
                                    <h2 className="text-sm font-medium text-foreground">
                                        {t('Workspace profile')}
                                    </h2>
                                </div>
                                <dl className="mt-4 grid gap-3 text-sm">
                                    <div>
                                        <dt className="text-xs tracking-wide text-muted-foreground uppercase">
                                            {t('Slug')}
                                        </dt>
                                        <dd className="mt-1 font-medium text-foreground">
                                            {workspace.slug}
                                        </dd>
                                    </div>
                                    <div>
                                        <dt className="text-xs tracking-wide text-muted-foreground uppercase">
                                            {t('Owner')}
                                        </dt>
                                        <dd className="mt-1 text-foreground">
                                            {workspace.owner?.name ??
                                                t('No owner')}
                                            <p className="text-xs text-muted-foreground">
                                                {workspace.owner?.email ?? '—'}
                                            </p>
                                        </dd>
                                    </div>
                                    <div>
                                        <dt className="text-xs tracking-wide text-muted-foreground uppercase">
                                            {t('Plan')}
                                        </dt>
                                        <dd className="mt-1 text-foreground">
                                            {workspace.plan?.name ??
                                                t('No plan')}
                                            <p className="text-xs text-muted-foreground">
                                                {workspace.plan
                                                    ? workspace.plan
                                                          .monthly_conversations >
                                                      0
                                                        ? t(
                                                              ':count conversations / month',
                                                              {
                                                                  count: workspace.plan.monthly_conversations.toLocaleString(),
                                                              },
                                                          )
                                                        : t(
                                                              'Unlimited conversations',
                                                          )
                                                    : '—'}
                                            </p>
                                        </dd>
                                    </div>
                                    <div>
                                        <dt className="text-xs tracking-wide text-muted-foreground uppercase">
                                            {t('Created')}
                                        </dt>
                                        <dd className="mt-1 text-foreground">
                                            {workspace.created_at
                                                ? new Date(
                                                      workspace.created_at,
                                                  ).toLocaleDateString()
                                                : '—'}
                                        </dd>
                                    </div>
                                    <div>
                                        <dt className="text-xs tracking-wide text-muted-foreground uppercase">
                                            {t('Monthly price')}
                                        </dt>
                                        <dd className="mt-1 text-foreground">
                                            {workspace.plan
                                                ? `$${(workspace.plan.price_cents / 100).toLocaleString(undefined, { minimumFractionDigits: 0, maximumFractionDigits: 2 })}`
                                                : '—'}
                                        </dd>
                                    </div>
                                </dl>
                            </Card>

                            <Card className="p-4">
                                <div className="flex items-center gap-2 text-muted-foreground">
                                    <Users className="size-4" />
                                    <h2 className="text-sm font-medium text-foreground">
                                        {t('Workspace posture')}
                                    </h2>
                                </div>
                                <p className="mt-3 text-sm text-muted-foreground">
                                    {agents.length === 1
                                        ? t(
                                              'This tenant currently runs :agents agent, generated :conversations conversations, and produced :leads leads.',
                                              {
                                                  agents: agents.length.toLocaleString(),
                                                  conversations:
                                                      metrics.conversations.toLocaleString(),
                                                  leads: metrics.leads.toLocaleString(),
                                              },
                                          )
                                        : t(
                                              'This tenant currently runs :agents agents, generated :conversations conversations, and produced :leads leads.',
                                              {
                                                  agents: agents.length.toLocaleString(),
                                                  conversations:
                                                      metrics.conversations.toLocaleString(),
                                                  leads: metrics.leads.toLocaleString(),
                                              },
                                          )}
                                </p>
                            </Card>
                        </div>
                    </div>
                </div>
            </AdminSurface>
        </AdminLayout>
    );
}
