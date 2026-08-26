import { Head, Link, router } from '@inertiajs/react';
import {
    AlertCircle,
    CheckCircle2,
    CreditCard,
    Loader2,
    Pencil,
    Plus,
    RefreshCw,
    Trash2,
} from 'lucide-react';
import { Fragment, useState } from 'react';
import { AdminSurface, AdminSurfaceBar } from '@/components/admin-surface';
import { useConfirm } from '@/components/confirm-dialog-provider';
import { Button } from '@/components/ui/button';
import AdminLayout from '@/layouts/admin-layout';
import { useT } from '@/lib/i18n';
import { relativeTime } from '@/lib/relative-time';
import {
    create as adminPlansCreate,
    destroy as adminPlansDestroy,
    edit as adminPlansEdit,
    index as adminPlansIndex,
} from '@/routes/admin/plans';
import { gateway as adminPlansSyncGateway } from '@/routes/admin/plans/sync';

type GatewayId = 'stripe' | 'paypal' | 'razorpay';

type GatewayIds = {
    stripe: { product: string | null; price: string | null };
    paypal: { product: string | null; plan: string | null };
    razorpay: { plan: string | null };
};

type PlanRow = {
    id: string;
    name: string;
    slug: string;
    monthly_conversations: number;
    price_cents: number;
    features: Record<string, boolean>;
    is_active: boolean;
    is_default_for_signup: boolean;
    workspaces_count: number;
    workspaces_limit: number | null;
    created_at: string | null;
    gateway_ids: GatewayIds;
};

type GatewayStatus = {
    enabled: boolean;
    configured: boolean;
    available: boolean;
};

type Props = {
    plans: PlanRow[];
    currency: string;
    gateways: Record<GatewayId, GatewayStatus>;
};

type SyncResult = { ok: boolean; message: string } | null;

const GATEWAY_LABELS: Record<GatewayId, string> = {
    stripe: 'Stripe',
    paypal: 'PayPal',
    razorpay: 'Razorpay',
};

/**
 * Per-gateway sync state.
 *
 *   free       — price_cents=0; no gateway entity expected.
 *   disabled   — gateway is turned off in Settings → System → Billing.
 *   unconfigured — enabled but credentials missing.
 *   synced     — has at least one gateway-side ID.
 *   pending    — paid plan + enabled gateway, but no IDs yet.
 */
type GatewayState = 'free' | 'disabled' | 'unconfigured' | 'synced' | 'pending';

function gatewayState(
    plan: PlanRow,
    gateway: GatewayId,
    status: GatewayStatus,
): GatewayState {
    if (plan.price_cents <= 0) {
        return 'free';
    }

    if (!status.enabled) {
        return 'disabled';
    }

    if (!status.configured) {
        return 'unconfigured';
    }

    const ids = plan.gateway_ids[gateway];
    const hasAnyId = Object.values(ids).some((v) => Boolean(v));

    return hasAnyId ? 'synced' : 'pending';
}

function GatewayBadge({ state }: { state: GatewayState }) {
    const { t } = useT();

    if (state === 'free') {
        return (
            <span className="inline-flex items-center gap-1 rounded-md border bg-muted/40 px-2 py-0.5 text-xs font-medium text-muted-foreground">
                {t('Free')}
            </span>
        );
    }

    if (state === 'disabled') {
        return (
            <span className="inline-flex items-center gap-1 rounded-md border bg-muted/40 px-2 py-0.5 text-xs font-medium text-muted-foreground">
                {t('Off')}
            </span>
        );
    }

    if (state === 'unconfigured') {
        return (
            <span className="inline-flex items-center gap-1 rounded-md border border-rose-200 bg-rose-50 px-2 py-0.5 text-xs font-medium text-rose-700 dark:border-rose-500/30 dark:bg-rose-500/10 dark:text-rose-300">
                <AlertCircle className="size-3" />
                {t('No keys')}
            </span>
        );
    }

    if (state === 'synced') {
        return (
            <span className="inline-flex items-center gap-1 rounded-md border border-emerald-200 bg-emerald-50 px-2 py-0.5 text-xs font-medium text-emerald-700 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-300">
                <CheckCircle2 className="size-3" />
                {t('Synced')}
            </span>
        );
    }

    return (
        <span className="inline-flex items-center gap-1 rounded-md border border-amber-200 bg-amber-50 px-2 py-0.5 text-xs font-medium text-amber-700 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-300">
            <AlertCircle className="size-3" />
            {t('Not synced')}
        </span>
    );
}

