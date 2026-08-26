import { Head, Link } from '@inertiajs/react';
import { CreditCard, Wallet } from 'lucide-react';
import { AdminSurface, AdminSurfaceBar } from '@/components/admin-surface';
import { TablePagination } from '@/components/table-pagination';
import type { PaginationMeta } from '@/components/table-pagination';
import { TableSearch } from '@/components/table-search';
import { Card } from '@/components/ui/card';
import AdminLayout from '@/layouts/admin-layout';
import { useT } from '@/lib/i18n';
import { relativeTime } from '@/lib/relative-time';
import { dashboard as adminDashboard } from '@/routes/admin';
import { index as adminSubscriptionsIndex } from '@/routes/admin/subscriptions';
import { show as adminWorkspaceShow } from '@/routes/admin/workspaces';

type Totals = {
    mrr_cents: number;
    at_risk_cents: number;
    active_count: number;
    past_due_count: number;
    total_workspaces: number;
};

type PlanRollup = {
    name: string;
    slug: string;
    price_cents: number;
    monthly_conversations: number;
    subscriber_count: number;
};

type WorkspaceRow = {
    workspace_id: string;
    workspace_name: string;
    workspace_slug: string;
    plan: string;
    plan_slug: string | null;
    price_cents: number;
    monthly_conversations: number;
    stripe_status: string;
    stripe_customer_id: string | null;
    created_at: string | null;
    ends_at: string | null;
};

type Props = {
    totals: Totals;
    plans: PlanRollup[];
    workspaces: WorkspaceRow[];
    pagination: PaginationMeta;
    filters: { q: string };
};

const SUBSCRIPTIONS_ONLY = ['workspaces', 'pagination', 'filters'];

const dollars = (cents: number): string =>
    `$${(cents / 100).toLocaleString(undefined, { minimumFractionDigits: 0, maximumFractionDigits: 2 })}`;

const STATUS_PILL: Record<string, string> = {
    active: 'bg-emerald-500/15 text-emerald-700 dark:text-emerald-400',
    trialing: 'bg-sky-500/15 text-sky-700 dark:text-sky-400',
    past_due: 'bg-amber-500/15 text-amber-700 dark:text-amber-400',
    canceled: 'bg-muted text-muted-foreground',
    no_subscription: 'bg-muted text-muted-foreground',
    no_plan: 'bg-muted text-muted-foreground',
    free: 'bg-slate-500/15 text-slate-700 dark:text-slate-300',
    manual: 'bg-violet-500/15 text-violet-700 dark:text-violet-300',
};

