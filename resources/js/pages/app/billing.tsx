import { Head, router, usePage } from '@inertiajs/react';
import {
    Check,
    CheckCircle2,
    ChevronLeft,
    ChevronRight,
    Minus,
    Search,
    X,
} from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { useConfirm } from '@/components/confirm-dialog-provider';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { useT } from '@/lib/i18n';
import {
    cancel as billingCancel,
    checkout as billingCheckout,
    portal as billingPortal,
    resume as billingResume,
    swap as billingSwap,
} from '@/routes/billing';

type Gateway = 'stripe' | 'paypal' | 'razorpay';

type Plan = {
    id: string;
    name: string;
    slug: 'free' | 'standard' | 'pro' | 'custom' | string;
    monthly_conversations: number;
    monthly_messages: number | null;
    price_cents: number;
    yearly_price_cents: number | null;
    agents_limit: number | null;
    sources_limit: number | null;
    workflows_limit: number | null;
    integrations_limit: number | null;
    members_limit: number | null;
    workspaces_limit: number | null;
    api_access: boolean;
    remove_branding: boolean;
    features: string[] | null;
    is_purchasable: boolean;
};

type Summary = {
    plan: {
        id: string;
        name: string;
        slug: string;
        monthly_conversations: number;
        price_cents: number;
    } | null;
    used: number;
    limit: number;
    percent: number;
    subscription_status: string | null;
};

type Invoice = {
    id: string;
    number: string | null;
    total_cents: number;
    currency: string;
    status: string;
    hosted_invoice_url: string | null;
    created_at: string | null;
    source: string;
};

type Charge = {
    id: string;
    total_cents: number;
    currency: string;
    description: string;
    created_at: string | null;
    source: string;
};

type Lifetime = {
    plan_name: string | null;
    purchased_at: string | null;
} | null;

type Props = {
    plans: Plan[];
    summary: Summary;
    stripe_configured: boolean;
    available_gateways: Gateway[];
    has_active_subscription: boolean;
    active_gateway: Gateway | null;
    subscription_cancel_at_period_end: boolean;
    flash_checkout?: { status: string | null; gateway?: string | null };
    // C7: native billing history (invoices + one-time charges + LTD
    // metadata). Optional so the page survives older controllers that
    // haven't been redeployed yet.
    invoices?: Invoice[];
    charges?: Charge[];
    lifetime?: Lifetime;
};

const formatPrice = (
    cents: number,
    slug: string,
    t: (key: string) => string,
): string => {
    if (slug === 'custom') {
        return t('Custom');
    }

    if (cents === 0) {
        return '$0';
    }

    return `$${Math.round(cents / 100)}`;
};

function InvoiceStatusBadge({ status }: { status: string }) {
    const label = status.charAt(0).toUpperCase() + status.slice(1);

    if (status === 'paid') {
        return (
            <span className="inline-flex items-center gap-1 rounded-md border border-emerald-200 bg-emerald-50 px-2 py-0.5 text-xs font-medium text-emerald-700 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-300">
                <CheckCircle2 className="size-3" />
                {label}
            </span>
        );
    }

    if (status === 'open' || status === 'draft') {
        return (
            <span className="inline-flex items-center rounded-md border border-amber-200 bg-amber-50 px-2 py-0.5 text-xs font-medium text-amber-700 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-300">
                {label}
            </span>
        );
    }

    return (
        <span className="inline-flex items-center rounded-md border border-slate-200 bg-slate-50 px-2 py-0.5 text-xs font-medium text-slate-600 dark:border-slate-500/30 dark:bg-slate-500/10 dark:text-slate-300">
            {label}
        </span>
    );
}

const formatLimit = (n: number, t: (key: string) => string): string => {
    if (n === 0) {
        return t('Unlimited');
    }

    if (n >= 1000) {
        return `${(n / 1000).toFixed(n % 1000 === 0 ? 0 : 1)}k`;
    }

    return n.toLocaleString();
};