function GatewayCell({
    plan,
    gateway,
    status,
}: {
    plan: PlanRow;
    gateway: GatewayId;
    status: GatewayStatus;
}) {
    const { t } = useT();
    const state = gatewayState(plan, gateway, status);
    const ids = plan.gateway_ids[gateway];
    const idValues = Object.values(ids).filter((v): v is string => Boolean(v));

    return (
        <div className="space-y-1">
            <GatewayBadge state={state} />
            {state === 'free' ? (
                <p className="text-xs text-muted-foreground">
                    {t('Local only')}
                </p>
            ) : state === 'disabled' ? (
                <p className="text-xs text-muted-foreground">
                    {t('Disabled in Settings')}
                </p>
            ) : state === 'unconfigured' ? (
                <p className="text-xs text-muted-foreground">
                    {t('Add keys in Settings')}
                </p>
            ) : idValues.length > 0 ? (
                <div className="space-y-0.5 text-[11px]">
                    {idValues.map((id) => (
                        <p key={id} className="truncate font-mono">
                            {id}
                        </p>
                    ))}
                </div>
            ) : (
                <p className="text-xs text-muted-foreground">
                    {t('No IDs yet')}
                </p>
            )}
        </div>
    );
}

export default function PlansIndex({ plans, currency, gateways }: Props) {
    const { t } = useT();
    const confirm = useConfirm();

    const dollars = (cents: number, currency: string): string => {
        if (cents === 0) {
            return t('Free');
        }

        return t(':currency :amount/mo', {
            currency: currency.toUpperCase(),
            amount: (cents / 100).toLocaleString(undefined, {
                minimumFractionDigits: 0,
            }),
        });
    };

    // Per-row sync UI state, keyed by `${plan.id}:${gateway}` so each
    // gateway button has independent spinner + result. Running sync on
    // one cell doesn't blank out a result the admin just read on another.
    const [syncing, setSyncing] = useState<Record<string, boolean>>({});
    const [results, setResults] = useState<Record<string, SyncResult>>({});

    const VISIBLE_GATEWAYS: GatewayId[] = (
        ['stripe', 'paypal', 'razorpay'] as GatewayId[]
    ).filter((g) => gateways[g]?.enabled);

    // Always show Stripe as a column even if disabled, so the page
    // header doesn't collapse to zero columns on a fresh install before
    // any gateway is enabled.
    if (VISIBLE_GATEWAYS.length === 0) {
        VISIBLE_GATEWAYS.push('stripe');
    }

    const deactivate = async (plan: PlanRow) => {
        const ok = await confirm({
            title: t('Deactivate plan?'),
            message: t(
                'Deactivate ":name"?\n\nThe plan stays in the database (existing workspaces keep their assignment) but is archived on every enabled gateway and removed from new checkouts.',
                { name: plan.name },
            ),
            confirmLabel: t('Deactivate'),
            danger: true,
        });

        if (!ok) {
            return;
        }

        router.delete(adminPlansDestroy(plan.id).url, { preserveScroll: true });
    };

    const sync = async (plan: PlanRow, gateway: GatewayId) => {
        const cellKey = `${plan.id}:${gateway}`;
        setSyncing((s) => ({ ...s, [cellKey]: true }));
        setResults((r) => ({ ...r, [cellKey]: null }));

        try {
            const csrf =
                (
                    document.querySelector(
                        'meta[name="csrf-token"]',
                    ) as HTMLMetaElement | null
                )?.content ?? '';
            const response = await fetch(
                adminPlansSyncGateway([plan.id, gateway]).url,
                {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': csrf,
                        'X-Requested-With': 'XMLHttpRequest',
                        Accept: 'application/json',
                    },
                    credentials: 'same-origin',
                },
            );
            const json = (await response.json()) as {
                ok: boolean;
                message: string;
            } | null;

            setResults((r) => ({
                ...r,
                [cellKey]: json
                    ? { ok: json.ok, message: json.message }
                    : {
                          ok: false,
                          message: t('Unexpected response: HTTP :status', {
                              status: response.status,
                          }),
                      },
            }));

            if (json?.ok) {
                router.reload({ only: ['plans'] });
            }
        } catch (e) {
            setResults((r) => ({
                ...r,
                [cellKey]: {
                    ok: false,
                    message: e instanceof Error ? e.message : String(e),
                },
            }));
        } finally {
            setSyncing((s) => ({ ...s, [cellKey]: false }));
        }
    };

    const totalCols = 5 + VISIBLE_GATEWAYS.length + 3; // checkbox+plan+price+conv+status + gateways + workspaces+created+actions

    return (
        <AdminLayout
            breadcrumbs={[
                { title: t('Admin'), href: '/admin' },
                { title: t('Plans'), href: adminPlansIndex() },
            ]}
        >
            <Head title={t('Plans · Admin')} />
            <AdminSurface>
                <AdminSurfaceBar>
                    <span className="inline-flex h-7 items-center rounded-md border bg-card px-2.5 text-xs font-normal text-foreground">
                        {t('Plans')}
                    </span>
                    <span className="inline-flex h-7 items-center rounded-md border bg-card px-2.5 text-xs font-normal text-muted-foreground">
                        {t(':count plans', {
                            count: plans.length.toLocaleString(),
                        })}
                    </span>
                    <div className="ms-auto flex w-full items-center justify-end gap-2 sm:w-auto">
                        <Button asChild size="sm">
                            <Link href={adminPlansCreate()}>
                                <Plus className="size-3.5" />
                                {t('New plan')}
                            </Link>
                        </Button>
                    </div>
                </AdminSurfaceBar>

                <div className="min-h-0 flex-1 overflow-auto">
                    <table className="w-full min-w-[1280px] border-separate border-spacing-0 text-start text-sm">
                        <thead className="sticky top-0 z-10 bg-card text-xs font-medium text-muted-foreground">
                            <tr>
                                <th className="w-9 border-b px-3 py-2">
                                    <input
                                        type="checkbox"
                                        aria-label={t('Select all plans')}
                                        disabled
                                        className="size-3.5 rounded border-input"
                                    />
                                </th>
                                <th className="border-e border-b px-3 py-2">
                                    {t('Plan')}
                                </th>
                                <th className="border-e border-b px-3 py-2">
                                    {t('Price')}
                                </th>
                                <th className="border-e border-b px-3 py-2">
                                    {t('Conversations')}
                                </th>
                                <th className="border-e border-b px-3 py-2">
                                    {t('Status')}
                                </th>
                                {VISIBLE_GATEWAYS.map((g) => (
                                    <th
                                        key={g}
                                        className="border-e border-b px-3 py-2"
                                    >
                                        {GATEWAY_LABELS[g]}
                                    </th>
                                ))}
                                <th className="border-e border-b px-3 py-2">
                                    {t('Workspaces')}
                                </th>
                                <th className="border-e border-b px-3 py-2">
                                    {t('Created')}
                                </th>
                                <th className="border-b px-3 py-2 text-end">
                                    {t('Actions')}
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            {plans.length === 0 ? (
                                <tr>
                                    <td
                                        colSpan={totalCols}
                                        className="h-72 border-b px-4 text-center"
                                    >
                                        <div className="mx-auto flex max-w-sm flex-col items-center gap-2 text-muted-foreground">
                                            <CreditCard className="size-8" />
                                            <p className="text-sm font-medium text-foreground">
                                                {t('No plans yet')}
                                            </p>
                                            <p className="text-xs">
                                                {t(
                                                    'Create a plan to start accepting subscriptions.',
                                                )}
                                            </p>
                                            <Button
                                                asChild
                                                size="sm"
                                                className="mt-2"
                                            >
                                                <Link href={adminPlansCreate()}>
                                                    <Plus className="size-3.5" />
                                                    {t('New plan')}
                                                </Link>
                                            </Button>
                                        </div>
                                    </td>
                                </tr>
                            ) : (
                                plans.map((plan) => {
                                    return (
                                        <Fragment key={plan.id}>
                                            <tr className="group hover:bg-muted/35">
                                                <td className="border-b px-3 py-2">
                                                    <input
                                                        type="checkbox"
                                                        aria-label={t(
                                                            'Select :name',
                                                            { name: plan.name },
                                                        )}
                                                        disabled
                                                        className="size-3.5 rounded border-input"
                                                    />
                                                </td>
                                                <td className="border-e border-b px-3 py-2">
                                                    <div className="min-w-0">
                                                        <p className="truncate font-medium text-foreground">
                                                            {plan.name}
                                                        </p>
                                                        <p className="truncate text-xs text-muted-foreground">
                                                            {plan.slug}
                                                        </p>
                                                    </div>
                                                </td>
                                                <td className="border-e border-b px-3 py-2 text-foreground">
                                                    <span className="tabular-nums">
                                                        {dollars(
                                                            plan.price_cents,
                                                            currency,
                                                        )}
                                                    </span>
                                                </td>
                                                <td className="border-e border-b px-3 py-2 text-muted-foreground">
                                                    {plan.monthly_conversations ===
                                                    0
                                                        ? t('Unlimited')
                                                        : plan.monthly_conversations.toLocaleString()}
                                                </td>
                                                <td className="border-e border-b px-3 py-2">
                                                    <div className="flex flex-wrap items-center gap-1">
                                                        <span
                                                            className={`inline-flex rounded-md border px-2 py-0.5 text-xs font-medium ${
                                                                plan.is_active
                                                                    ? 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-300'
                                                                    : 'border-border bg-muted/40 text-muted-foreground'
                                                            }`}
                                                        >
                                                            {plan.is_active
                                                                ? t('Active')
                                                                : t('Inactive')}
                                                        </span>
                                                        {plan.is_default_for_signup && (
                                                            <span
                                                                className="inline-flex rounded-md border border-amber-200 bg-amber-50 px-2 py-0.5 text-xs font-medium text-amber-700 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-300"
                                                                title={t(
                                                                    'New signups land on this plan',
                                                                )}
                                                            >
                                                                {t('Default')}
                                                            </span>
                                                        )}
                                                    </div>
                                                </td>
                                                {VISIBLE_GATEWAYS.map((g) => (
                                                    <td
                                                        key={g}
                                                        className="border-e border-b px-3 py-2 text-muted-foreground"
                                                    >
                                                        <GatewayCell
                                                            plan={plan}
                                                            gateway={g}
                                                            status={
                                                                gateways[g] ?? {
                                                                    enabled: false,
                                                                    configured: false,
                                                                    available: false,
                                                                }
                                                            }
                                                        />
                                                    </td>
                                                ))}
                                                <td className="border-e border-b px-3 py-2 text-muted-foreground">
                                                    {plan.workspaces_count.toLocaleString()}
                                                    {plan.workspaces_limit !==
                                                    null ? (
                                                        <span className="ms-1 text-xs">
                                                            /{' '}
                                                            {plan.workspaces_limit.toLocaleString()}
                                                        </span>
                                                    ) : (
                                                        <span className="ms-1 text-xs">
                                                            / ∞
                                                        </span>
                                                    )}
                                                </td>
                                                <td className="border-e border-b px-3 py-2 text-muted-foreground">
                                                    {relativeTime(
                                                        plan.created_at,
                                                        '—',
                                                    )}
                                                </td>
                                                <td className="border-b px-3 py-2">
                                                    <div className="flex justify-end gap-1">
                                                        {VISIBLE_GATEWAYS.map(
                                                            (g) => {
                                                                const status =
                                                                    gateways[g];
                                                                const cellKey = `${plan.id}:${g}`;
                                                                const isSyncing =
                                                                    syncing[
                                                                        cellKey
                                                                    ] === true;
                                                                const isFree =
                                                                    plan.price_cents <=
                                                                    0;
                                                                const disabled =
                                                                    isSyncing ||
                                                                    isFree ||
                                                                    !status?.available;
                                                                const title =
                                                                    isFree
                                                                        ? t(
                                                                              'Free plans skip gateway sync',
                                                                          )
                                                                        : !status?.enabled
                                                                          ? t(
                                                                                ':gateway disabled',
                                                                                {
                                                                                    gateway:
                                                                                        GATEWAY_LABELS[
                                                                                            g
                                                                                        ],
                                                                                },
                                                                            )
                                                                          : !status?.configured
                                                                            ? t(
                                                                                  ':gateway missing keys',
                                                                                  {
                                                                                      gateway:
                                                                                          GATEWAY_LABELS[
                                                                                              g
                                                                                          ],
                                                                                  },
                                                                              )
                                                                            : t(
                                                                                  'Sync to :gateway',
                                                                                  {
                                                                                      gateway:
                                                                                          GATEWAY_LABELS[
                                                                                              g
                                                                                          ],
                                                                                  },
                                                                              );

                                                                return (
                                                                    <Button
                                                                        key={g}
                                                                        variant="ghost"
                                                                        size="sm"
                                                                        onClick={() =>
                                                                            sync(
                                                                                plan,
                                                                                g,
                                                                            )
                                                                        }
                                                                        disabled={
                                                                            disabled
                                                                        }
                                                                        title={
                                                                            title
                                                                        }
                                                                    >
                                                                        {isSyncing ? (
                                                                            <Loader2 className="size-3.5 animate-spin" />
                                                                        ) : (
                                                                            <RefreshCw className="size-3.5" />
                                                                        )}
                                                                        <span className="ms-1 hidden text-[11px] xl:inline">
                                                                            {
                                                                                GATEWAY_LABELS[
                                                                                    g
                                                                                ]
                                                                            }
                                                                        </span>
                                                                    </Button>
                                                                );
                                                            },
                                                        )}
                                                        <Button
                                                            asChild
                                                            variant="ghost"
                                                            size="sm"
                                                        >
                                                            <Link
                                                                href={
                                                                    adminPlansEdit(
                                                                        plan.id,
                                                                    ).url
                                                                }
                                                            >
                                                                <Pencil className="size-3.5" />
                                                            </Link>
                                                        </Button>
                                                        <Button
                                                            variant="ghost"
                                                            size="sm"
                                                            onClick={() =>
                                                                deactivate(plan)
                                                            }
                                                            disabled={
                                                                !plan.is_active
                                                            }
                                                            title={
                                                                plan.is_active
                                                                    ? t(
                                                                          'Deactivate',
                                                                      )
                                                                    : t(
                                                                          'Already inactive',
                                                                      )
                                                            }
                                                        >
                                                            <Trash2 className="size-3.5" />
                                                        </Button>
                                                    </div>
                                                </td>
                                            </tr>
                                            {VISIBLE_GATEWAYS.map((g) => {
                                                const cellKey = `${plan.id}:${g}`;
                                                const result =
                                                    results[cellKey] ?? null;

                                                if (result === null) {
                                                    return null;
                                                }

                                                return (
                                                    <tr
                                                        key={cellKey}
                                                        className={
                                                            result.ok
                                                                ? 'bg-emerald-500/5'
                                                                : 'bg-rose-500/5'
                                                        }
                                                    >
                                                        <td
                                                            colSpan={totalCols}
                                                            className="border-b px-3 py-3"
                                                        >
                                                            <div
                                                                className={`flex items-start gap-2 text-xs ${
                                                                    result.ok
                                                                        ? 'text-emerald-700 dark:text-emerald-400'
                                                                        : 'text-rose-700 dark:text-rose-400'
                                                                }`}
                                                            >
                                                                {result.ok ? (
                                                                    <CheckCircle2 className="mt-0.5 size-3.5 shrink-0" />
                                                                ) : (
                                                                    <AlertCircle className="mt-0.5 size-3.5 shrink-0" />
                                                                )}
                                                                <span className="break-all">
                                                                    <span className="font-semibold">
                                                                        {
                                                                            GATEWAY_LABELS[
                                                                                g
                                                                            ]
                                                                        }
                                                                        :
                                                                    </span>{' '}
                                                                    {
                                                                        result.message
                                                                    }
                                                                </span>
                                                            </div>
                                                        </td>
                                                    </tr>
                                                );
                                            })}
                                        </Fragment>
                                    );
                                })
                            )}
                        </tbody>
                    </table>
                </div>
            </AdminSurface>
        </AdminLayout>
    );
}