export default function AdminSubscriptions({
    totals,
    plans,
    workspaces,
    pagination,
    filters,
}: Props) {
    const { t } = useT();
    const conversionRate =
        totals.total_workspaces > 0
            ? ((totals.active_count / totals.total_workspaces) * 100).toFixed(1)
            : '0.0';

    return (
        <AdminLayout
            breadcrumbs={[
                { title: t('Dashboard'), href: adminDashboard() },
                { title: t('Subscriptions'), href: adminSubscriptionsIndex() },
            ]}
        >
            <Head title={t('Subscriptions · Admin')} />
            <AdminSurface>
                <AdminSurfaceBar>
                    <span className="inline-flex h-7 items-center rounded-md border bg-card px-2.5 text-xs font-normal text-foreground">
                        {t('Subscriptions')}
                    </span>
                    <span className="inline-flex h-7 items-center rounded-md border bg-card px-2.5 text-xs font-normal text-muted-foreground">
                        {pagination.total === 1
                            ? t(':count billed workspace', {
                                  count: pagination.total.toLocaleString(),
                              })
                            : t(':count billed workspaces', {
                                  count: pagination.total.toLocaleString(),
                              })}
                    </span>
                    <div className="ms-auto w-full sm:w-auto">
                        <TableSearch
                            placeholder={t(
                                'Search by workspace, slug, or stripe id...',
                            )}
                            initialValue={filters.q}
                            only={SUBSCRIPTIONS_ONLY}
                        />
                    </div>
                </AdminSurfaceBar>

                <div className="min-h-0 flex-1 overflow-y-auto p-3 sm:p-4">
                    <div className="grid gap-4">
                        <div className="grid grid-cols-2 gap-4 xl:grid-cols-4">
                            <Card className="p-4">
                                <div className="flex items-center gap-2 text-muted-foreground">
                                    <Wallet className="size-4" />
                                    <p className="text-xs tracking-wide uppercase">
                                        {t('MRR')}
                                    </p>
                                </div>
                                <p className="mt-3 text-3xl font-semibold tracking-tight">
                                    {dollars(totals.mrr_cents)}
                                </p>
                                <p className="mt-1 text-xs text-muted-foreground">
                                    {totals.active_count === 1
                                        ? t(':count active subscription', {
                                              count: totals.active_count,
                                          })
                                        : t(':count active subscriptions', {
                                              count: totals.active_count,
                                          })}
                                </p>
                            </Card>
                            <Card className="p-4">
                                <div className="flex items-center gap-2 text-muted-foreground">
                                    <CreditCard className="size-4" />
                                    <p className="text-xs tracking-wide uppercase">
                                        {t('At risk')}
                                    </p>
                                </div>
                                <p className="mt-3 text-3xl font-semibold tracking-tight text-amber-600 dark:text-amber-400">
                                    {dollars(totals.at_risk_cents)}
                                </p>
                                <p className="mt-1 text-xs text-muted-foreground">
                                    {totals.past_due_count === 1
                                        ? t(':count past-due account', {
                                              count: totals.past_due_count,
                                          })
                                        : t(':count past-due accounts', {
                                              count: totals.past_due_count,
                                          })}
                                </p>
                            </Card>
                            <Card className="p-4">
                                <p className="text-xs tracking-wide text-muted-foreground uppercase">
                                    {t('Workspaces')}
                                </p>
                                <p className="mt-3 text-3xl font-semibold tracking-tight">
                                    {totals.total_workspaces.toLocaleString()}
                                </p>
                                <p className="mt-1 text-xs text-muted-foreground">
                                    {t('total signed up')}
                                </p>
                            </Card>
                            <Card className="p-4">
                                <p className="text-xs tracking-wide text-muted-foreground uppercase">
                                    {t('Paid conversion')}
                                </p>
                                <p className="mt-3 text-3xl font-semibold tracking-tight">
                                    {conversionRate}%
                                </p>
                                <p className="mt-1 text-xs text-muted-foreground">
                                    {t(
                                        'active subscriptions / total workspaces',
                                    )}
                                </p>
                            </Card>
                        </div>

                        <Card className="overflow-hidden p-0">
                            <div className="border-b px-4 py-3">
                                <h2 className="text-sm font-medium">
                                    {t('Plan distribution')}
                                </h2>
                            </div>
                            <div className="overflow-x-auto">
                                <table className="w-full min-w-[780px] border-separate border-spacing-0 text-start text-sm">
                                    <thead className="bg-card text-xs font-medium text-muted-foreground">
                                        <tr>
                                            <th className="border-e border-b px-3 py-2">
                                                {t('Plan')}
                                            </th>
                                            <th className="border-e border-b px-3 py-2">
                                                {t('Price')}
                                            </th>
                                            <th className="border-e border-b px-3 py-2">
                                                {t('Allowance')}
                                            </th>
                                            <th className="border-b px-3 py-2 text-end">
                                                {t('Subscribers')}
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {plans.map((plan) => (
                                            <tr
                                                key={plan.slug}
                                                className="group hover:bg-muted/35"
                                            >
                                                <td className="border-e border-b px-3 py-2 font-medium text-foreground">
                                                    {plan.name}
                                                </td>
                                                <td className="border-e border-b px-3 py-2 text-muted-foreground">
                                                    {plan.price_cents === 0
                                                        ? t('Free')
                                                        : t(':amount / mo', {
                                                              amount: dollars(
                                                                  plan.price_cents,
                                                              ),
                                                          })}
                                                </td>
                                                <td className="border-e border-b px-3 py-2 text-muted-foreground">
                                                    {plan.monthly_conversations ===
                                                    0
                                                        ? t(
                                                              'Unlimited conversations',
                                                          )
                                                        : t(
                                                              ':count conversations',
                                                              {
                                                                  count: plan.monthly_conversations.toLocaleString(),
                                                              },
                                                          )}
                                                </td>
                                                <td className="border-b px-3 py-2 text-end">
                                                    <span className="font-medium text-foreground">
                                                        {plan.subscriber_count}
                                                    </span>{' '}
                                                    <span className="text-xs text-muted-foreground">
                                                        {plan.subscriber_count ===
                                                        1
                                                            ? t('subscriber')
                                                            : t('subscribers')}
                                                    </span>
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        </Card>

                        <Card className="overflow-hidden p-0">
                            <div className="border-b px-4 py-3">
                                <h2 className="text-sm font-medium">
                                    {t('Workspaces with a plan')}
                                </h2>
                            </div>
                            <div className="overflow-x-auto">
                                <table className="w-full min-w-[1080px] border-separate border-spacing-0 text-start text-sm">
                                    <thead className="bg-card text-xs font-medium text-muted-foreground">
                                        <tr>
                                            <th className="w-9 border-b px-3 py-2">
                                                <input
                                                    type="checkbox"
                                                    aria-label={t(
                                                        'Select all billed workspaces',
                                                    )}
                                                    disabled
                                                    className="size-3.5 rounded border-input"
                                                />
                                            </th>
                                            <th className="border-e border-b px-3 py-2">
                                                {t('Workspace')}
                                            </th>
                                            <th className="border-e border-b px-3 py-2">
                                                {t('Plan')}
                                            </th>
                                            <th className="border-e border-b px-3 py-2">
                                                {t('$ / mo')}
                                            </th>
                                            <th className="border-e border-b px-3 py-2">
                                                {t('Stripe status')}
                                            </th>
                                            <th className="border-b px-3 py-2">
                                                {t('Joined')}
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {workspaces.length === 0 ? (
                                            <tr>
                                                <td
                                                    colSpan={6}
                                                    className="h-72 border-b px-4 text-center"
                                                >
                                                    <div className="mx-auto flex max-w-sm flex-col items-center gap-2 text-muted-foreground">
                                                        <CreditCard className="size-8" />
                                                        <p className="text-sm font-medium text-foreground">
                                                            {t(
                                                                'No billed workspaces yet',
                                                            )}
                                                        </p>
                                                        <p className="text-xs">
                                                            {t(
                                                                'Customers will appear here after they upgrade from billing.',
                                                            )}
                                                        </p>
                                                    </div>
                                                </td>
                                            </tr>
                                        ) : (
                                            workspaces.map((workspace) => (
                                                <tr
                                                    key={workspace.workspace_id}
                                                    className="group hover:bg-muted/35"
                                                >
                                                    <td className="border-b px-3 py-2">
                                                        <input
                                                            type="checkbox"
                                                            aria-label={t(
                                                                'Select :name',
                                                                {
                                                                    name: workspace.workspace_name,
                                                                },
                                                            )}
                                                            disabled
                                                            className="size-3.5 rounded border-input"
                                                        />
                                                    </td>
                                                    <td className="border-e border-b px-3 py-2">
                                                        <div className="min-w-0">
                                                            <Link
                                                                href={adminWorkspaceShow(
                                                                    workspace.workspace_id,
                                                                )}
                                                                className="block truncate font-medium text-foreground hover:text-primary"
                                                            >
                                                                {
                                                                    workspace.workspace_name
                                                                }
                                                            </Link>
                                                            <p className="truncate text-xs text-muted-foreground">
                                                                {
                                                                    workspace.workspace_slug
                                                                }
                                                            </p>
                                                        </div>
                                                    </td>
                                                    <td className="border-e border-b px-3 py-2 text-muted-foreground">
                                                        {workspace.plan}
                                                    </td>
                                                    <td className="border-e border-b px-3 py-2 text-foreground">
                                                        {workspace.price_cents >
                                                        0
                                                            ? dollars(
                                                                  workspace.price_cents,
                                                              )
                                                            : '—'}
                                                    </td>
                                                    <td className="border-e border-b px-3 py-2">
                                                        <span
                                                            className={`inline-flex rounded-md border px-2 py-0.5 text-xs font-medium ${
                                                                STATUS_PILL[
                                                                    workspace
                                                                        .stripe_status
                                                                ] ??
                                                                STATUS_PILL.no_subscription
                                                            }`}
                                                        >
                                                            {workspace.stripe_status.replace(
                                                                '_',
                                                                ' ',
                                                            )}
                                                        </span>
                                                    </td>
                                                    <td className="border-b px-3 py-2 text-muted-foreground">
                                                        {relativeTime(
                                                            workspace.created_at,
                                                            '—',
                                                        )}
                                                    </td>
                                                </tr>
                                            ))
                                        )}
                                    </tbody>
                                </table>
                            </div>
                            <TablePagination
                                pagination={pagination}
                                only={SUBSCRIPTIONS_ONLY}
                            />
                        </Card>
                    </div>
                </div>
            </AdminSurface>
        </AdminLayout>
    );
}
