import type { Method } from '@inertiajs/core';
import { Link, useForm } from '@inertiajs/react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useT } from '@/lib/i18n';

export type PlanFormValues = {
    name: string;
    monthly_conversations: number;
    monthly_messages: number | null;
    max_tokens_per_response: number | null;
    // Per-resource caps. null = unlimited, 0 = blocked.
    agents_limit: number | null;
    sources_limit: number | null;
    workflows_limit: number | null;
    integrations_limit: number | null;
    members_limit: number | null;
    workspaces_limit: number | null;
    api_access: boolean;
    price_cents: number;
    /** Optional annual price (in cents). When set, the customer-facing
     *  billing page renders a Monthly/Yearly toggle and uses this for
     *  the yearly column. Leave null to keep the toggle hidden for
     *  this plan. */
    yearly_price_cents: number | null;
    interval: 'month' | 'year';
    is_active: boolean;
    is_default_for_signup: boolean;
    show_on_pricing_page: boolean;
    // Trial plan: signing up on this starts a no-card, time-limited
    // window. trial_days is only meaningful when is_trial is true.
    is_trial: boolean;
    trial_days: number | null;
    features: { remove_branding: boolean };
    // C6: billing-model selector. Defaults to subscription;
    // lifetime + one_time skip the recurring Stripe Price.
    billing_model?: 'subscription' | 'lifetime' | 'one_time';
    // C2: per-currency price overrides. Map of ISO 4217 (lower-case)
    // → minor-unit integer. USD always mirrors `price_cents`.
    prices?: Record<string, number>;
};

type Props = {
    initial: PlanFormValues;
    /** PATCH on edit, POST on create. */
    method: Extract<Method, 'post' | 'patch'>;
    /** Submit URL. */
    action: string;
    submitLabel: string;
    currency: string;
    /**
     * Stripe IDs (read-only on edit). Helpful surface so admins can
     * cross-reference what's wired in their Stripe dashboard without
     * leaving the page.
     */
    readonly?: {
        stripe_product_id: string | null;
        stripe_price_id: string | null;
    };
};

const SUPPORTED_CURRENCIES = [
    'EUR',
    'GBP',
    'INR',
    'BDT',
    'JPY',
    'CNY',
    'CAD',
    'AUD',
    'NZD',
    'SGD',
    'AED',
    'BRL',
    'MXN',
    'ZAR',
    'TRY',
    'KRW',
    'PHP',
    'MYR',
    'IDR',
    'PKR',
    'EGP',
    'NGN',
    'KES',
];

function CurrencyRows({
    prices,
    onChange,
}: {
    prices: Record<string, number>;
    onChange: (next: Record<string, number>) => void;
}) {
    const entries = Object.entries(prices).filter(([k]) => k !== 'usd');
    const addable = SUPPORTED_CURRENCIES.filter(
        (c) => !(c.toLowerCase() in prices),
    );

    const update = (code: string, raw: string) => {
        const cleaned = raw.replace(/[^\d]/g, '');
        const next = {
            ...prices,
            [code.toLowerCase()]: cleaned === '' ? 0 : Number(cleaned),
        };
        onChange(next);
    };
    const remove = (code: string) => {
        const next = { ...prices };
        delete next[code.toLowerCase()];
        onChange(next);
    };
    const add = (code: string) => {
        onChange({ ...prices, [code.toLowerCase()]: 0 });
    };

    return (
        <div className="space-y-2">
            {entries.map(([code, value]) => (
                <div key={code} className="flex items-center gap-2 text-sm">
                    <span className="w-16 font-mono text-muted-foreground uppercase">
                        {code}
                    </span>
                    <input
                        type="number"
                        min={0}
                        step={1}
                        value={value}
                        onChange={(e) => update(code, e.target.value)}
                        className="flex-1 rounded-md border bg-background px-3 py-1.5"
                        placeholder="minor units"
                    />
                    <button
                        type="button"
                        onClick={() => remove(code)}
                        className="rounded-md border px-2 py-1 text-xs hover:bg-muted"
                    >
                        Remove
                    </button>
                </div>
            ))}
            {addable.length > 0 && (
                <select
                    onChange={(e) => {
                        if (e.target.value) {
                            add(e.target.value);
                            e.target.value = '';
                        }
                    }}
                    className="rounded-md border bg-background px-3 py-1.5 text-sm"
                >
                    <option value="">+ Add currency</option>
                    {addable.map((c) => (
                        <option key={c} value={c}>
                            {c}
                        </option>
                    ))}
                </select>
            )}
        </div>
    );
}