export default function BillingPage({
    plans,
    summary,
    stripe_configured,
    available_gateways,
    has_active_subscription,
    active_gateway,
    subscription_cancel_at_period_end,
    flash_checkout,
    invoices = [],
    charges = [],
    lifetime = null,
}: Props) {
    const { t } = useT();
    const confirm = useConfirm();
    const trial = usePage<{
        trial: { active: boolean; expired: boolean; days_left: number | null } | null;
    }>().props.trial;

    const INVOICES_PER_PAGE = 10;
    const [invoicesPage, setInvoicesPage] = useState(1);
    const [invoicesStatus, setInvoicesStatus] = useState<string>('all');
    const [invoicesSearch, setInvoicesSearch] = useState('');

    const filteredInvoices = useMemo(() => {
        const term = invoicesSearch.trim().toLowerCase();

        return invoices.filter((inv) => {
            if (invoicesStatus !== 'all' && inv.status !== invoicesStatus) {
                return false;
            }

            if (term === '') {
                return true;
            }

            const hay = (inv.number ?? inv.id).toLowerCase();

            return hay.includes(term);
        });
    }, [invoices, invoicesStatus, invoicesSearch]);

    const invoicesTotalPages = Math.max(
        1,
        Math.ceil(filteredInvoices.length / INVOICES_PER_PAGE),
    );

    useEffect(() => {
        if (invoicesPage > invoicesTotalPages) {
            // eslint-disable-next-line react-hooks/set-state-in-effect -- clamp the page once the filtered list shrinks below it
            setInvoicesPage(1);
        }
    }, [invoicesPage, invoicesTotalPages]);

    const invoicesPaginated = useMemo(() => {
        const start = (invoicesPage - 1) * INVOICES_PER_PAGE;

        return filteredInvoices.slice(start, start + INVOICES_PER_PAGE);
    }, [filteredInvoices, invoicesPage]);
    const invoicesRangeStart =
        filteredInvoices.length === 0
            ? 0
            : (invoicesPage - 1) * INVOICES_PER_PAGE + 1;
    const invoicesRangeEnd = Math.min(
        invoicesPage * INVOICES_PER_PAGE,
        filteredInvoices.length,
    );

    const invoicesStatusOptions = useMemo(() => {
        const known = ['paid', 'open', 'draft', 'void', 'uncollectible'];
        const present = new Set(invoices.map((i) => i.status));

        return known.filter((s) => present.has(s));
    }, [invoices]);

    const TAGLINE: Record<string, string> = {
        free: t('For getting live fast'),
        standard: t('Level up productivity'),
        pro: t('For teams ready to scale'),
        custom: t('Tailored solutions for enterprises'),
    };
    const GATEWAY_LABEL: Record<Gateway, string> = {
        stripe: t('Credit / debit card (Stripe)'),
        paypal: t('PayPal'),
        razorpay: t('Razorpay (UPI, cards, netbanking)'),
    };
    const currentSlug = summary.plan?.slug ?? 'free';
    const checkoutStatus = flash_checkout?.status ?? null;

    const gateways = available_gateways ?? [];
    const anyConfigured = gateways.length > 0;

    // Pending plan slug while picking a gateway. Null when the modal isn't
    // open. We always pick a default gateway (first available) so the
    // submit button works without a forced selection.
    const [pendingSlug, setPendingSlug] = useState<string | null>(null);
    const [pendingGateway, setPendingGateway] = useState<Gateway | null>(
        gateways[0] ?? null,
    );

    const onChoose = async (slug: string) => {
        if (!anyConfigured) {
            return;
        }

        // Already on a paid plan with this gateway? Use the in-place swap
        // path on Stripe; non-Stripe gateways re-enter the checkout flow
        // (CheckoutController orphan-cancels the prior sub before creating
        // the new one).
        if (has_active_subscription && active_gateway === 'stripe') {
            const ok = await confirm({
                title: t('Swap plan?'),
                message: t(
                    'Swap to :name now? The change is immediate and Stripe will prorate the difference on your next invoice.',
                    { name: slug },
                ),
                confirmLabel: t('Swap'),
            });

            if (!ok) {
                return;
            }

            router.post(billingSwap().url, { plan_slug: slug });

            return;
        }

        // Only one gateway live? Skip the picker — submit straight through.
        if (gateways.length === 1) {
            router.post(billingCheckout().url, {
                plan_slug: slug,
                gateway: gateways[0],
            });

            return;
        }

        setPendingSlug(slug);
        setPendingGateway(gateways[0] ?? null);
    };

    const onCancel = async () => {
        const ok = await confirm({
            title: t('Cancel subscription?'),
            message: t(
                'Cancel your subscription? You keep access until the end of the current billing period, then drop back to Free.',
            ),
            confirmLabel: t('Cancel subscription'),
            danger: true,
        });

        if (!ok) {
            return;
        }

        router.post(billingCancel().url, {});
    };

    const onResume = () => {
        router.post(billingResume().url, {});
    };

    const onConfirmGateway = () => {
        if (pendingSlug === null || pendingGateway === null) {
            return;
        }

        router.post(billingCheckout().url, {
            plan_slug: pendingSlug,
            gateway: pendingGateway,
        });

        setPendingSlug(null);
    };

    const usagePercent =
        summary.limit === 0 ? 0 : Math.min(100, summary.percent);
    const overLimit = summary.limit > 0 && summary.used >= summary.limit;
    const nearLimit = !overLimit && usagePercent >= 80;

    const getPlanFeatures = (plan: Plan): string[] => {
        return Array.isArray(plan.features) ? plan.features : [];
    };

    // Monthly / Yearly toggle. Only shows when at least one of the
    // plans has a yearly price configured; otherwise the toggle is
    // hidden (the customer-facing controller decides what to surface).
    const [interval, setInterval] = useState<'month' | 'year'>('month');
    const anyYearly = plans.some(
        (p) =>
            typeof p.yearly_price_cents === 'number' &&
            p.yearly_price_cents > 0,
    );

    type LimitRow = {
        key: string;
        label: string;
        // null = unlimited (default for legacy plans), 0 = blocked,
        // positive = capped, boolean = on/off, string = literal copy.
        value: number | boolean | string | null;
    };

    const formatRowValue = (
        value: number | boolean | string | null,
    ): { text: string; icon: 'check' | 'x' | 'dot' } => {
        if (typeof value === 'string') {
            return { text: value, icon: 'check' };
        }

        if (typeof value === 'boolean') {
            return value
                ? { text: t('Included'), icon: 'check' }
                : { text: t('Not included'), icon: 'x' };
        }

        if (value === null) {
            return { text: t('Unlimited'), icon: 'check' };
        }

        if (value === 0) {
            return { text: t('Not included'), icon: 'x' };
        }

        return { text: formatLimit(value, t), icon: 'check' };
    };

    const featureRows = (plan: Plan): LimitRow[] => [
        {
            key: 'conversations',
            label: t('Conversations per month'),
            value:
                plan.monthly_conversations === 0
                    ? null
                    : plan.monthly_conversations,
        },
        {
            key: 'messages',
            label: t('AI messages per month'),
            value: plan.monthly_messages,
        },
        {
            key: 'agents',
            label: t('AI agents'),
            value: plan.agents_limit,
        },
        {
            key: 'sources',
            label: t('Knowledge sources'),
            value: plan.sources_limit,
        },
        {
            key: 'workflows',
            label: t('Workflows'),
            value: plan.workflows_limit,
        },
        {
            key: 'integrations',
            label: t('Integrations'),
            value: plan.integrations_limit,
        },
        {
            key: 'members',
            label: t('Team members'),
            value: plan.members_limit,
        },
        {
            key: 'workspaces',
            label: t('Workspaces per owner'),
            value: plan.workspaces_limit,
        },
        {
            key: 'api',
            label: t('API access'),
            value: plan.api_access,
        },
        {
            key: 'branding',
            label: t('Remove "Powered by" branding'),
            value: plan.remove_branding,
        },
    ];

    const planDisplayPriceCents = (plan: Plan): number => {
        if (
            interval === 'year' &&
            typeof plan.yearly_price_cents === 'number' &&
            plan.yearly_price_cents > 0
        ) {
            return plan.yearly_price_cents;
        }

        return plan.price_cents;
    };

    const planSavingsLabel = (plan: Plan): string | null => {
        if (
            interval !== 'year' ||
            !plan.yearly_price_cents ||
            plan.yearly_price_cents <= 0 ||
            plan.price_cents <= 0
        ) {
            return null;
        }

        const fullYear = plan.price_cents * 12;
        const saved = fullYear - plan.yearly_price_cents;

        if (saved <= 0) {
            return null;
        }

        return t('Save :pct%', {
            pct: Math.round((saved / fullYear) * 100),
        });
    };

    return (
        <AppLayout
            breadcrumbs={[{ title: t('Billing'), href: '/app/billing' }]}
        >
            <Head title={t('Billing')} />
            <div className="flex flex-1 flex-col gap-6 p-4">
                <div>
                    <h1 className="text-2xl font-semibold tracking-tight">
                        {t('Billing')}
                    </h1>
                    <p className="mt-1 text-sm text-muted-foreground">
                        {t(
                            'Plans, usage, and subscription for this workspace.',
                        )}
                    </p>
                </div>

                {trial?.expired && (
                    <Card className="border-rose-500/30 bg-rose-500/5 p-4">
                        <p className="text-sm font-medium text-rose-700 dark:text-rose-400">
                            {t('Your free trial has ended.')}
                        </p>
                        <p className="mt-1 text-sm text-rose-700/80 dark:text-rose-400/80">
                            {t(
                                'Choose a plan below to reactivate your workspace. Your agents, sources, and conversations are kept safe in the meantime.',
                            )}
                        </p>
                    </Card>
                )}

                {checkoutStatus === 'success' && (
                    <Card className="border-emerald-500/30 bg-emerald-500/5 p-4">
                        <p className="text-sm text-emerald-700 dark:text-emerald-400">
                            {t(
                                "Subscription confirmed — your new plan is active. If you don't see the change yet, it'll appear once the payment provider finishes notifying us (usually within a few seconds).",
                            )}
                        </p>
                    </Card>
                )}
                {checkoutStatus === 'cancelled' && (
                    <Card className="border-amber-500/30 bg-amber-500/5 p-4">
                        <p className="text-sm text-amber-700 dark:text-amber-400">
                            {t('Checkout cancelled — no charge was made.')}
                        </p>
                    </Card>
                )}

                <Card className="p-5">
                    <div className="flex flex-wrap items-center justify-between gap-3">
                        <div>
                            <p className="text-xs tracking-wide text-muted-foreground uppercase">
                                {t('Current plan')}
                            </p>
                            <p className="mt-1 text-xl font-semibold">
                                {summary.plan?.name ?? t('Free')}
                            </p>
                        </div>
                        <div className="text-end">
                            <p className="text-xs tracking-wide text-muted-foreground uppercase">
                                {t('This month')}
                            </p>
                            <p className="mt-1 text-xl font-semibold">
                                {summary.used.toLocaleString()}
                                <span className="text-sm font-normal text-muted-foreground">
                                    {' '}
                                    /{' '}
                                    {summary.limit === 0
                                        ? t('Unlimited')
                                        : t(':count conversations', {
                                              count: summary.limit.toLocaleString(),
                                          })}
                                </span>
                            </p>
                        </div>
                    </div>
                    {has_active_subscription && (
                        <div className="mt-4 flex flex-wrap justify-end gap-2">
                            {subscription_cancel_at_period_end &&
                                active_gateway === 'stripe' && (
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        onClick={onResume}
                                    >
                                        {t('Resume subscription')}
                                    </Button>
                                )}
                            {!subscription_cancel_at_period_end && (
                                <Button
                                    variant="outline"
                                    size="sm"
                                    onClick={onCancel}
                                    className="text-rose-600 hover:bg-rose-500/10 hover:text-rose-700"
                                >
                                    {t('Cancel subscription')}
                                </Button>
                            )}
                            <Button asChild variant="outline" size="sm">
                                <a href={billingPortal().url}>
                                    {t('Manage subscription')}
                                </a>
                            </Button>
                        </div>
                    )}
                    {subscription_cancel_at_period_end && (
                        <p className="mt-3 text-xs text-amber-600 dark:text-amber-400">
                            {t(
                                'Your subscription is scheduled to end at the close of the current billing period. Resume to keep your plan.',
                            )}
                        </p>
                    )}
                    {summary.limit > 0 && (
                        <div className="mt-4">
                            <div className="h-2 w-full overflow-hidden rounded-full bg-muted">
                                <div
                                    className={`h-full transition-all ${
                                        overLimit
                                            ? 'bg-rose-500'
                                            : nearLimit
                                              ? 'bg-amber-500'
                                              : 'bg-emerald-500'
                                    }`}
                                    style={{ width: `${usagePercent}%` }}
                                />
                            </div>
                            {overLimit && (
                                <p className="mt-2 text-xs text-rose-600 dark:text-rose-400">
                                    {t(
                                        'You\'ve reached your monthly conversation limit. New visitors get a friendly "we\'re full" message until you upgrade or the month resets.',
                                    )}
                                </p>
                            )}
                            {nearLimit && (
                                <p className="mt-2 text-xs text-amber-600 dark:text-amber-400">
                                    {t(
                                        ':pct% of your monthly conversation budget remaining.',
                                        { pct: 100 - usagePercent },
                                    )}
                                </p>
                            )}
                        </div>
                    )}
                    <p className="mt-3 text-xs text-muted-foreground">
                        {t(
                            'One unique visitor session counts as one conversation, no matter how many messages they send. Reopening the chat in the same browser stays on the same conversation. The counter resets on the 1st of each month.',
                        )}
                    </p>
                </Card>

                {anyYearly && (
                    <div className="flex items-center justify-center">
                        <div
                            role="radiogroup"
                            aria-label={t('Billing interval')}
                            className="inline-flex rounded-full border bg-muted/40 p-1 text-sm"
                        >
                            {(
                                [
                                    {
                                        v: 'month' as const,
                                        label: t('Monthly'),
                                    },
                                    {
                                        v: 'year' as const,
                                        label: t('Yearly'),
                                    },
                                ] as const
                            ).map((opt) => (
                                <button
                                    key={opt.v}
                                    type="button"
                                    role="radio"
                                    aria-checked={interval === opt.v}
                                    onClick={() => setInterval(opt.v)}
                                    className={
                                        interval === opt.v
                                            ? 'rounded-full bg-foreground px-4 py-1.5 font-medium text-background'
                                            : 'rounded-full px-4 py-1.5 text-muted-foreground hover:text-foreground'
                                    }
                                >
                                    {opt.label}
                                </button>
                            ))}
                        </div>
                    </div>
                )}

                <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                    {plans.map((plan) => {
                        const isCurrent = plan.slug === currentSlug;
                        const tagline = TAGLINE[plan.slug] ?? '';
                        const displayCents = planDisplayPriceCents(plan);
                        const savings = planSavingsLabel(plan);
                        const isYearlyView =
                            interval === 'year' &&
                            typeof plan.yearly_price_cents === 'number' &&
                            plan.yearly_price_cents > 0;

                        return (
                            <Card
                                key={plan.id}
                                className={`flex flex-col p-5 ${isCurrent ? 'border-foreground/30 ring-1 ring-foreground/20' : ''}`}
                            >
                                <div className="mb-4 flex items-center gap-2">
                                    <h2 className="text-xl font-semibold tracking-tight">
                                        {plan.name}
                                    </h2>
                                    {plan.slug === 'standard' && (
                                        <span className="rounded bg-gradient-to-r from-violet-500 to-orange-500 px-1.5 py-0.5 text-[10px] font-semibold tracking-wide text-white uppercase">
                                            {t('Popular')}
                                        </span>
                                    )}
                                    {isCurrent && (
                                        <span className="rounded bg-foreground/10 px-1.5 py-0.5 text-[10px] font-semibold tracking-wide uppercase">
                                            {t('Current')}
                                        </span>
                                    )}
                                </div>
                                <p className="text-xs text-muted-foreground">
                                    {tagline}
                                </p>

                                <div className="mt-6">
                                    <span className="text-3xl font-semibold">
                                        {formatPrice(
                                            displayCents,
                                            plan.slug,
                                            t,
                                        )}
                                    </span>
                                    {plan.slug !== 'custom' && (
                                        <span className="ms-1 text-xs text-muted-foreground">
                                            {isYearlyView
                                                ? t('/ year')
                                                : t('/ month')}
                                        </span>
                                    )}
                                    {savings && (
                                        <span className="ms-2 rounded bg-emerald-500/15 px-1.5 py-0.5 text-[10px] font-semibold text-emerald-700 dark:text-emerald-400">
                                            {savings}
                                        </span>
                                    )}
                                </div>

                                <ul className="mt-5 flex-1 space-y-2 text-sm">
                                    {featureRows(plan).map((row) => {
                                        const { text, icon } = formatRowValue(
                                            row.value,
                                        );
                                        const Icon =
                                            icon === 'check'
                                                ? Check
                                                : icon === 'x'
                                                  ? X
                                                  : Minus;
                                        const tone =
                                            icon === 'check'
                                                ? 'text-emerald-600'
                                                : icon === 'x'
                                                  ? 'text-muted-foreground/60'
                                                  : 'text-muted-foreground';

                                        return (
                                            <li
                                                key={row.key}
                                                className="flex items-start gap-2"
                                            >
                                                <Icon
                                                    className={`mt-0.5 size-4 shrink-0 ${tone}`}
                                                />
                                                <span
                                                    className={
                                                        icon === 'x'
                                                            ? 'text-muted-foreground line-through'
                                                            : undefined
                                                    }
                                                >
                                                    {row.label}: {text}
                                                </span>
                                            </li>
                                        );
                                    })}
                                    {getPlanFeatures(plan).map((f) => (
                                        <li
                                            key={f}
                                            className="flex items-start gap-2"
                                        >
                                            <Check className="mt-0.5 size-4 shrink-0 text-emerald-600" />
                                            <span>{f}</span>
                                        </li>
                                    ))}
                                </ul>

                                <div className="mt-6">
                                    {isCurrent ? (
                                        <Button
                                            variant="outline"
                                            className="w-full"
                                            disabled
                                        >
                                            {t('Current plan')}
                                        </Button>
                                    ) : plan.slug === 'custom' ? (
                                        <Button
                                            asChild
                                            variant="outline"
                                            className="w-full"
                                        >
                                            <a href="mailto:sales@pitchbar.io?subject=Custom plan">
                                                {t('Contact sales')}
                                            </a>
                                        </Button>
                                    ) : (
                                        <Button
                                            className="w-full"
                                            disabled={!plan.is_purchasable}
                                            onClick={() => onChoose(plan.slug)}
                                            title={
                                                !plan.is_purchasable
                                                    ? t(
                                                          'No payment gateway is configured for this install yet',
                                                      )
                                                    : undefined
                                            }
                                        >
                                            {!plan.is_purchasable
                                                ? t('Coming soon')
                                                : has_active_subscription
                                                  ? (summary.plan
                                                        ?.price_cents ?? 0) <
                                                    plan.price_cents
                                                      ? t('Upgrade to :name', {
                                                            name: plan.name,
                                                        })
                                                      : t(
                                                            'Downgrade to :name',
                                                            {
                                                                name: plan.name,
                                                            },
                                                        )
                                                  : t('Choose :name', {
                                                        name: plan.name,
                                                    })}
                                        </Button>
                                    )}
                                </div>
                            </Card>
                        );
                    })}
                </div>

                {!anyConfigured && (
                    <Card className="border-dashed p-4">
                        <p className="text-sm text-muted-foreground">
                            {t(
                                'No payment gateway is configured yet, so paid plans show as "Coming soon". Configure Stripe, PayPal, or Razorpay in',
                            )}{' '}
                            <code className="rounded bg-muted px-1 py-0.5 text-xs">
                                {t('Settings → System → Billing')}
                            </code>{' '}
                            {t('to enable upgrades.')}
                        </p>
                    </Card>
                )}

                {!stripe_configured && anyConfigured && (
                    <Card className="border-dashed p-4">
                        <p className="text-xs text-muted-foreground">
                            {t(
                                "Stripe isn't configured on this install — paid plans still work via :gateways but card-only checkout via Stripe is unavailable.",
                                { gateways: gateways.join(' / ') },
                            )}
                        </p>
                    </Card>
                )}
            </div>

            <Dialog
                open={pendingSlug !== null}
                onOpenChange={(open) => {
                    if (!open) {
                        setPendingSlug(null);
                    }
                }}
            >
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>{t('Pick a payment method')}</DialogTitle>
                    </DialogHeader>
                    <div className="space-y-2">
                        {gateways.map((g) => (
                            <label
                                key={g}
                                className={`flex cursor-pointer items-center gap-3 rounded border p-3 text-sm transition ${
                                    pendingGateway === g
                                        ? 'border-foreground/40 bg-foreground/5'
                                        : 'border-border hover:bg-foreground/5'
                                }`}
                            >
                                <input
                                    type="radio"
                                    name="gateway"
                                    value={g}
                                    checked={pendingGateway === g}
                                    onChange={() => setPendingGateway(g)}
                                />
                                <span>{GATEWAY_LABEL[g]}</span>
                            </label>
                        ))}
                    </div>
                    <DialogFooter>
                        <Button
                            variant="outline"
                            onClick={() => setPendingSlug(null)}
                        >
                            {t('Cancel')}
                        </Button>
                        <Button
                            onClick={onConfirmGateway}
                            disabled={pendingGateway === null}
                        >
                            {t('Continue')}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            {/* C7: native billing history. Surfaces invoices, one-time
                charges (lifetime purchases), and the LTD unlock metadata
                so buyers don't bounce to Stripe's hosted portal. White-
                labeled — Stripe invoice PDFs route through our endpoint
                so the vendor name renders the operator's brand. */}
            {(invoices.length > 0 || charges.length > 0 || lifetime) && (
                <div className="mt-10 space-y-6 px-4 pb-12">
                    {lifetime && (
                        <Card className="p-6">
                            <h2 className="text-lg font-semibold">
                                {t('Lifetime access')}
                            </h2>
                            <p className="mt-1 text-sm text-muted-foreground">
                                {lifetime.plan_name}
                                {lifetime.purchased_at &&
                                    ' · ' +
                                        t('owned since :date', {
                                            date: new Date(
                                                lifetime.purchased_at,
                                            ).toLocaleDateString(),
                                        })}
                            </p>
                        </Card>
                    )}

                    {invoices.length > 0 && (
                        <Card className="p-6">
                            <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                                <h2 className="text-lg font-semibold">
                                    {t('Invoices')}
                                </h2>
                                <div className="flex flex-col gap-2 sm:flex-row sm:items-center">
                                    <Select
                                        value={invoicesStatus}
                                        onValueChange={(v) => {
                                            setInvoicesStatus(v);
                                            setInvoicesPage(1);
                                        }}
                                    >
                                        <SelectTrigger className="h-8 w-full text-xs sm:w-40">
                                            <SelectValue
                                                placeholder={t('All statuses')}
                                            />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="all">
                                                {t('All statuses')}
                                            </SelectItem>
                                            {invoicesStatusOptions.map((s) => (
                                                <SelectItem key={s} value={s}>
                                                    {s.charAt(0).toUpperCase() +
                                                        s.slice(1)}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    <div className="relative w-full sm:w-64">
                                        <Search className="absolute top-1/2 left-2 size-3.5 -translate-y-1/2 text-muted-foreground" />
                                        <Input
                                            placeholder={t(
                                                'Search invoice number…',
                                            )}
                                            value={invoicesSearch}
                                            onChange={(e) => {
                                                setInvoicesSearch(
                                                    e.target.value,
                                                );
                                                setInvoicesPage(1);
                                            }}
                                            className="h-8 pl-7 text-xs"
                                        />
                                    </div>
                                </div>
                            </div>
                            <div className="mt-4 overflow-hidden rounded-md border">
                                <div className="overflow-auto">
                                    <table className="w-full border-separate border-spacing-0 text-start text-sm">
                                        <thead className="bg-card text-xs font-medium text-muted-foreground">
                                            <tr>
                                                <th className="border-e border-b px-3 py-2 text-start">
                                                    {t('Invoice')}
                                                </th>
                                                <th className="border-e border-b px-3 py-2 text-start">
                                                    {t('Date')}
                                                </th>
                                                <th className="border-e border-b px-3 py-2 text-start">
                                                    {t('Status')}
                                                </th>
                                                <th className="border-e border-b px-3 py-2 text-end">
                                                    {t('Amount')}
                                                </th>
                                                <th className="border-b px-3 py-2 text-end">
                                                    <span className="sr-only">
                                                        {t('Actions')}
                                                    </span>
                                                </th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {invoicesPaginated.length === 0 && (
                                                <tr>
                                                    <td
                                                        colSpan={5}
                                                        className="border-b px-3 py-8 text-center text-muted-foreground"
                                                    >
                                                        {t(
                                                            'No invoices match these filters.',
                                                        )}
                                                    </td>
                                                </tr>
                                            )}
                                            {invoicesPaginated.map((inv) => (
                                                <tr
                                                    key={inv.id}
                                                    className="group hover:bg-muted/35"
                                                >
                                                    <td className="border-e border-b px-3 py-2 font-medium">
                                                        {inv.number ?? inv.id}
                                                    </td>
                                                    <td className="border-e border-b px-3 py-2 text-muted-foreground">
                                                        {inv.created_at
                                                            ? new Date(
                                                                  inv.created_at,
                                                              ).toLocaleDateString()
                                                            : '—'}
                                                    </td>
                                                    <td className="border-e border-b px-3 py-2">
                                                        <InvoiceStatusBadge
                                                            status={inv.status}
                                                        />
                                                    </td>
                                                    <td className="border-e border-b px-3 py-2 text-end tabular-nums">
                                                        {inv.currency.toUpperCase()}{' '}
                                                        {(
                                                            inv.total_cents /
                                                            100
                                                        ).toFixed(2)}
                                                    </td>
                                                    <td className="border-b px-3 py-2 text-end">
                                                        {inv.source ===
                                                            'stripe' && (
                                                            <a
                                                                href={`/app/billing/invoices/${inv.id}/download`}
                                                                className="rounded-md border px-2.5 py-1 text-xs hover:bg-muted"
                                                            >
                                                                {t('Download')}
                                                            </a>
                                                        )}
                                                    </td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                                {filteredInvoices.length >
                                    INVOICES_PER_PAGE && (
                                    <div className="flex min-h-8 items-center justify-between gap-3 border-t bg-muted/20 px-4 py-1.5 text-xs text-muted-foreground">
                                        <span>
                                            {t('Showing')}{' '}
                                            <span className="font-medium text-foreground">
                                                {invoicesRangeStart}
                                            </span>
                                            –
                                            <span className="font-medium text-foreground">
                                                {invoicesRangeEnd}
                                            </span>{' '}
                                            {t('of')}{' '}
                                            <span className="font-medium text-foreground">
                                                {filteredInvoices.length}
                                            </span>
                                        </span>
                                        <div className="flex items-center gap-1">
                                            <button
                                                type="button"
                                                onClick={() =>
                                                    setInvoicesPage((p) =>
                                                        Math.max(1, p - 1),
                                                    )
                                                }
                                                disabled={invoicesPage <= 1}
                                                aria-label={t('Previous page')}
                                                className="flex size-6 items-center justify-center rounded-md border bg-card text-foreground transition hover:bg-muted disabled:cursor-not-allowed disabled:opacity-40"
                                            >
                                                <ChevronLeft className="size-4" />
                                            </button>
                                            <span className="px-2 tabular-nums">
                                                {invoicesPage} /{' '}
                                                {invoicesTotalPages}
                                            </span>
                                            <button
                                                type="button"
                                                onClick={() =>
                                                    setInvoicesPage((p) =>
                                                        Math.min(
                                                            invoicesTotalPages,
                                                            p + 1,
                                                        ),
                                                    )
                                                }
                                                disabled={
                                                    invoicesPage >=
                                                    invoicesTotalPages
                                                }
                                                aria-label={t('Next page')}
                                                className="flex size-6 items-center justify-center rounded-md border bg-card text-foreground transition hover:bg-muted disabled:cursor-not-allowed disabled:opacity-40"
                                            >
                                                <ChevronRight className="size-4" />
                                            </button>
                                        </div>
                                    </div>
                                )}
                            </div>
                        </Card>
                    )}

                    {charges.length > 0 && (
                        <Card className="p-6">
                            <h2 className="text-lg font-semibold">
                                {t('Order history')}
                            </h2>
                            <div className="mt-4 overflow-auto rounded-md border">
                                <table className="w-full min-w-[520px] border-separate border-spacing-0 text-start text-sm">
                                    <thead className="bg-card text-xs font-medium text-muted-foreground">
                                        <tr>
                                            <th className="border-e border-b px-3 py-2 text-start">
                                                {t('Description')}
                                            </th>
                                            <th className="border-e border-b px-3 py-2 text-start">
                                                {t('Date')}
                                            </th>
                                            <th className="border-b px-3 py-2 text-end">
                                                {t('Amount')}
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {charges.map((c) => (
                                            <tr
                                                key={c.id}
                                                className="group hover:bg-muted/35"
                                            >
                                                <td className="border-e border-b px-3 py-2 font-medium">
                                                    {c.description}
                                                </td>
                                                <td className="border-e border-b px-3 py-2 text-muted-foreground">
                                                    {c.created_at
                                                        ? new Date(
                                                              c.created_at,
                                                          ).toLocaleDateString()
                                                        : '—'}
                                                </td>
                                                <td className="border-b px-3 py-2 text-end tabular-nums">
                                                    {c.currency.toUpperCase()}{' '}
                                                    {(
                                                        c.total_cents / 100
                                                    ).toFixed(2)}
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        </Card>
                    )}
                </div>
            )}
        </AppLayout>
    );
}
