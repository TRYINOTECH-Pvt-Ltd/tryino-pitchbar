import { Head, Link } from '@inertiajs/react';
import { BriefcaseBusiness, Gauge } from 'lucide-react';
import { AdminSurface, AdminSurfaceBar } from '@/components/admin-surface';
import AdminLayout from '@/layouts/admin-layout';
import { useT } from '@/lib/i18n';
import { dashboard as adminDashboard } from '@/routes/admin';
import { index as adminUsageIndex } from '@/routes/admin/usage';
import { show as adminWorkspaceShow } from '@/routes/admin/workspaces';

type Row = {
    workspace_id: string;
    workspace_name: string;
    plan: string;
    used: number;
    limit: number;
    percent: number;
};

type ModelBreakdown = {
    provider: string;
    model: string;
    tokens_in: number;
    tokens_out: number;
    calls: number;
    cost_usd: number;
    no_catalog_match: boolean;
};

type TokenRow = {
    workspace_id: string;
    workspace_name: string;
    tokens_in: number;
    tokens_out: number;
    tokens_total: number;
    calls: number;
    cost_usd: number;
    cost_missing?: boolean;
    breakdown?: ModelBreakdown[];
};

export default function AdminUsage({
    rows,
    tokens = [],
}: {
    rows: Row[];
    tokens?: TokenRow[];
}) {
    const { t } = useT();

    return (
        <AdminLayout
            breadcrumbs={[
                { title: t('Dashboard'), href: adminDashboard() },
                { title: t('Usage'), href: adminUsageIndex() },
            ]}
        >
            <Head title={t('Usage · Admin')} />
            <AdminSurface>
                <AdminSurfaceBar>
                    <span className="inline-flex h-7 items-center rounded-md border bg-card px-2.5 text-xs font-normal text-foreground">
                        {t('Usage this month')}
                    </span>
                    <span className="inline-flex h-7 items-center rounded-md border bg-card px-2.5 text-xs font-normal text-muted-foreground">
                        {rows.length === 1
                            ? t(':count workspace', {
                                  count: rows.length.toLocaleString(),
                              })
                            : t(':count workspaces', {
                                  count: rows.length.toLocaleString(),
                              })}
                    </span>
                </AdminSurfaceBar>

                <div className="min-h-0 flex-1 overflow-auto">
                    <table className="w-full min-w-[1080px] border-separate border-spacing-0 text-start text-sm">
                        <thead className="sticky top-0 z-10 bg-card text-xs font-medium text-muted-foreground">
                            <tr>
                                <th className="w-9 border-b px-3 py-2">
                                    <input
                                        type="checkbox"
                                        aria-label={t('Select all usage rows')}
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
                                    {t('Usage')}
                                </th>
                                <th className="border-b px-3 py-2">
                                    {t('Health')}
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            {rows.length === 0 ? (
                                <tr>
                                    <td
                                        colSpan={5}
                                        className="h-72 border-b px-4 text-center"
                                    >
                                        <div className="mx-auto flex max-w-sm flex-col items-center gap-2 text-muted-foreground">
                                            <Gauge className="size-8" />
                                            <p className="text-sm font-medium text-foreground">
                                                {t('No usage recorded')}
                                            </p>
                                            <p className="text-xs">
                                                {t(
                                                    'No workspace usage has been recorded for this month.',
                                                )}
                                            </p>
                                        </div>
                                    </td>
                                </tr>
                            ) : (
                                rows.map((row) => (
                                    <tr
                                        key={row.workspace_id}
                                        className="group hover:bg-muted/35"
                                    >
                                        <td className="border-b px-3 py-2">
                                            <input
                                                type="checkbox"
                                                aria-label={t('Select :name', {
                                                    name: row.workspace_name,
                                                })}
                                                disabled
                                                className="size-3.5 rounded border-input"
                                            />
                                        </td>
                                        <td className="border-e border-b px-3 py-2">
                                            <div className="flex min-w-0 items-center gap-2 text-foreground">
                                                <span className="flex size-5 shrink-0 items-center justify-center rounded bg-muted text-muted-foreground">
                                                    <BriefcaseBusiness className="size-3.5" />
                                                </span>
                                                <Link
                                                    href={adminWorkspaceShow(
                                                        row.workspace_id,
                                                    )}
                                                    className="block truncate font-medium text-foreground hover:text-primary"
                                                >
                                                    {row.workspace_name}
                                                </Link>
                                            </div>
                                        </td>
                                        <td className="border-e border-b px-3 py-2 text-muted-foreground">
                                            {row.plan}
                                        </td>
                                        <td className="border-e border-b px-3 py-2">
                                            <div className="space-y-2">
                                                <p className="text-sm text-foreground">
                                                    {row.used.toLocaleString()}{' '}
                                                    /{' '}
                                                    {row.limit > 0
                                                        ? row.limit.toLocaleString()
                                                        : t('Unlimited')}
                                                </p>
                                                <div className="h-2 rounded-full bg-muted">
                                                    <div
                                                        className={`h-2 rounded-full ${
                                                            row.percent >= 90
                                                                ? 'bg-rose-500'
                                                                : row.percent >=
                                                                    70
                                                                  ? 'bg-amber-500'
                                                                  : 'bg-emerald-500'
                                                        }`}
                                                        style={{
                                                            width: `${Math.min(row.percent, 100)}%`,
                                                        }}
                                                    />
                                                </div>
                                            </div>
                                        </td>
                                        <td className="border-b px-3 py-2">
                                            <span
                                                className={`inline-flex items-center gap-1 rounded-md border px-2 py-0.5 text-xs font-medium ${
                                                    row.percent >= 90
                                                        ? 'border-rose-200 bg-rose-50 text-rose-700 dark:border-rose-500/30 dark:bg-rose-500/10 dark:text-rose-300'
                                                        : row.percent >= 70
                                                          ? 'border-amber-200 bg-amber-50 text-amber-700 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-300'
                                                          : 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-300'
                                                }`}
                                            >
                                                <Gauge className="size-3.5" />
                                                {row.percent === 0 &&
                                                row.used > 0
                                                    ? '<1%'
                                                    : `${row.percent}%`}
                                            </span>
                                        </td>
                                    </tr>
                                ))
                            )}
                        </tbody>
                    </table>
                </div>
            </AdminSurface>

            {/* C9: token-level usage. Silent feature in v2.0.0 — the
                tab informs operators of real per-workspace AI spend so
                they can price future plans against actual cost. No
                quota enforcement on this surface yet. */}
            <AdminSurface className="mt-6">
                <AdminSurfaceBar>
                    <span className="inline-flex h-7 items-center rounded-md border bg-card px-2.5 text-xs font-normal text-foreground">
                        {t('Token usage this month')}
                    </span>
                    <span className="inline-flex h-7 items-center rounded-md border bg-card px-2.5 text-xs font-normal text-muted-foreground">
                        {tokens.length === 1
                            ? t(':count workspace', {
                                  count: tokens.length.toLocaleString(),
                              })
                            : t(':count workspaces', {
                                  count: tokens.length.toLocaleString(),
                              })}
                    </span>
                </AdminSurfaceBar>

                <div className="min-h-0 flex-1 overflow-auto">
                    <table className="w-full min-w-[900px] border-separate border-spacing-0 text-start text-sm">
                        <thead className="sticky top-0 z-10 bg-card text-xs font-medium text-muted-foreground">
                            <tr>
                                <th className="border-e border-b px-3 py-2">
                                    {t('Workspace')}
                                </th>
                                <th className="border-e border-b px-3 py-2 text-end">
                                    {t('Tokens in')}
                                </th>
                                <th className="border-e border-b px-3 py-2 text-end">
                                    {t('Tokens out')}
                                </th>
                                <th className="border-e border-b px-3 py-2 text-end">
                                    {t('Total')}
                                </th>
                                <th className="border-e border-b px-3 py-2 text-end">
                                    {t('Calls')}
                                </th>
                                <th className="border-b px-3 py-2 text-end">
                                    {t('Cost (USD)')}
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            {tokens.length === 0 ? (
                                <tr>
                                    <td
                                        colSpan={6}
                                        className="h-32 border-b px-4 text-center text-muted-foreground"
                                    >
                                        {t(
                                            'No token usage recorded yet — chat at least once for a row to appear here.',
                                        )}
                                    </td>
                                </tr>
                            ) : (
                                tokens.map((row) => (
                                    <tr
                                        key={row.workspace_id}
                                        className="hover:bg-muted/35"
                                    >
                                        <td className="border-e border-b px-3 py-2 font-medium text-foreground">
                                            {row.workspace_name}
                                        </td>
                                        <td className="border-e border-b px-3 py-2 text-end tabular-nums">
                                            {row.tokens_in.toLocaleString()}
                                        </td>
                                        <td className="border-e border-b px-3 py-2 text-end tabular-nums">
                                            {row.tokens_out.toLocaleString()}
                                        </td>
                                        <td className="border-e border-b px-3 py-2 text-end font-medium tabular-nums">
                                            {row.tokens_total.toLocaleString()}
                                        </td>
                                        <td className="border-e border-b px-3 py-2 text-end text-muted-foreground tabular-nums">
                                            {row.calls.toLocaleString()}
                                        </td>
                                        <td className="border-b px-3 py-2 text-end tabular-nums">
                                            <span
                                                title={
                                                    row.breakdown &&
                                                    row.breakdown.length > 0
                                                        ? row.breakdown
                                                              .map(
                                                                  (b) =>
                                                                      `${b.provider}/${b.model}: $${b.cost_usd.toFixed(6)} (${b.calls} calls, ${b.tokens_in + b.tokens_out} tok)${b.no_catalog_match ? ' — no catalog match' : ''}`,
                                                              )
                                                              .join('\n')
                                                        : undefined
                                                }
                                            >
                                                {row.cost_usd >= 0.0001
                                                    ? `$${row.cost_usd.toFixed(4)}`
                                                    : row.cost_usd > 0
                                                      ? `$${row.cost_usd.toFixed(6)}`
                                                      : '$0.0000'}
                                            </span>
                                            {row.cost_missing && (
                                                <span
                                                    className="ms-1 text-[10px] text-amber-700 dark:text-amber-300"
                                                    title={t(
                                                        'Tokens were logged but no cost was priced. Either the model is missing from the catalog, or the LLM stream returned zero tokens. Hover the cost value for the per-model breakdown.',
                                                    )}
                                                >
                                                    ⚠
                                                </span>
                                            )}
                                        </td>
                                    </tr>
                                ))
                            )}
                        </tbody>
                    </table>
                </div>
            </AdminSurface>
        </AdminLayout>
    );
}