const dollarsToCents = (value: string): number => {
    const cleaned = value.replace(/[^\d.]/g, '');

    if (cleaned === '') {
        return 0;
    }

    const dollars = Number.parseFloat(cleaned);

    return Number.isFinite(dollars) ? Math.round(dollars * 100) : 0;
};

const centsToDollars = (cents: number): string => {
    if (!Number.isFinite(cents) || cents === 0) {
        return '';
    }

    return (cents / 100).toFixed(2);
};

export function PlanForm({
    initial,
    method,
    action,
    submitLabel,
    currency,
    readonly,
}: Props) {
    const { t } = useT();
    const form = useForm<PlanFormValues>(initial);

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        form.submit(method, action, { preserveScroll: true });
    };

    const isPaid = form.data.price_cents > 0;

    return (
        <form onSubmit={submit} className="grid gap-4">
            <Card className="p-4">
                <h2 className="font-semibold">{t('Plan')}</h2>
                <p className="mb-4 text-xs text-muted-foreground">
                    {t(
                        'Saved here, then mirrored to Stripe as a Product + Price automatically. Free / custom plans (price ≤ 0) skip the Stripe step.',
                    )}
                </p>

                <div className="grid gap-4 md:grid-cols-2">
                    <div className="grid gap-1.5">
                        <Label htmlFor="plan-name">{t('Display name')}</Label>
                        <Input
                            id="plan-name"
                            value={form.data.name}
                            onChange={(e) =>
                                form.setData('name', e.target.value)
                            }
                            placeholder={t('Standard')}
                            required
                        />
                        {form.errors.name && (
                            <p className="text-xs text-destructive">
                                {form.errors.name}
                            </p>
                        )}
                    </div>

                    <div className="grid gap-1.5">
                        <Label htmlFor="plan-conversations">
                            {t('Monthly conversations')}
                        </Label>
                        <Input
                            id="plan-conversations"
                            type="number"
                            min={0}
                            value={form.data.monthly_conversations}
                            onChange={(e) =>
                                form.setData(
                                    'monthly_conversations',
                                    Number.parseInt(e.target.value, 10) || 0,
                                )
                            }
                            placeholder="500"
                            required
                        />
                        <p className="text-xs text-muted-foreground">
                            {t('0 = unlimited.')}
                        </p>
                        {form.errors.monthly_conversations && (
                            <p className="text-xs text-destructive">
                                {form.errors.monthly_conversations}
                            </p>
                        )}
                    </div>

                    <div className="grid gap-1.5">
                        <Label htmlFor="plan-price">
                            {t('Monthly price (:currency)', {
                                currency: currency.toUpperCase(),
                            })}
                        </Label>
                        <Input
                            id="plan-price"
                            type="text"
                            inputMode="decimal"
                            value={centsToDollars(form.data.price_cents)}
                            onChange={(e) =>
                                form.setData(
                                    'price_cents',
                                    dollarsToCents(e.target.value),
                                )
                            }
                            placeholder="49.00"
                        />
                        <p className="text-xs text-muted-foreground">
                            {t('Set to 0 for free / custom-quote plans.')}
                        </p>
                        {form.errors.price_cents && (
                            <p className="text-xs text-destructive">
                                {form.errors.price_cents}
                            </p>
                        )}
                    </div>

                    <div className="grid gap-1.5">
                        <Label htmlFor="plan-yearly-price">
                            {t('Yearly price (:currency, optional)', {
                                currency: currency.toUpperCase(),
                            })}
                        </Label>
                        <Input
                            id="plan-yearly-price"
                            type="text"
                            inputMode="decimal"
                            value={
                                form.data.yearly_price_cents !== null
                                    ? centsToDollars(
                                          form.data.yearly_price_cents,
                                      )
                                    : ''
                            }
                            onChange={(e) =>
                                form.setData(
                                    'yearly_price_cents',
                                    e.target.value === ''
                                        ? null
                                        : dollarsToCents(e.target.value),
                                )
                            }
                            placeholder="490.00"
                        />
                        <p className="text-xs text-muted-foreground">
                            {t(
                                'Leave empty to hide the Yearly toggle for this plan. Typical discount is 2 months free (≈ monthly × 10).',
                            )}
                        </p>
                        {form.errors.yearly_price_cents && (
                            <p className="text-xs text-destructive">
                                {form.errors.yearly_price_cents}
                            </p>
                        )}
                    </div>

                    <div className="grid gap-1.5">
                        <Label htmlFor="plan-interval">
                            {t('Billing interval')}
                        </Label>
                        <div
                            id="plan-interval"
                            className="inline-flex rounded-md border bg-muted/50 p-1 text-sm"
                            role="radiogroup"
                            aria-label={t('Billing interval')}
                        >
                            {(
                                [
                                    { v: 'month', label: t('Monthly') },
                                    { v: 'year', label: t('Yearly') },
                                ] as const
                            ).map((opt) => (
                                <button
                                    key={opt.v}
                                    type="button"
                                    role="radio"
                                    aria-checked={form.data.interval === opt.v}
                                    onClick={() =>
                                        form.setData('interval', opt.v)
                                    }
                                    className={`rounded px-3 py-1 transition ${
                                        form.data.interval === opt.v
                                            ? 'bg-background font-semibold shadow-sm'
                                            : 'text-muted-foreground hover:text-foreground'
                                    }`}
                                >
                                    {opt.label}
                                </button>
                            ))}
                        </div>
                        <p className="text-xs text-muted-foreground">
                            {t(
                                'For an annual variant of an existing plan, create a separate row with the same display name and interval = Yearly. The pricing page groups them under one toggle.',
                            )}
                        </p>
                        {form.errors.interval && (
                            <p className="text-xs text-destructive">
                                {form.errors.interval}
                            </p>
                        )}
                    </div>

                    <div className="grid gap-1.5">
                        <Label className="flex items-center gap-2">
                            <input
                                type="checkbox"
                                checked={form.data.is_active}
                                onChange={(e) =>
                                    form.setData('is_active', e.target.checked)
                                }
                                className="size-4"
                            />
                            <span>{t('Active (purchasable)')}</span>
                        </Label>
                        <p className="text-xs text-muted-foreground">
                            {t(
                                "Inactive plans stay in the DB but don't appear on the marketing pricing page or Stripe checkout.",
                            )}
                        </p>
                    </div>

                    <div className="grid gap-1.5">
                        <Label className="flex items-center gap-2">
                            <input
                                type="checkbox"
                                checked={form.data.show_on_pricing_page}
                                onChange={(e) =>
                                    form.setData(
                                        'show_on_pricing_page',
                                        e.target.checked,
                                    )
                                }
                                className="size-4"
                            />
                            <span>{t('Show on public /pricing page')}</span>
                        </Label>
                        <p className="text-xs text-muted-foreground">
                            {t(
                                'Hide a card when an Enterprise / Custom CTA text below the grid already covers that audience. The plan remains purchasable via direct link.',
                            )}
                        </p>
                    </div>

                    <div className="grid gap-1.5">
                        <Label className="flex items-center gap-2">
                            <input
                                type="checkbox"
                                checked={form.data.is_default_for_signup}
                                onChange={(e) =>
                                    form.setData(
                                        'is_default_for_signup',
                                        e.target.checked,
                                    )
                                }
                                className="size-4"
                            />
                            <span>{t('Default plan for new signups')}</span>
                        </Label>
                        <p className="text-xs text-muted-foreground">
                            {t(
                                'When a new user signs up, this is the plan their first workspace is attached to. Only one plan may be the default — saving this checked demotes the previous default automatically.',
                            )}
                        </p>
                    </div>

                    <div className="grid gap-1.5">
                        <Label className="flex items-center gap-2">
                            <input
                                type="checkbox"
                                checked={form.data.is_trial}
                                onChange={(e) =>
                                    form.setData('is_trial', e.target.checked)
                                }
                                className="size-4"
                            />
                            <span>{t('Free trial plan')}</span>
                        </Label>
                        <p className="text-xs text-muted-foreground">
                            {t(
                                'New signups on this plan get a no-credit-card trial. When it ends, the workspace is blocked until they upgrade to a paid plan. Combine with “Default plan for new signups” to put every new user on a trial.',
                            )}
                        </p>
                        {form.data.is_trial && (
                            <div className="mt-1 grid gap-1.5">
                                <Label htmlFor="plan-trial-days">
                                    {t('Trial length (days)')}
                                </Label>
                                <Input
                                    id="plan-trial-days"
                                    type="number"
                                    min={1}
                                    max={365}
                                    value={form.data.trial_days ?? ''}
                                    onChange={(e) =>
                                        form.setData(
                                            'trial_days',
                                            e.target.value === ''
                                                ? null
                                                : Number(e.target.value),
                                        )
                                    }
                                    placeholder="14"
                                    className="max-w-[160px]"
                                />
                                <InputError message={form.errors.trial_days} />
                                <p className="text-xs text-muted-foreground">
                                    {t(
                                        'Common values: 7, 14, or 30. Defaults to 14 if left blank.',
                                    )}
                                </p>
                            </div>
                        )}
                    </div>

                    {/* C6: billing model. Lifetime + one-time hide the
                        interval selector since they don't renew. */}
                    <div className="grid gap-1.5 md:col-span-2">
                        <Label htmlFor="plan-billing-model">
                            {t('Billing model')}
                        </Label>
                        <select
                            id="plan-billing-model"
                            value={form.data.billing_model ?? 'subscription'}
                            onChange={(e) =>
                                form.setData(
                                    'billing_model',
                                    e.target.value as
                                        | 'subscription'
                                        | 'lifetime'
                                        | 'one_time',
                                )
                            }
                            className="w-full max-w-xs rounded-md border bg-background px-3 py-2 text-sm"
                        >
                            <option value="subscription">
                                {t('Subscription (recurring)')}
                            </option>
                            <option value="lifetime">
                                {t(
                                    'Lifetime deal (one-time, permanent access)',
                                )}
                            </option>
                            <option value="one_time">
                                {t('One-time charge')}
                            </option>
                        </select>
                        <p className="text-xs text-muted-foreground">
                            {t(
                                'Lifetime plans never renew. The buyer pays once and the workspace keeps access forever (until you revoke it).',
                            )}
                        </p>
                    </div>
                </div>

                {/* C2: per-currency overrides. USD always mirrors the
                    main Price field above. Operators add EUR / INR /
                    GBP / etc. here; Stripe Prices are minted lazily on
                    first checkout in each currency. */}
                <div className="mt-6 grid gap-3">
                    <h3 className="text-sm font-semibold">
                        {t('Multi-currency prices (optional)')}
                    </h3>
                    <p className="text-xs text-muted-foreground">
                        {t(
                            'Add a per-currency override in minor units (cents-equivalent). USD always uses the main Price field above. Visitor sees their currency auto-detected from country / language, with a dropdown to switch.',
                        )}
                    </p>
                    <CurrencyRows
                        prices={form.data.prices ?? {}}
                        onChange={(p) => form.setData('prices', p)}
                    />
                </div>
            </Card>

            <Card className="p-4">
                <h2 className="font-semibold">{t('AI rate limits')}</h2>
                <p className="mb-4 text-xs text-muted-foreground">
                    {t(
                        'Optional dials for throttling LLM cost. Both fields default to "no extra cap" — leave blank to gate on the monthly conversation count alone.',
                    )}
                </p>

                <div className="grid gap-4 md:grid-cols-2">
                    <div className="grid gap-1.5">
                        <Label htmlFor="plan-monthly-messages">
                            {t('Monthly messages cap')}
                        </Label>
                        <Input
                            id="plan-monthly-messages"
                            type="number"
                            min={0}
                            value={form.data.monthly_messages ?? ''}
                            onChange={(e) => {
                                const raw = e.target.value;
                                form.setData(
                                    'monthly_messages',
                                    raw === ''
                                        ? null
                                        : Number.parseInt(raw, 10) || 0,
                                );
                            }}
                            placeholder={t('leave blank for no cap')}
                        />
                        <p className="text-xs text-muted-foreground">
                            {t(
                                'Counts every visitor message. The widget returns a "quota exceeded" error when the workspace passes this threshold for the current calendar month.',
                            )}
                        </p>
                        {form.errors.monthly_messages && (
                            <p className="text-xs text-destructive">
                                {form.errors.monthly_messages}
                            </p>
                        )}
                    </div>

                    <div className="grid gap-1.5">
                        <Label htmlFor="plan-max-tokens">
                            {t('Max tokens per response')}
                        </Label>
                        <Input
                            id="plan-max-tokens"
                            type="number"
                            min={100}
                            max={8000}
                            value={form.data.max_tokens_per_response ?? ''}
                            onChange={(e) => {
                                const raw = e.target.value;
                                form.setData(
                                    'max_tokens_per_response',
                                    raw === ''
                                        ? null
                                        : Number.parseInt(raw, 10) || 0,
                                );
                            }}
                            placeholder={t('leave blank for default (800)')}
                        />
                        <p className="text-xs text-muted-foreground">
                            {t(
                                'Caps each LLM reply at this many tokens. Useful for keeping the free tier short and the paid tiers verbose. Must be ≥ 100, ≤ 8000.',
                            )}
                        </p>
                        {form.errors.max_tokens_per_response && (
                            <p className="text-xs text-destructive">
                                {form.errors.max_tokens_per_response}
                            </p>
                        )}
                    </div>
                </div>
            </Card>

            <Card className="p-4">
                <h2 className="font-semibold">{t('Resource limits')}</h2>
                <p className="mb-4 text-xs text-muted-foreground">
                    {t(
                        'Cap how many of each resource a workspace can create on this plan. Leave blank for "unlimited" (legacy default). Set to 0 to block the feature entirely (e.g. Free tier without integrations).',
                    )}
                </p>

                <div className="grid gap-4 md:grid-cols-2">
                    {(
                        [
                            ['agents_limit', 'Agents'],
                            ['sources_limit', 'Knowledge sources'],
                            ['workflows_limit', 'Workflows'],
                            ['integrations_limit', 'Integrations'],
                            ['members_limit', 'Members (seats)'],
                            ['workspaces_limit', 'Workspaces per owner'],
                        ] as const
                    ).map(([key, label]) => (
                        <div key={key} className="grid gap-1.5">
                            <Label htmlFor={`plan-${key}`}>{t(label)}</Label>
                            <Input
                                id={`plan-${key}`}
                                type="number"
                                min={0}
                                value={form.data[key] ?? ''}
                                onChange={(e) => {
                                    const raw = e.target.value;
                                    form.setData(
                                        key,
                                        raw === ''
                                            ? null
                                            : Math.max(
                                                  0,
                                                  Number.parseInt(raw, 10) || 0,
                                              ),
                                    );
                                }}
                                placeholder={t('blank = unlimited')}
                            />
                            {form.errors[key] && (
                                <p className="text-xs text-destructive">
                                    {form.errors[key]}
                                </p>
                            )}
                        </div>
                    ))}

                    <div className="grid gap-1.5">
                        <Label className="flex items-center gap-2">
                            <input
                                type="checkbox"
                                checked={form.data.api_access}
                                onChange={(e) =>
                                    form.setData('api_access', e.target.checked)
                                }
                                className="size-4"
                            />
                            <span>{t('Allow API tokens')}</span>
                        </Label>
                        <p className="text-xs text-muted-foreground">
                            {t(
                                'Workspaces on this plan can mint and use API tokens (WordPress plugin, Sources API). Disable for Free / starter tiers.',
                            )}
                        </p>
                    </div>
                </div>
            </Card>

            <Card className="p-4">
                <h2 className="font-semibold">{t('Features')}</h2>
                <p className="mb-4 text-xs text-muted-foreground">
                    {t(
                        'Plan-level capability flags. Stored as JSON on the plan row; consumed by middleware / UI gates.',
                    )}
                </p>

                <Label className="flex items-center gap-2">
                    <input
                        type="checkbox"
                        checked={form.data.features.remove_branding}
                        onChange={(e) =>
                            form.setData('features', {
                                ...form.data.features,
                                remove_branding: e.target.checked,
                            })
                        }
                        className="size-4"
                    />
                    <span className="text-sm">
                        {t('Remove "Powered by" widget branding')}
                    </span>
                </Label>
            </Card>

            {readonly && (
                <Card className="p-4">
                    <h2 className="font-semibold">{t('Stripe')}</h2>
                    <p className="mb-3 text-xs text-muted-foreground">
                        {t(
                            'These are managed automatically when you save. Read-only for reference.',
                        )}
                    </p>

                    <div className="grid gap-3 md:grid-cols-2">
                        <div>
                            <Label className="text-xs text-muted-foreground">
                                {t('Product ID')}
                            </Label>
                            <p className="mt-1 font-mono text-xs break-all">
                                {readonly.stripe_product_id ?? (
                                    <span className="text-muted-foreground/60">
                                        {t('not synced yet (paid plans only)')}
                                    </span>
                                )}
                            </p>
                        </div>
                        <div>
                            <Label className="text-xs text-muted-foreground">
                                {t('Price ID')}
                            </Label>
                            <p className="mt-1 font-mono text-xs break-all">
                                {readonly.stripe_price_id ?? (
                                    <span className="text-muted-foreground/60">
                                        {t('not synced yet (paid plans only)')}
                                    </span>
                                )}
                            </p>
                        </div>
                    </div>

                    {isPaid && (
                        <p className="mt-3 text-xs text-muted-foreground">
                            {t(
                                'Changing the price archives the current Stripe Price and creates a new one. Existing subscriptions stay on the old price until you migrate them.',
                            )}
                        </p>
                    )}
                </Card>
            )}

            <div className="flex items-center justify-end gap-2">
                <Button asChild type="button" variant="ghost">
                    <Link href="/admin/plans">{t('Cancel')}</Link>
                </Button>
                <Button type="submit" disabled={form.processing}>
                    {form.processing ? t('Saving…') : submitLabel}
                </Button>
            </div>
        </form>
    );
}
