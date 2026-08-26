import { Head, useForm } from '@inertiajs/react';
import {
    AlertCircle,
    CheckCircle2,
    CreditCard,
    Database,
    Globe2,
    HardDrive,
    LayoutDashboard,
    Loader2,
    Mail,
    Palette,
    PanelBottom,
    PanelTop,
    Radio,
    Route as RouteIcon,
    Shield,
    Sparkles,
    ToggleRight,
    Upload,
    Wallet,
    Workflow,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import BrandLockup from '@/components/brand-lockup';
import { useConfirm } from '@/components/confirm-dialog-provider';
import Heading from '@/components/heading';
import { EmbedModelPicker } from '@/components/settings/embed-model-picker';
import { ModelPicker } from '@/components/settings/model-picker';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Separator } from '@/components/ui/separator';
import { useBranding } from '@/hooks/use-branding';
import { BRAND_DISPLAY_OPTIONS } from '@/lib/brand-display';
import type { BrandDisplayMode } from '@/lib/brand-display';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { update as updateSystemSettings } from '@/routes/settings/system';
import MarketingContentEditor from './marketing-content-editor';
import type { MarketingContentFormValue } from './marketing-content-editor';
import PrivacyPolicyEditor from './privacy-policy-editor';
import type { PrivacyPolicyFormValue } from './privacy-policy-editor';

type MailSummary = {
    driver: string;
    host: string | null;
    port: number | null;
    encryption: string | null;
    username: string | null;
    from_address: string | null;
    from_name: string | null;
    configured: boolean;
};

type StripeSummary = {
    public_key: string | null;
    secret: string | null;
    webhook_secret: string | null;
    currency: string | null;
    enabled?: boolean;
    configured: boolean;
};

type PayPalSummary = {
    mode: string;
    client_id: string | null;
    client_secret: string | null;
    webhook_id: string | null;
    enabled: boolean;
    configured: boolean;
};

type RazorpaySummary = {
    key_id: string | null;
    key_secret: string | null;
    webhook_secret: string | null;
    enabled: boolean;
    configured: boolean;
};

type ModelCatalogEntry = {
    id: string;
    label: string;
    provider: 'cloudflare' | 'openai' | 'openrouter';
    ttft_ms: number;
    tier: 'fast' | 'medium' | 'slow';
    cost: string;
    context_tokens: number;
    supports_tools: boolean;
    recommended: boolean;
    notes: string;
};

type ModelLatency = {
    ok: boolean;
    ttft_ms: number | null;
    total_ms: number | null;
    measured_at: string | null;
    error: string | null;
    provider: string;
    model: string;
};

type EmbedCatalogEntry = {
    id: string;
    label: string;
    provider: 'cloudflare' | 'openai' | 'openrouter';
    dimensions: number;
    max_input_tokens: number;
    languages: string;
    cost: string;
    recommended: boolean;
    notes: string;
};

type LlmSummary = {
    provider_env: string;
    resolved: 'cloudflare' | 'openrouter' | 'openai' | 'fake';
    cloudflare_account: string | null;
    cloudflare_chat_model: string;
    openai_key: string | null;
    openai_chat_model: string;
    openrouter_key: string | null;
    openrouter_chat_model: string;
    configured: boolean;
    model_catalog: Record<
        'cloudflare' | 'openai' | 'openrouter',
        ModelCatalogEntry[]
    >;
    model_latencies: Record<
        'cloudflare' | 'openai' | 'openrouter',
        ModelLatency | null
    >;
    embed_catalog: Record<
        'cloudflare' | 'openai' | 'openrouter',
        EmbedCatalogEntry[]
    >;
    current_vector_dim: number;
};

type CacheSummary = {
    driver: string;
    redis_host: string | null;
    redis_port: string | null;
    configured: boolean;
};

type VectorSummary = {
    provider_env: string;
    resolved: string;
    vectorize_index: string;
    qdrant_url: string | null;
    configured: boolean;
};

type ReverbSummary = {
    app_key: string | null;
    host: string;
    port: number;
    scheme: string;
    configured: boolean;
};

type MarketingSummary = {
    customized: boolean;
    agent_options?: Array<{ id: string; label: string }>;
    theme_options?: Array<{
        slug: string;
        name: string;
        description: string | null;
    }>;
};

type PrivacySummary = {
    customized: boolean;
};

type FormValues = {
    stripe_key: string | null;
    stripe_secret_set: boolean;
    stripe_webhook_secret_set: boolean;
    cashier_currency: string | null;
    stripe_enabled: boolean;
    paypal_enabled: boolean;
    razorpay_enabled: boolean;
    paypal_mode: string;
    paypal_client_id: string | null;
    paypal_client_secret_set: boolean;
    paypal_webhook_id: string | null;
    razorpay_key_id: string | null;
    razorpay_key_secret_set: boolean;
    razorpay_webhook_secret_set: boolean;
    cloudflare_account_id: string | null;
    cloudflare_api_token_set: boolean;
    cloudflare_chat_model: string | null;
    cloudflare_embed_model: string | null;
    cloudflare_vectorize_index: string | null;
    cloudflare_ai_gateway_url: string | null;
    cloudflare_browser_rendering: boolean;
    openai_api_key_set: boolean;
    openai_chat_model: string | null;
    openai_embed_model: string | null;
    openrouter_api_key_set: boolean;
    openrouter_chat_model: string | null;
    llm_provider: string | null;
    vector_provider: string | null;
    mail_driver: string | null;
    mail_host: string | null;
    mail_port: number | null;
    mail_encryption: string | null;
    mail_username: string | null;
    mail_password_set: boolean;
    mail_from_address: string | null;
    mail_from_name: string | null;
    site_title: string | null;
    header_logo_url: string | null;
    footer_logo_url: string | null;
    dashboard_logo_url: string | null;
    header_logo_dark_url: string | null;
    footer_logo_dark_url: string | null;
    dashboard_logo_dark_url: string | null;
    favicon_url: string | null;
    header_brand_display: BrandDisplayMode;
    footer_brand_display: BrandDisplayMode;
    dashboard_brand_display: BrandDisplayMode;
    pitchbar_brand_url: string | null;
    auth_aside_eyebrow?: string;
    auth_aside_heading?: string;
    auth_aside_lede?: string;
    auth_aside_bullets?: string[];
    pitchbar_brand_label: string | null;
    marketing_site_enabled: boolean;
    marketing_widget_enabled: boolean;
    marketing_widget_agent_id: string | null;
    marketing_theme: string;
    marketing_home_content: MarketingContentFormValue;
    privacy_policy_content: PrivacyPolicyFormValue;
    pricing_faqs?: Array<{ q: string; a: string }>;
    pricing_matrix?: Array<Record<string, unknown>>;
    integrations_enabled?: {
        slack?: boolean;
        notion?: boolean;
        google?: boolean;
        webhooks?: boolean;
        wordpress?: boolean;
    };
    integration_cards?: Array<{
        key: string;
        name: string;
        category: string;
        tagline: string;
        description: string;
    }>;
    byok_enabled_globally: boolean;
    admin_daily_digest_enabled: boolean;
    require_email_verification: boolean;
    wordpress_plugin_download_url?: string;
    wordpress_plugin_help_text?: string;
};

type SettingsPage = 'system' | 'branding' | 'marketing' | 'privacy';

type CronTickRow = {
    at: string | null;
    processed: number;
    failed: number;
    remaining: number;
    ms: number;
};

type CronWorkerSummary = {
    deployed: boolean;
    worker_name: string | null;
    deployed_at: string | null;
    last_status: Record<string, unknown> | null;
    last_status_at: string | null;
    cloudflare_configured: boolean;
    callback_url: string;
    // Health stats from cron_tick_logs (every-tick log written by
    // QueueTickController). All optional so older Inertia payloads
    // pre-deploy don't crash the new component.
    last_tick_at?: string | null;
    seconds_since_last_tick?: number | null;
    liveness?: 'live' | 'stale' | 'dead' | 'unknown';
    ticks_last_hour?: number;
    ticks_last_24h?: number;
    jobs_processed_last_hour?: number;
    jobs_processed_last_24h?: number;
    pending_jobs?: number;
    failed_jobs?: number;
    recent_ticks?: CronTickRow[];
};

type Props = {
    page: SettingsPage;
    sections: {
        mail: MailSummary;
        stripe: StripeSummary;
        paypal: PayPalSummary;
        razorpay: RazorpaySummary;
        llm: LlmSummary;
        cache: CacheSummary;
        vector: VectorSummary;
        reverb: ReverbSummary;
        marketing: MarketingSummary;
        privacy: PrivacySummary;
        cron_worker: CronWorkerSummary;
    };
    form: FormValues;
};

type TestResult = { ok: boolean; message: string } | null;

function StatusPill({ configured }: { configured: boolean }) {
    const { t } = useT();

    return (
        <span
            className={`inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[10px] font-medium tracking-wide uppercase ${
                configured
                    ? 'bg-emerald-500/15 text-emerald-700 dark:text-emerald-400'
                    : 'bg-amber-500/15 text-amber-700 dark:text-amber-400'
            }`}
        >
            {configured ? (
                <CheckCircle2 className="size-3" />
            ) : (
                <AlertCircle className="size-3" />
            )}
            {configured ? t('configured') : t('missing')}
        </span>
    );
}

function ResultBanner({ result }: { result: TestResult }) {
    if (result === null) {
        return null;
    }

    return (
        <div
            className={`mt-3 flex items-start gap-2 rounded border p-3 text-xs ${
                result.ok
                    ? 'border-emerald-500/30 bg-emerald-500/5 text-emerald-700 dark:text-emerald-400'
                    : 'border-rose-500/30 bg-rose-500/5 text-rose-700 dark:text-rose-400'
            }`}
        >
            {result.ok ? (
                <CheckCircle2 className="mt-0.5 size-4 shrink-0" />
            ) : (
                <AlertCircle className="mt-0.5 size-4 shrink-0" />
            )}
            <span className="break-all">{result.message}</span>
        </div>
    );
}

function SectionShell({
    icon: Icon,
    title,
    description,
    statusPill,
    testEndpoint,
    testLabel,
    secondaryTestEndpoint,
    secondaryTestLabel,
    children,
}: {
    icon: LucideIcon;
    title: string;
    description: string | React.ReactNode;
    statusPill?: React.ReactNode;
    testEndpoint?: string;
    testLabel?: string;
    /**
     * Optional second test button shown next to the primary one.
     * Used by the Mail section to expose both a raw SMTP probe AND
     * an end-to-end NewLeadCaptured-via-queue probe — buyer reports
     * of "leads not arriving" trace back to either misconfigured
     * mail OR a missing queue worker, and the two probes catch the
     * different failure modes.
     */
    secondaryTestEndpoint?: string;
    secondaryTestLabel?: string;
    children: React.ReactNode;
}) {
    const { t } = useT();
    const [runningPrimary, setRunningPrimary] = useState(false);
    const [runningSecondary, setRunningSecondary] = useState(false);
    const [result, setResult] = useState<TestResult>(null);

    const run = async (endpoint: string, isSecondary: boolean) => {
        if (isSecondary) {
            setRunningSecondary(true);
        } else {
            setRunningPrimary(true);
        }

        setResult(null);

        try {
            const csrf =
                (
                    document.querySelector(
                        'meta[name="csrf-token"]',
                    ) as HTMLMetaElement | null
                )?.content ?? '';
            const response = await fetch(endpoint, {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': csrf,
                    'X-Requested-With': 'XMLHttpRequest',
                    Accept: 'application/json',
                },
                credentials: 'same-origin',
            });
            const json = (await response.json()) as TestResult;
            setResult(
                json ?? {
                    ok: false,
                    message: t('Unexpected response: HTTP :status', {
                        status: response.status,
                    }),
                },
            );
        } catch (e) {
            setResult({
                ok: false,
                message: e instanceof Error ? e.message : String(e),
            });
        } finally {
            if (isSecondary) {
                setRunningSecondary(false);
            } else {
                setRunningPrimary(false);
            }
        }
    };

    return (
        <Card className="border-border/80 p-5">
            <div className="mb-4 flex items-start justify-between gap-3">
                <div className="flex items-start gap-3">
                    <div className="rounded-lg bg-foreground/5 p-2">
                        <Icon className="size-4" />
                    </div>
                    <div>
                        <div className="flex items-center gap-2">
                            <h2 className="font-semibold">{title}</h2>
                            {statusPill}
                        </div>
                        <p className="mt-0.5 text-xs text-muted-foreground">
                            {description}
                        </p>
                    </div>
                </div>
                <div className="flex flex-wrap items-center gap-2">
                    {testEndpoint && (
                        <Button
                            size="sm"
                            variant="outline"
                            type="button"
                            onClick={() => run(testEndpoint, false)}
                            disabled={runningPrimary || runningSecondary}
                        >
                            {runningPrimary ? (
                                <Loader2 className="size-3 animate-spin" />
                            ) : null}
                            {runningPrimary
                                ? t('Testing…')
                                : (testLabel ?? t('Run test'))}
                        </Button>
                    )}
                    {secondaryTestEndpoint && (
                        <Button
                            size="sm"
                            variant="outline"
                            type="button"
                            onClick={() => run(secondaryTestEndpoint, true)}
                            disabled={runningPrimary || runningSecondary}
                        >
                            {runningSecondary ? (
                                <Loader2 className="size-3 animate-spin" />
                            ) : null}
                            {runningSecondary
                                ? t('Testing…')
                                : (secondaryTestLabel ??
                                  t('Run secondary test'))}
                        </Button>
                    )}
                </div>
            </div>
            <div className="space-y-4">{children}</div>
            <ResultBanner result={result} />
        </Card>
    );
}

function BrandSurfacePreview({
    title,
    caption,
    icon: Icon,
    siteTitle,
    logoUrl,
    mode,
    shellClassName,
    logoClassName,
    textClassName,
}: {
    title: string;
    caption: string;
    icon: LucideIcon;
    siteTitle: string;
    logoUrl?: string | null;
    mode: BrandDisplayMode;
    shellClassName?: string;
    logoClassName?: string;
    textClassName?: string;
}) {
    return (
        <div className="rounded-xl border border-border/70 bg-card/80 p-3 shadow-sm">
            <div className="flex items-center gap-2">
                <span className="flex size-8 shrink-0 items-center justify-center rounded-lg border border-border/70 bg-background text-muted-foreground shadow-xs">
                    <Icon className="size-4" />
                </span>
                <div className="min-w-0">
                    <p className="text-xs font-semibold text-foreground">
                        {title}
                    </p>
                    <p className="text-[11px] leading-5 text-muted-foreground">
                        {caption}
                    </p>
                </div>
            </div>

            <div
                className={cn(
                    'mt-3 flex min-h-[86px] items-center rounded-xl border border-dashed border-border/70 p-3',
                    shellClassName,
                )}
            >
                <BrandLockup
                    siteTitle={siteTitle}
                    logoUrl={logoUrl}
                    mode={mode}
                    className="min-w-0 gap-2.5"
                    logoClassName={cn('h-9 max-w-[150px]', logoClassName)}
                    textClassName={cn(
                        'truncate text-sm font-semibold tracking-tight text-foreground',
                        textClassName,
                    )}
                />
            </div>
        </div>
    );
}

function FaviconSurfacePreview({
    siteTitle,
    faviconUrl,
}: {
    siteTitle: string;
    faviconUrl?: string | null;
}) {
    const { t } = useT();
    const initial = (siteTitle.trim().charAt(0) || 'S').toUpperCase();

    return (
        <div className="rounded-xl border border-border/70 bg-card/80 p-3 shadow-sm">
            <div className="flex items-center gap-2">
                <span className="flex size-8 shrink-0 items-center justify-center rounded-lg border border-border/70 bg-background text-muted-foreground shadow-xs">
                    <Globe2 className="size-4" />
                </span>
                <div className="min-w-0">
                    <p className="text-xs font-semibold text-foreground">
                        {t('Browser tab')}
                    </p>
                    <p className="text-[11px] leading-5 text-muted-foreground">
                        {t('Favicon + page title shell')}
                    </p>
                </div>
            </div>

            <div className="mt-3 rounded-xl border border-dashed border-border/70 bg-gradient-to-br from-background to-muted/35 p-3">
                <div className="flex items-center gap-2 rounded-lg border border-border/70 bg-background px-2.5 py-2 shadow-xs">
                    {faviconUrl ? (
                        <img
                            src={faviconUrl}
                            alt=""
                            className="size-4 shrink-0 rounded-[4px] object-contain"
                        />
                    ) : (
                        <span className="flex size-4 shrink-0 items-center justify-center rounded-[4px] bg-foreground text-[10px] font-bold text-background">
                            {initial}
                        </span>
                    )}
                    <span className="truncate text-xs font-medium text-foreground">
                        {siteTitle || t('Site title')}
                    </span>
                </div>
            </div>
        </div>
    );
}

function useSelectedFilePreview(selectedFile: File | null): string | null {
    const previewUrl = useMemo(
        () => (selectedFile ? URL.createObjectURL(selectedFile) : null),
        [selectedFile],
    );

    useEffect(() => {
        if (!previewUrl) {
            return;
        }

        return () => {
            URL.revokeObjectURL(previewUrl);
        };
    }, [previewUrl]);

    return previewUrl;
}

/**
 * Dedicated input for sensitive fields. When the server reports the
 * value is `_set`, the input shows a "•••• stored" placeholder until
 * the admin starts typing — making it obvious that the secret is
 * already stored without leaking it.
 */
function SecretInput({
    id,
    value,
    isSet,
    onChange,
    placeholder,
}: {
    id: string;
    value: string;
    isSet: boolean;
    onChange: (v: string) => void;
    placeholder?: string;
}) {
    const { t } = useT();
    const fallbackPlaceholder = placeholder ?? t('Leave blank to keep current');

    return (
        <Input
            id={id}
            type="password"
            autoComplete="off"
            spellCheck={false}
            value={value}
            placeholder={
                isSet
                    ? t('•••• stored — leave blank to keep')
                    : fallbackPlaceholder
            }
            onChange={(e) => onChange(e.target.value)}
        />
    );
}

function StripeSection({
    summary,
    initial,
}: {
    summary: StripeSummary;
    initial: FormValues;
}) {
    const { t } = useT();
    const form = useForm<{
        stripe_key: string;
        stripe_secret: string;
        stripe_webhook_secret: string;
        cashier_currency: string;
    }>({
        stripe_key: initial.stripe_key ?? '',
        stripe_secret: '',
        stripe_webhook_secret: '',
        cashier_currency: initial.cashier_currency ?? '',
    });

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        form.patch('/settings/system/stripe', { preserveScroll: true });
    };

    return (
        <SectionShell
            icon={CreditCard}
            title={t('Stripe')}
            description={t(
                'Subscription billing, webhook signatures, and Cashier.',
            )}
            statusPill={<StatusPill configured={summary.configured} />}
            testEndpoint="/settings/system/test/stripe"
        >
            <form onSubmit={submit} className="grid gap-3">
                <div className="grid gap-1">
                    <Label htmlFor="stripe_key">{t('Public key')}</Label>
                    <Input
                        id="stripe_key"
                        autoComplete="off"
                        value={form.data.stripe_key}
                        onChange={(e) =>
                            form.setData('stripe_key', e.target.value)
                        }
                        placeholder="pk_live_…"
                    />
                </div>
                <div className="grid gap-1">
                    <Label htmlFor="stripe_secret">{t('Secret key')}</Label>
                    <SecretInput
                        id="stripe_secret"
                        value={form.data.stripe_secret}
                        isSet={initial.stripe_secret_set}
                        onChange={(v) => form.setData('stripe_secret', v)}
                        placeholder="sk_live_…"
                    />
                </div>
                <div className="grid gap-1">
                    <Label htmlFor="stripe_webhook_secret">
                        {t('Webhook signing secret')}
                    </Label>
                    <SecretInput
                        id="stripe_webhook_secret"
                        value={form.data.stripe_webhook_secret}
                        isSet={initial.stripe_webhook_secret_set}
                        onChange={(v) =>
                            form.setData('stripe_webhook_secret', v)
                        }
                        placeholder="whsec_…"
                    />
                </div>
                <div className="grid gap-1">
                    <Label htmlFor="cashier_currency">{t('Currency')}</Label>
                    <Input
                        id="cashier_currency"
                        autoComplete="off"
                        value={form.data.cashier_currency}
                        onChange={(e) =>
                            form.setData('cashier_currency', e.target.value)
                        }
                        placeholder="usd"
                    />
                </div>
                <div className="flex justify-end">
                    <Button type="submit" disabled={form.processing}>
                        {t('Save Stripe')}
                    </Button>
                </div>
            </form>
        </SectionShell>
    );
}

function PayPalSection({
    summary,
    initial,
}: {
    summary: PayPalSummary;
    initial: FormValues;
}) {
    const { t } = useT();
    const form = useForm<{
        paypal_mode: string;
        paypal_client_id: string;
        paypal_client_secret: string;
        paypal_webhook_id: string;
    }>({
        paypal_mode: initial.paypal_mode || 'sandbox',
        paypal_client_id: initial.paypal_client_id ?? '',
        paypal_client_secret: '',
        paypal_webhook_id: initial.paypal_webhook_id ?? '',
    });

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        form.patch('/settings/system/paypal', { preserveScroll: true });
    };

    return (
        <SectionShell
            icon={Wallet}
            title={t('PayPal')}
            description={t(
                'Subscriptions via PayPal Billing Plans (sandbox or live mode).',
            )}
            statusPill={<StatusPill configured={summary.configured} />}
            testEndpoint="/settings/system/test/paypal"
        >
            <form onSubmit={submit} className="grid gap-3">
                <div className="grid gap-1">
                    <Label htmlFor="paypal_mode">{t('Mode')}</Label>
                    <Select
                        value={form.data.paypal_mode || 'sandbox'}
                        onValueChange={(v) => form.setData('paypal_mode', v)}
                    >
                        <SelectTrigger id="paypal_mode">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="sandbox">
                                {t('sandbox')}
                            </SelectItem>
                            <SelectItem value="live">{t('live')}</SelectItem>
                        </SelectContent>
                    </Select>
                </div>
                <div className="grid gap-1">
                    <Label htmlFor="paypal_client_id">{t('Client ID')}</Label>
                    <Input
                        id="paypal_client_id"
                        autoComplete="off"
                        value={form.data.paypal_client_id}
                        onChange={(e) =>
                            form.setData('paypal_client_id', e.target.value)
                        }
                        placeholder="Axx…"
                    />
                </div>
                <div className="grid gap-1">
                    <Label htmlFor="paypal_client_secret">
                        {t('Client secret')}
                    </Label>
                    <SecretInput
                        id="paypal_client_secret"
                        value={form.data.paypal_client_secret}
                        isSet={initial.paypal_client_secret_set}
                        onChange={(v) =>
                            form.setData('paypal_client_secret', v)
                        }
                        placeholder="EFx…"
                    />
                </div>
                <div className="grid gap-1">
                    <Label htmlFor="paypal_webhook_id">{t('Webhook ID')}</Label>
                    <Input
                        id="paypal_webhook_id"
                        autoComplete="off"
                        value={form.data.paypal_webhook_id}
                        onChange={(e) =>
                            form.setData('paypal_webhook_id', e.target.value)
                        }
                        placeholder="WH-…"
                    />
                </div>
                <div className="flex justify-end">
                    <Button type="submit" disabled={form.processing}>
                        {t('Save PayPal')}
                    </Button>
                </div>
            </form>
        </SectionShell>
    );
}

function RazorpaySection({
    summary,
    initial,
}: {
    summary: RazorpaySummary;
    initial: FormValues;
}) {
    const { t } = useT();
    const form = useForm<{
        razorpay_key_id: string;
        razorpay_key_secret: string;
        razorpay_webhook_secret: string;
    }>({
        razorpay_key_id: initial.razorpay_key_id ?? '',
        razorpay_key_secret: '',
        razorpay_webhook_secret: '',
    });

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        form.patch('/settings/system/razorpay', { preserveScroll: true });
    };

    return (
        <SectionShell
            icon={Wallet}
            title={t('Razorpay')}
            description={t(
                'Subscriptions via Razorpay Plans + Subscriptions API (UPI, cards, netbanking).',
            )}
            statusPill={<StatusPill configured={summary.configured} />}
            testEndpoint="/settings/system/test/razorpay"
        >
            <form onSubmit={submit} className="grid gap-3">
                <div className="grid gap-1">
                    <Label htmlFor="razorpay_key_id">{t('Key ID')}</Label>
                    <Input
                        id="razorpay_key_id"
                        autoComplete="off"
                        value={form.data.razorpay_key_id}
                        onChange={(e) =>
                            form.setData('razorpay_key_id', e.target.value)
                        }
                        placeholder="rzp_test_…"
                    />
                </div>
                <div className="grid gap-1">
                    <Label htmlFor="razorpay_key_secret">
                        {t('Key secret')}
                    </Label>
                    <SecretInput
                        id="razorpay_key_secret"
                        value={form.data.razorpay_key_secret}
                        isSet={initial.razorpay_key_secret_set}
                        onChange={(v) => form.setData('razorpay_key_secret', v)}
                        placeholder={t('Key secret from dashboard')}
                    />
                </div>
                <div className="grid gap-1">
                    <Label htmlFor="razorpay_webhook_secret">
                        {t('Webhook secret')}
                    </Label>
                    <SecretInput
                        id="razorpay_webhook_secret"
                        value={form.data.razorpay_webhook_secret}
                        isSet={initial.razorpay_webhook_secret_set}
                        onChange={(v) =>
                            form.setData('razorpay_webhook_secret', v)
                        }
                        placeholder={t('Webhook signing secret')}
                    />
                </div>
                <div className="flex justify-end">
                    <Button type="submit" disabled={form.processing}>
                        {t('Save Razorpay')}
                    </Button>
                </div>
            </form>
        </SectionShell>
    );
}

function GatewayTogglesSection({ initial }: { initial: FormValues }) {
    const { t } = useT();
    const form = useForm<{
        stripe_enabled: boolean;
        paypal_enabled: boolean;
        razorpay_enabled: boolean;
    }>({
        stripe_enabled: initial.stripe_enabled,
        paypal_enabled: initial.paypal_enabled,
        razorpay_enabled: initial.razorpay_enabled,
    });

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        form.patch('/settings/system/gateways', { preserveScroll: true });
    };

    const toggle = (
        key: 'stripe_enabled' | 'paypal_enabled' | 'razorpay_enabled',
    ) => (
        <label className="flex cursor-pointer items-center justify-between rounded border border-border p-3 text-sm">
            <span className="capitalize">{key.replace('_enabled', '')}</span>
            <input
                type="checkbox"
                checked={form.data[key]}
                onChange={(e) => form.setData(key, e.target.checked)}
            />
        </label>
    );

    return (
        <SectionShell
            icon={ToggleRight}
            title={t('Payment gateways')}
            description={t(
                'Enable or disable each gateway. Disabled gateways never appear on the customer billing page, even when credentials are configured.',
            )}
        >
            <form onSubmit={submit} className="grid gap-3">
                <div className="grid gap-2 sm:grid-cols-3">
                    {toggle('stripe_enabled')}
                    {toggle('paypal_enabled')}
                    {toggle('razorpay_enabled')}
                </div>
                <div className="flex justify-end">
                    <Button type="submit" disabled={form.processing}>
                        {t('Save gateway toggles')}
                    </Button>
                </div>
            </form>
        </SectionShell>
    );
}

function CloudflareSection({
    summary,
    initial,
}: {
    summary: LlmSummary;
    initial: FormValues;
}) {
    const { t } = useT();
    const form = useForm<{
        cloudflare_account_id: string;
        cloudflare_api_token: string;
        cloudflare_chat_model: string;
        cloudflare_embed_model: string;
        cloudflare_vectorize_index: string;
        cloudflare_ai_gateway_url: string;
        cloudflare_browser_rendering: boolean;
    }>({
        cloudflare_account_id: initial.cloudflare_account_id ?? '',
        cloudflare_api_token: '',
        cloudflare_chat_model: initial.cloudflare_chat_model ?? '',
        cloudflare_embed_model: initial.cloudflare_embed_model ?? '',
        cloudflare_vectorize_index: initial.cloudflare_vectorize_index ?? '',
        cloudflare_ai_gateway_url: initial.cloudflare_ai_gateway_url ?? '',
        cloudflare_browser_rendering:
            initial.cloudflare_browser_rendering ?? true,
    });

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        form.patch('/settings/system/cloudflare', { preserveScroll: true });
    };

    return (
        <SectionShell
            icon={Sparkles}
            title={t('Cloudflare (Workers AI + Vectorize)')}
            description={
                <>
                    {t(
                        'Default chat / embed / vector provider when keys are present. Cloudflare bills your account directly —',
                    )}{' '}
                    <a
                        href="/documentation/cloudflare-costs"
                        target="_blank"
                        rel="noopener noreferrer"
                        className="font-semibold underline-offset-2 hover:underline"
                    >
                        {t('what does this cost?')}
                    </a>
                </>
            }
            statusPill={
                <StatusPill configured={summary.resolved === 'cloudflare'} />
            }
        >
            <form onSubmit={submit} className="grid gap-3">
                <div className="rounded-md border border-amber-300/30 bg-amber-50/40 p-3 text-xs leading-5 text-amber-900 dark:border-amber-500/20 dark:bg-amber-500/5 dark:text-amber-200">
                    <p className="font-semibold">
                        {t('Required token permissions')}
                    </p>
                    <p className="mt-1">
                        {t(
                            'Create the token at My Profile → API Tokens → Create Token → Get started → Create Custom Token. Add ALL of these account-scoped permissions or some features will silently break:',
                        )}
                    </p>
                    <ul className="ms-4 mt-2 list-disc space-y-0.5">
                        <li>
                            <span className="font-mono text-[11px]">
                                Account → Workers AI → Read
                            </span>{' '}
                            —{' '}
                            {t(
                                'chat completion + embedding (bge-base-en-v1.5).',
                            )}
                        </li>
                        <li>
                            <span className="font-mono text-[11px]">
                                Account → Vectorize → Edit
                            </span>{' '}
                            —{' '}
                            {t(
                                'creates the chunks index on first run, upserts vectors, queries on every visitor message.',
                            )}
                        </li>
                        <li>
                            <span className="font-mono text-[11px]">
                                Account → Browser Rendering → Edit
                            </span>{' '}
                            —{' '}
                            {t(
                                'JS-rendered crawl of customer sites (Shopify, Next.js, Vue, SPA pages).',
                            )}
                        </li>
                        <li>
                            <span className="font-mono text-[11px]">
                                Account → Workers R2 Storage → Edit
                            </span>{' '}
                            —{' '}
                            {t(
                                'optional, only if you store assets (logos, uploaded source PDFs) in R2.',
                            )}
                        </li>
                        <li>
                            <span className="font-mono text-[11px]">
                                Account → Workers Scripts → Edit
                            </span>{' '}
                            —{' '}
                            {t(
                                'lets the "Deploy Cron Worker" button push the queue-tick Worker for you.',
                            )}
                        </li>
                    </ul>
                    <p className="mt-2">
                        <strong>{t('Resources scope:')}</strong>{' '}
                        {t(
                            'set "Include — All accounts" OR pick the specific account whose ID you paste below. Mismatched account = code 10000 "Authentication error" on every Vectorize call.',
                        )}
                    </p>
                    <p className="mt-2">
                        <a
                            href="/documentation/cloudflare-costs"
                            target="_blank"
                            rel="noopener noreferrer"
                            className="font-semibold underline-offset-2 hover:underline"
                        >
                            {t('Full setup guide →')}
                        </a>
                    </p>
                </div>

                <div className="grid gap-1">
                    <Label htmlFor="cloudflare_account_id">
                        {t('Account ID')}
                    </Label>
                    <Input
                        id="cloudflare_account_id"
                        autoComplete="off"
                        value={form.data.cloudflare_account_id}
                        onChange={(e) =>
                            form.setData(
                                'cloudflare_account_id',
                                e.target.value,
                            )
                        }
                    />
                    <p className="text-[11px] text-muted-foreground">
                        {t(
                            'Find it on the right sidebar of the Cloudflare dashboard home (32-character hex string).',
                        )}
                    </p>
                </div>
                <div className="grid gap-1">
                    <Label htmlFor="cloudflare_api_token">
                        {t('API token')}
                    </Label>
                    <SecretInput
                        id="cloudflare_api_token"
                        value={form.data.cloudflare_api_token}
                        isSet={initial.cloudflare_api_token_set}
                        onChange={(v) =>
                            form.setData('cloudflare_api_token', v)
                        }
                    />
                    <p className="text-[11px] text-muted-foreground">
                        {t(
                            'Token from My Profile → API Tokens with the permissions listed above. Starts with a long alphanumeric string; treat as a password.',
                        )}
                    </p>
                </div>
                <div className="grid gap-3">
                    <ModelPicker
                        id="cloudflare_chat_model"
                        label={t('Chat model')}
                        provider="cloudflare"
                        value={form.data.cloudflare_chat_model}
                        onChange={(v) =>
                            form.setData('cloudflare_chat_model', v)
                        }
                        catalog={summary.model_catalog.cloudflare}
                        initialLatency={summary.model_latencies.cloudflare}
                        placeholder="@cf/meta/llama-3.3-70b-instruct-fp8-fast"
                        helpText={t(
                            'Click "Test connection" to measure the real time-to-first-token from this server.',
                        )}
                    />
                    <EmbedModelPicker
                        id="cloudflare_embed_model"
                        label={t('Embed model')}
                        value={form.data.cloudflare_embed_model}
                        onChange={(v) =>
                            form.setData('cloudflare_embed_model', v)
                        }
                        catalog={summary.embed_catalog.cloudflare}
                        currentVectorDim={summary.current_vector_dim}
                        placeholder="@cf/baai/bge-base-en-v1.5"
                    />
                </div>
                <div className="grid gap-1">
                    <Label htmlFor="cloudflare_vectorize_index">
                        {t('Vectorize index')}
                    </Label>
                    <Input
                        id="cloudflare_vectorize_index"
                        autoComplete="off"
                        value={form.data.cloudflare_vectorize_index}
                        onChange={(e) =>
                            form.setData(
                                'cloudflare_vectorize_index',
                                e.target.value,
                            )
                        }
                        placeholder="pitchbar-chunks"
                    />
                </div>
                <div className="grid gap-1">
                    <Label htmlFor="cloudflare_ai_gateway_url">
                        {t('AI Gateway URL (optional)')}
                    </Label>
                    <Input
                        id="cloudflare_ai_gateway_url"
                        type="url"
                        autoComplete="off"
                        value={form.data.cloudflare_ai_gateway_url}
                        onChange={(e) =>
                            form.setData(
                                'cloudflare_ai_gateway_url',
                                e.target.value,
                            )
                        }
                        placeholder="https://gateway.ai.cloudflare.com/v1/<account>/<gateway>"
                    />
                    <p className="text-xs text-muted-foreground">
                        {t(
                            'Route Workers AI calls through Cloudflare AI Gateway for observability + caching. Leave blank to call Workers AI directly.',
                        )}
                    </p>
                </div>
                <label className="flex cursor-pointer items-center justify-between rounded border border-border p-3 text-sm">
                    <span>
                        {t(
                            'Use Cloudflare Browser Rendering for crawled pages',
                        )}
                        <span className="ml-2 text-xs text-muted-foreground">
                            {t(
                                '(falls back to Browserless / plain HTTP when disabled)',
                            )}
                        </span>
                    </span>
                    <input
                        type="checkbox"
                        checked={form.data.cloudflare_browser_rendering}
                        onChange={(e) =>
                            form.setData(
                                'cloudflare_browser_rendering',
                                e.target.checked,
                            )
                        }
                    />
                </label>
                <div className="flex justify-end">
                    <Button type="submit" disabled={form.processing}>
                        {t('Save Cloudflare')}
                    </Button>
                </div>
            </form>
        </SectionShell>
    );
}

function OpenAiSection({
    summary,
    initial,
}: {
    summary: LlmSummary;
    initial: FormValues;
}) {
    const { t } = useT();
    const form = useForm<{
        openai_api_key: string;
        openai_chat_model: string;
        openai_embed_model: string;
    }>({
        openai_api_key: '',
        openai_chat_model: initial.openai_chat_model ?? '',
        openai_embed_model: initial.openai_embed_model ?? '',
    });

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        form.patch('/settings/system/openai', { preserveScroll: true });
    };

    return (
        <SectionShell
            icon={Sparkles}
            title={t('OpenAI')}
            description={t(
                'Fallback / quality-bump for chat + embed fallback when CF embed fails.',
            )}
            statusPill={<StatusPill configured={initial.openai_api_key_set} />}
        >
            <form onSubmit={submit} className="grid gap-3">
                <div className="grid gap-1">
                    <Label htmlFor="openai_api_key">{t('API key')}</Label>
                    <SecretInput
                        id="openai_api_key"
                        value={form.data.openai_api_key}
                        isSet={initial.openai_api_key_set}
                        onChange={(v) => form.setData('openai_api_key', v)}
                        placeholder="sk-…"
                    />
                </div>
                <div className="grid gap-3">
                    <ModelPicker
                        id="openai_chat_model"
                        label={t('Chat model')}
                        provider="openai"
                        value={form.data.openai_chat_model}
                        onChange={(v) => form.setData('openai_chat_model', v)}
                        catalog={summary.model_catalog.openai}
                        initialLatency={summary.model_latencies.openai}
                        placeholder="gpt-4o-mini"
                        helpText={t(
                            'Click "Test connection" to measure the real time-to-first-token from this server.',
                        )}
                    />
                    <EmbedModelPicker
                        id="openai_embed_model"
                        label={t('Embed model')}
                        value={form.data.openai_embed_model}
                        onChange={(v) => form.setData('openai_embed_model', v)}
                        catalog={summary.embed_catalog.openai}
                        currentVectorDim={summary.current_vector_dim}
                        placeholder="text-embedding-3-small"
                    />
                </div>
                <div className="flex justify-end">
                    <Button type="submit" disabled={form.processing}>
                        {t('Save OpenAI')}
                    </Button>
                </div>
            </form>
        </SectionShell>
    );
}

function OpenRouterSection({
    summary,
    initial,
}: {
    summary: LlmSummary;
    initial: FormValues;
}) {
    const { t } = useT();
    const form = useForm<{
        openrouter_api_key: string;
        openrouter_chat_model: string;
    }>({
        openrouter_api_key: '',
        openrouter_chat_model: initial.openrouter_chat_model ?? '',
    });

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        form.patch('/settings/system/openrouter', { preserveScroll: true });
    };

    return (
        <SectionShell
            icon={Sparkles}
            title={t('OpenRouter')}
            description={t(
                'Free-tier chat models. Only used when LLM_PROVIDER is set to openrouter explicitly.',
            )}
            statusPill={
                <StatusPill configured={initial.openrouter_api_key_set} />
            }
        >
            <form onSubmit={submit} className="grid gap-3">
                <div className="grid gap-1">
                    <Label htmlFor="openrouter_api_key">{t('API key')}</Label>
                    <SecretInput
                        id="openrouter_api_key"
                        value={form.data.openrouter_api_key}
                        isSet={initial.openrouter_api_key_set}
                        onChange={(v) => form.setData('openrouter_api_key', v)}
                        placeholder="sk-or-…"
                    />
                </div>
                <ModelPicker
                    id="openrouter_chat_model"
                    label={t('Chat model')}
                    provider="openrouter"
                    value={form.data.openrouter_chat_model}
                    onChange={(v) => form.setData('openrouter_chat_model', v)}
                    catalog={summary.model_catalog.openrouter}
                    initialLatency={summary.model_latencies.openrouter}
                    placeholder="meta-llama/llama-3.3-70b-instruct:free"
                    helpText={t(
                        'Click "Test connection" to measure the real time-to-first-token from this server.',
                    )}
                />
                <div className="flex justify-end">
                    <Button type="submit" disabled={form.processing}>
                        {t('Save OpenRouter')}
                    </Button>
                </div>
            </form>
        </SectionShell>
    );
}

function RoutingSection({ initial }: { initial: FormValues }) {
    const { t } = useT();
    const form = useForm<{
        llm_provider: string;
        vector_provider: string;
    }>({
        llm_provider: initial.llm_provider ?? '',
        vector_provider: initial.vector_provider ?? '',
    });

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        form.patch('/settings/system/routing', { preserveScroll: true });
    };

    return (
        <SectionShell
            icon={RouteIcon}
            title={t('Provider routing')}
            description={t(
                'Force a specific LLM or vector provider. Leave blank to auto-pick from configured keys.',
            )}
        >
            <form onSubmit={submit} className="grid gap-3">
                <div className="grid gap-3 sm:grid-cols-2">
                    <div className="grid gap-1">
                        <Label htmlFor="llm_provider">LLM_PROVIDER</Label>
                        <Select
                            value={form.data.llm_provider || 'auto'}
                            onValueChange={(v) =>
                                form.setData(
                                    'llm_provider',
                                    v === 'auto' ? '' : v,
                                )
                            }
                        >
                            <SelectTrigger id="llm_provider">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="auto">
                                    {t('auto (from keys)')}
                                </SelectItem>
                                <SelectItem value="cloudflare">
                                    cloudflare
                                </SelectItem>
                                <SelectItem value="openai">openai</SelectItem>
                                <SelectItem value="openrouter">
                                    openrouter
                                </SelectItem>
                            </SelectContent>
                        </Select>
                    </div>
                    <div className="grid gap-1">
                        <Label htmlFor="vector_provider">VECTOR_PROVIDER</Label>
                        <Select
                            value={form.data.vector_provider || 'auto'}
                            onValueChange={(v) =>
                                form.setData(
                                    'vector_provider',
                                    v === 'auto' ? '' : v,
                                )
                            }
                        >
                            <SelectTrigger id="vector_provider">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="auto">
                                    {t('auto (from keys)')}
                                </SelectItem>
                                <SelectItem value="cloudflare">
                                    cloudflare
                                </SelectItem>
                                <SelectItem value="qdrant">qdrant</SelectItem>
                            </SelectContent>
                        </Select>
                    </div>
                </div>
                <div className="flex justify-end">
                    <Button type="submit" disabled={form.processing}>
                        {t('Save routing')}
                    </Button>
                </div>
            </form>
        </SectionShell>
    );
}

function AdminDigestSection({ initial }: { initial: FormValues }) {
    const { t } = useT();
    const form = useForm<{ admin_daily_digest_enabled: boolean }>({
        admin_daily_digest_enabled: initial.admin_daily_digest_enabled ?? false,
    });

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        form.patch('/settings/system/notifications', { preserveScroll: true });
    };

    return (
        <SectionShell
            icon={Sparkles}
            title={t('Daily admin digest')}
            description={t(
                'Email every super_admin a 24-hour summary of new users, new paid subscriptions, new workspaces, and new leads captured platform-wide. The summary is sent at 09:00 UTC every day via the scheduled command admin:send-daily-digest. Disable to stop the emails — opt-in by default.',
            )}
        >
            <form onSubmit={submit} className="grid gap-3">
                <label className="flex cursor-pointer items-center justify-between rounded border border-border p-3 text-sm">
                    <span>
                        {t('Send a daily summary email to every super_admin')}
                    </span>
                    <input
                        type="checkbox"
                        checked={form.data.admin_daily_digest_enabled}
                        onChange={(e) =>
                            form.setData(
                                'admin_daily_digest_enabled',
                                e.target.checked,
                            )
                        }
                    />
                </label>
                <div className="flex justify-end">
                    <Button type="submit" disabled={form.processing}>
                        {t('Save digest preference')}
                    </Button>
                </div>
            </form>
        </SectionShell>
    );
}

function WordpressPluginSection({ initial }: { initial: FormValues }) {
    const { t } = useT();
    const form = useForm<{
        wordpress_plugin_download_url: string;
        wordpress_plugin_help_text: string;
    }>({
        wordpress_plugin_download_url:
            initial.wordpress_plugin_download_url ?? '',
        wordpress_plugin_help_text: initial.wordpress_plugin_help_text ?? '',
    });

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        form.patch('/settings/system/wordpress_plugin', {
            preserveScroll: true,
        });
    };

    return (
        <SectionShell
            icon={Sparkles}
            title={t('WordPress plugin distribution')}
            description={t(
                "Override the help blurb + download link shown on every workspace's Integrations → WordPress card. Leave blank to keep the default text. Use the URL field to point customers to your own plugin mirror (WordPress.org listing, S3 bucket, marketing site, etc.).",
            )}
        >
            <form onSubmit={submit} className="grid gap-3">
                <div className="grid gap-1.5">
                    <Label htmlFor="wp-plugin-url">{t('Download URL')}</Label>
                    <input
                        id="wp-plugin-url"
                        type="url"
                        placeholder="https://yourdomain.com/plugin/pitchbar.zip"
                        value={form.data.wordpress_plugin_download_url}
                        onChange={(e) =>
                            form.setData(
                                'wordpress_plugin_download_url',
                                e.target.value,
                            )
                        }
                        className="rounded border border-border bg-background px-3 py-2 text-sm"
                    />
                    {form.errors.wordpress_plugin_download_url && (
                        <p className="text-xs text-destructive">
                            {form.errors.wordpress_plugin_download_url}
                        </p>
                    )}
                </div>
                <div className="grid gap-1.5">
                    <Label htmlFor="wp-plugin-text">{t('Help text')}</Label>
                    <textarea
                        id="wp-plugin-text"
                        rows={3}
                        placeholder={t(
                            'Need the plugin? Download it below, or read the setup guide:',
                        )}
                        value={form.data.wordpress_plugin_help_text}
                        onChange={(e) =>
                            form.setData(
                                'wordpress_plugin_help_text',
                                e.target.value,
                            )
                        }
                        className="rounded border border-border bg-background px-3 py-2 text-sm"
                    />
                    {form.errors.wordpress_plugin_help_text && (
                        <p className="text-xs text-destructive">
                            {form.errors.wordpress_plugin_help_text}
                        </p>
                    )}
                </div>
                <div className="flex justify-end">
                    <Button type="submit" disabled={form.processing}>
                        {t('Save plugin link')}
                    </Button>
                </div>
            </form>
        </SectionShell>
    );
}

function SignupVerificationSection({ initial }: { initial: FormValues }) {
    const { t } = useT();
    const form = useForm<{ require_email_verification: boolean }>({
        require_email_verification: initial.require_email_verification ?? false,
    });

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        form.patch('/settings/system/signup', { preserveScroll: true });
    };

    return (
        <SectionShell
            icon={Sparkles}
            title={t('Email verification on signup')}
            description={t(
                'When enabled, new users must click a verification link emailed to them before they can sign in. When disabled, signups are auto-verified and can log in immediately. Most B2B SaaS keeps this off so the very first signup-to-magic-moment funnel is friction-free.',
            )}
        >
            <form onSubmit={submit} className="grid gap-3">
                <label className="flex cursor-pointer items-center justify-between rounded border border-border p-3 text-sm">
                    <span>
                        {t('Require email verification before first login')}
                    </span>
                    <input
                        type="checkbox"
                        checked={form.data.require_email_verification}
                        onChange={(e) =>
                            form.setData(
                                'require_email_verification',
                                e.target.checked,
                            )
                        }
                    />
                </label>
                <div className="flex justify-end">
                    <Button type="submit" disabled={form.processing}>
                        {t('Save signup setting')}
                    </Button>
                </div>
            </form>
        </SectionShell>
    );
}

function ByokGlobalSection({ initial }: { initial: FormValues }) {
    const { t } = useT();
    const form = useForm<{ byok_enabled_globally: boolean }>({
        byok_enabled_globally: initial.byok_enabled_globally,
    });

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        form.patch('/settings/system/byok', { preserveScroll: true });
    };

    return (
        <SectionShell
            icon={Sparkles}
            title={t('Bring Your Own Keys (BYOK)')}
            description={t(
                'When enabled, every workspace must supply its own Cloudflare / OpenAI / OpenRouter / Qdrant credentials in Settings → BYOK Keys. The platform keys configured above will not resolve for any tenant. Per-user overrides on the /admin/users page still apply.',
            )}
        >
            <form onSubmit={submit} className="grid gap-3">
                <label className="flex cursor-pointer items-center justify-between rounded border border-border p-3 text-sm">
                    <span>
                        {t('Force every workspace to use their own AI keys')}
                    </span>
                    <input
                        type="checkbox"
                        checked={form.data.byok_enabled_globally}
                        onChange={(e) =>
                            form.setData(
                                'byok_enabled_globally',
                                e.target.checked,
                            )
                        }
                    />
                </label>
                <div className="flex justify-end">
                    <Button type="submit" disabled={form.processing}>
                        {t('Save BYOK gate')}
                    </Button>
                </div>
            </form>
        </SectionShell>
    );
}

function MailSection({
    summary,
    initial,
}: {
    summary: MailSummary;
    initial: FormValues;
}) {
    const { t } = useT();
    const branding = useBranding();
    const brand = branding.site_title || 'Pitchbar';
    const form = useForm<{
        mail_driver: string;
        mail_host: string;
        mail_port: string;
        mail_encryption: string;
        mail_username: string;
        mail_password: string;
        mail_from_address: string;
        mail_from_name: string;
    }>({
        mail_driver: initial.mail_driver ?? '',
        mail_host: initial.mail_host ?? '',
        mail_port: initial.mail_port ? String(initial.mail_port) : '',
        mail_encryption: initial.mail_encryption ?? '',
        mail_username: initial.mail_username ?? '',
        mail_password: '',
        mail_from_address: initial.mail_from_address ?? '',
        mail_from_name: initial.mail_from_name ?? '',
    });

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        form.patch('/settings/system/mail', { preserveScroll: true });
    };

    return (
        <SectionShell
            icon={Mail}
            title={t('Mail (SMTP)')}
            description={t(
                'Outbound transactional email. Raw SMTP test probes the mail driver only. Lead-email test goes through the queue worker too — use it to confirm the full lead-captured pipeline.',
            )}
            statusPill={<StatusPill configured={summary.configured} />}
            testEndpoint="/settings/system/test/mail"
            testLabel={t('Send raw SMTP test')}
            secondaryTestEndpoint="/settings/system/test/lead-email"
            secondaryTestLabel={t('Send test lead email')}
        >
            <form onSubmit={submit} className="grid gap-3">
                <div className="grid gap-3 sm:grid-cols-2">
                    <div className="grid gap-1">
                        <Label htmlFor="mail_driver">{t('Driver')}</Label>
                        <Input
                            id="mail_driver"
                            autoComplete="off"
                            value={form.data.mail_driver}
                            onChange={(e) =>
                                form.setData('mail_driver', e.target.value)
                            }
                            placeholder="smtp"
                        />
                    </div>
                    <div className="grid gap-1">
                        <Label htmlFor="mail_host">{t('Host')}</Label>
                        <Input
                            id="mail_host"
                            autoComplete="off"
                            value={form.data.mail_host}
                            onChange={(e) =>
                                form.setData('mail_host', e.target.value)
                            }
                            placeholder="smtp.example.com"
                        />
                    </div>
                </div>
                <div className="grid gap-3 sm:grid-cols-3">
                    <div className="grid gap-1">
                        <Label htmlFor="mail_port">{t('Port')}</Label>
                        <Input
                            id="mail_port"
                            type="number"
                            autoComplete="off"
                            value={form.data.mail_port}
                            onChange={(e) =>
                                form.setData('mail_port', e.target.value)
                            }
                            placeholder="587"
                        />
                    </div>
                    <div className="grid gap-1">
                        <Label htmlFor="mail_encryption">
                            {t('Encryption')}
                        </Label>
                        <Select
                            value={form.data.mail_encryption || 'none'}
                            onValueChange={(v) =>
                                form.setData(
                                    'mail_encryption',
                                    v === 'none' ? '' : v,
                                )
                            }
                        >
                            <SelectTrigger id="mail_encryption">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="none">
                                    {t('none')}
                                </SelectItem>
                                <SelectItem value="tls">tls</SelectItem>
                                <SelectItem value="ssl">ssl</SelectItem>
                            </SelectContent>
                        </Select>
                    </div>
                    <div className="grid gap-1">
                        <Label htmlFor="mail_username">{t('Username')}</Label>
                        <Input
                            id="mail_username"
                            autoComplete="off"
                            value={form.data.mail_username}
                            onChange={(e) =>
                                form.setData('mail_username', e.target.value)
                            }
                        />
                    </div>
                </div>
                <div className="grid gap-1">
                    <Label htmlFor="mail_password">{t('Password')}</Label>
                    <SecretInput
                        id="mail_password"
                        value={form.data.mail_password}
                        isSet={initial.mail_password_set}
                        onChange={(v) => form.setData('mail_password', v)}
                    />
                </div>
                <div className="grid gap-3 sm:grid-cols-2">
                    <div className="grid gap-1">
                        <Label htmlFor="mail_from_address">
                            {t('From address')}
                        </Label>
                        <Input
                            id="mail_from_address"
                            type="email"
                            autoComplete="off"
                            value={form.data.mail_from_address}
                            onChange={(e) =>
                                form.setData(
                                    'mail_from_address',
                                    e.target.value,
                                )
                            }
                            placeholder="hello@example.com"
                        />
                    </div>
                    <div className="grid gap-1">
                        <Label htmlFor="mail_from_name">{t('From name')}</Label>
                        <Input
                            id="mail_from_name"
                            autoComplete="off"
                            value={form.data.mail_from_name}
                            onChange={(e) =>
                                form.setData('mail_from_name', e.target.value)
                            }
                            placeholder={brand}
                        />
                    </div>
                </div>
                <div className="flex justify-end">
                    <Button type="submit" disabled={form.processing}>
                        {t('Save mail')}
                    </Button>
                </div>
            </form>
        </SectionShell>
    );
}

/**
 * Live health card for the Cloudflare cron Worker. Reads the
 * `cron_tick_logs` aggregate the controller folded into the page
 * payload. Answers the single question: "is the worker actually
 * firing AND actually doing work?"
 */
function CronWorkerHealth({ summary }: { summary: CronWorkerSummary }) {
    const { t } = useT();
    const liveness = summary.liveness ?? 'unknown';
    const sinceLast = summary.seconds_since_last_tick;
    const lastTickAt = summary.last_tick_at;
    const recent = summary.recent_ticks ?? [];

    const livenessTone =
        liveness === 'live'
            ? 'border-emerald-300 bg-emerald-50 text-emerald-900 dark:bg-emerald-950/40 dark:text-emerald-100'
            : liveness === 'stale'
              ? 'border-amber-300 bg-amber-50 text-amber-900 dark:bg-amber-950/40 dark:text-amber-100'
              : liveness === 'dead'
                ? 'border-rose-300 bg-rose-50 text-rose-900 dark:bg-rose-950/40 dark:text-rose-100'
                : 'border-slate-300 bg-slate-50 text-slate-900 dark:bg-slate-900/40 dark:text-slate-100';

    const livenessLabel =
        liveness === 'live'
            ? t('Live — firing on schedule')
            : liveness === 'stale'
              ? t('Stale — last tick > 90s ago')
              : liveness === 'dead'
                ? t('Dead — no tick in last 5 minutes')
                : t('Waiting for first tick');

    const sinceText = (() => {
        if (sinceLast === null || sinceLast === undefined) {
            return t('No ticks yet — give the cron 60s after deploy.');
        }

        if (sinceLast < 60) {
            return t('Last tick :sec s ago', { sec: sinceLast });
        }

        const m = Math.floor(sinceLast / 60);
        const s = sinceLast % 60;

        return t('Last tick :min m :sec s ago', { min: m, sec: s });
    })();

    return (
        <div className="grid gap-3">
            <div className={`rounded-md border p-4 ${livenessTone}`}>
                <div className="flex items-center justify-between gap-3">
                    <div>
                        <p className="text-sm font-semibold">{livenessLabel}</p>
                        <p className="mt-0.5 text-xs opacity-80">
                            {sinceText}
                            {lastTickAt && (
                                <>
                                    {' · '}
                                    {new Date(lastTickAt).toLocaleString()}
                                </>
                            )}
                        </p>
                    </div>
                    <span
                        className={`inline-flex size-2.5 rounded-full ${
                            liveness === 'live'
                                ? 'animate-pulse bg-emerald-500'
                                : liveness === 'stale'
                                  ? 'bg-amber-500'
                                  : liveness === 'dead'
                                    ? 'bg-rose-500'
                                    : 'bg-slate-400'
                        }`}
                    />
                </div>
            </div>

            <div className="grid gap-3 sm:grid-cols-4">
                <HealthStat
                    label={t('Ticks (last hour)')}
                    value={summary.ticks_last_hour ?? 0}
                    hint={t('should be ≈ 60 when live')}
                />
                <HealthStat
                    label={t('Jobs processed (1h)')}
                    value={summary.jobs_processed_last_hour ?? 0}
                />
                <HealthStat
                    label={t('Pending in queue')}
                    value={summary.pending_jobs ?? 0}
                    tone={(summary.pending_jobs ?? 0) > 100 ? 'warn' : 'normal'}
                />
                <HealthStat
                    label={t('Failed jobs')}
                    value={summary.failed_jobs ?? 0}
                    tone={(summary.failed_jobs ?? 0) > 0 ? 'warn' : 'normal'}
                />
            </div>

            {recent.length > 0 && (
                <details className="rounded-md border border-border bg-muted/20 p-3">
                    <summary className="cursor-pointer text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                        {t('Recent ticks (:count)', { count: recent.length })}
                    </summary>
                    <div className="mt-3 overflow-x-auto">
                        <table className="w-full text-xs">
                            <thead>
                                <tr className="border-b border-border text-start text-muted-foreground">
                                    <th className="px-2 py-1.5 font-medium">
                                        {t('When')}
                                    </th>
                                    <th className="px-2 py-1.5 font-medium">
                                        {t('Processed')}
                                    </th>
                                    <th className="px-2 py-1.5 font-medium">
                                        {t('Failed')}
                                    </th>
                                    <th className="px-2 py-1.5 font-medium">
                                        {t('Remaining')}
                                    </th>
                                    <th className="px-2 py-1.5 font-medium">
                                        {t('Elapsed')}
                                    </th>
                                </tr>
                            </thead>
                            <tbody className="font-mono">
                                {recent.map((r, i) => (
                                    <tr
                                        key={i}
                                        className="border-b border-border/40 last:border-0"
                                    >
                                        <td className="px-2 py-1.5">
                                            {r.at
                                                ? new Date(
                                                      r.at,
                                                  ).toLocaleTimeString()
                                                : '-'}
                                        </td>
                                        <td className="px-2 py-1.5 text-emerald-700">
                                            {r.processed}
                                        </td>
                                        <td
                                            className={`px-2 py-1.5 ${r.failed > 0 ? 'text-rose-600' : ''}`}
                                        >
                                            {r.failed}
                                        </td>
                                        <td className="px-2 py-1.5">
                                            {r.remaining}
                                        </td>
                                        <td className="px-2 py-1.5">
                                            {r.ms} ms
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </details>
            )}
        </div>
    );
}

function HealthStat({
    label,
    value,
    hint,
    tone = 'normal',
}: {
    label: string;
    value: number | string;
    hint?: string;
    tone?: 'normal' | 'warn';
}) {
    return (
        <div
            className={`rounded-md border p-3 ${
                tone === 'warn'
                    ? 'border-amber-300 bg-amber-50/40'
                    : 'border-border bg-muted/20'
            }`}
        >
            <p className="text-[11px] font-medium tracking-wide text-muted-foreground uppercase">
                {label}
            </p>
            <p className="mt-1 font-mono text-lg font-semibold">{value}</p>
            {hint && (
                <p className="mt-0.5 text-[10px] text-muted-foreground/80">
                    {hint}
                </p>
            )}
        </div>
    );
}

function CronWorkerSection({ summary }: { summary: CronWorkerSummary }) {
    const { t } = useT();
    const confirm = useConfirm();
    const [pending, setPending] = useState(false);
    const [result, setResult] = useState<{
        ok: boolean;
        message: string;
        worker_url?: string;
    } | null>(null);
    const [deployed, setDeployed] = useState(summary.deployed);
    const [workerName, setWorkerName] = useState(summary.worker_name);
    const [deployedAt, setDeployedAt] = useState(summary.deployed_at);

    const csrf =
        (
            document.querySelector(
                'meta[name="csrf-token"]',
            ) as HTMLMetaElement | null
        )?.content ?? '';

    const call = async (
        method: 'POST' | 'DELETE',
        path: string,
    ): Promise<Response> => {
        return fetch(path, {
            method,
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-CSRF-TOKEN': csrf,
                'X-Requested-With': 'XMLHttpRequest',
            },
        });
    };

    const onDeploy = async () => {
        setPending(true);
        setResult(null);

        try {
            const res = await call(
                'POST',
                '/settings/system/cron-worker/deploy',
            );
            const json = await res.json();

            if (!res.ok) {
                setResult({
                    ok: false,
                    message:
                        json?.error?.message ??
                        t('Deploy failed (HTTP :status).', {
                            status: res.status,
                        }),
                });

                return;
            }

            const data = json.data;
            setDeployed(true);
            setWorkerName(data.worker_name);
            setDeployedAt(data.deployed_at);
            setResult({
                ok: true,
                message: t(
                    'Deployed. Cloudflare will run the worker every minute.',
                ),
                worker_url: data.worker_url,
            });
        } catch (e) {
            setResult({
                ok: false,
                message:
                    e instanceof Error
                        ? e.message
                        : t('Network error reaching the deploy endpoint.'),
            });
        } finally {
            setPending(false);
        }
    };

    const onRemove = async () => {
        const ok = await confirm({
            title: t('Remove worker?'),
            message: t(
                'Remove the deployed Cloudflare worker? Your queue will stop processing until you re-deploy or set up another cron source.',
            ),
            confirmLabel: t('Remove'),
            danger: true,
        });

        if (!ok) {
            return;
        }

        setPending(true);
        setResult(null);

        try {
            const res = await call('DELETE', '/settings/system/cron-worker');

            if (!res.ok) {
                const json = await res.json();
                setResult({
                    ok: false,
                    message:
                        json?.error?.message ??
                        t('Remove failed (HTTP :status).', {
                            status: res.status,
                        }),
                });

                return;
            }

            setDeployed(false);
            setWorkerName(null);
            setDeployedAt(null);
            setResult({ ok: true, message: t('Worker removed.') });
        } catch (e) {
            setResult({
                ok: false,
                message: e instanceof Error ? e.message : t('Network error.'),
            });
        } finally {
            setPending(false);
        }
    };

    return (
        <SectionShell
            icon={Workflow}
            title={t('Cloudflare Cron Worker')}
            description={t(
                "One-click deploy: run your queue from Cloudflare's free cron infrastructure. No cPanel cron required.",
            )}
            statusPill={<StatusPill configured={deployed} />}
        >
            <div className="grid gap-4">
                {!summary.cloudflare_configured && (
                    <div className="rounded-md border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900">
                        {t('Save your Cloudflare account ID + API token under')}{' '}
                        <strong>{t('AI providers → Cloudflare')}</strong>{' '}
                        {t(
                            'first. The same credentials drive Workers AI and the queue cron.',
                        )}
                    </div>
                )}

                {deployed && <CronWorkerHealth summary={summary} />}

                <div className="rounded-md border border-border bg-muted/30 p-3 text-xs">
                    <p className="text-muted-foreground">
                        {t('The worker pings this endpoint every minute:')}
                    </p>
                    <p className="mt-1 font-mono break-all">
                        POST {summary.callback_url}
                    </p>
                </div>

                {deployed ? (
                    <div className="rounded-md border border-emerald-300 bg-emerald-50 p-3 text-sm dark:bg-emerald-950/40">
                        <p className="font-medium text-emerald-900 dark:text-emerald-100">
                            {t('Deployed')}
                        </p>
                        <p className="mt-1 text-xs text-emerald-800/80 dark:text-emerald-200/80">
                            {t('Worker name:')}{' '}
                            <span className="font-mono">{workerName}</span>
                            {deployedAt && (
                                <>
                                    {' '}
                                    · {t('deployed')}{' '}
                                    {new Date(deployedAt).toLocaleString()}
                                </>
                            )}
                        </p>
                    </div>
                ) : (
                    <p className="text-sm text-muted-foreground">
                        {t(
                            'Not deployed yet. Click the button to push the worker to your Cloudflare account.',
                        )}
                    </p>
                )}

                {result && (
                    <div
                        className={`rounded-md border p-3 text-sm ${result.ok ? 'border-emerald-300 bg-emerald-50 text-emerald-900' : 'border-rose-300 bg-rose-50 text-rose-900'}`}
                    >
                        {result.message}
                        {result.worker_url && (
                            <>
                                {' '}
                                <a
                                    className="underline"
                                    href={result.worker_url}
                                    target="_blank"
                                    rel="noopener noreferrer"
                                >
                                    {t('Open in Cloudflare dashboard ↗')}
                                </a>
                            </>
                        )}
                    </div>
                )}

                <div className="flex flex-wrap gap-2">
                    <Button
                        type="button"
                        onClick={onDeploy}
                        disabled={pending || !summary.cloudflare_configured}
                    >
                        {pending && <Loader2 className="size-4 animate-spin" />}
                        {deployed ? t('Re-deploy worker') : t('Deploy worker')}
                    </Button>
                    {deployed && (
                        <Button
                            type="button"
                            variant="outline"
                            onClick={onRemove}
                            disabled={pending}
                        >
                            {t('Remove worker')}
                        </Button>
                    )}
                </div>
            </div>
        </SectionShell>
    );
}

function BrandingAssetField({
    id,
    label,
    description,
    surfaceLabel,
    icon: Icon,
    accept,
    currentUrl,
    selectedFile,
    fileName,
    error,
    onChange,
    siteTitle,
    displayMode,
    onDisplayModeChange,
    previewClassName,
    surfacePreviewClassName,
    secondaryPreviewLabel,
    secondaryPreview,
}: {
    id: string;
    label: string;
    description: string;
    surfaceLabel: string;
    icon: LucideIcon;
    accept: string;
    currentUrl: string | null;
    selectedFile: File | null;
    fileName?: string;
    error?: string;
    onChange: (file: File | null) => void;
    siteTitle: string;
    displayMode?: BrandDisplayMode;
    onDisplayModeChange?: (value: BrandDisplayMode) => void;
    previewClassName?: string;
    surfacePreviewClassName?: string;
    secondaryPreviewLabel?: string;
    secondaryPreview?:
        | React.ReactNode
        | ((previewUrl: string | null) => React.ReactNode);
}) {
    const { t } = useT();
    const selectedPreviewUrl = useSelectedFilePreview(selectedFile);

    const assetPreviewUrl = selectedPreviewUrl ?? currentUrl;
    const supportsDisplayMode =
        displayMode !== undefined && onDisplayModeChange !== undefined;
    const hasSecondaryPreview =
        supportsDisplayMode || secondaryPreview !== undefined;
    const renderedSecondaryPreview =
        typeof secondaryPreview === 'function'
            ? secondaryPreview(assetPreviewUrl)
            : secondaryPreview;
    const uploadHint = fileName
        ? t('Selected file: :name', { name: fileName })
        : t(
              'PNG, JPG, SVG, or WEBP. Use a wider wordmark for public surfaces and a compact mark for dashboard surfaces.',
          );

    return (
        <div className="rounded-2xl border border-border/70 bg-card/70 p-5 shadow-sm">
            <div className="flex flex-col gap-5">
                <div className="flex flex-col gap-4 md:flex-row md:items-start md:justify-between">
                    <div className="max-w-2xl min-w-0">
                        <div className="flex flex-wrap items-center gap-2">
                            <span className="flex size-9 shrink-0 items-center justify-center rounded-xl border border-border/70 bg-muted/20 text-muted-foreground shadow-xs">
                                <Icon className="size-4" />
                            </span>
                            <div className="min-w-0">
                                <div className="flex flex-wrap items-center gap-2">
                                    <Label
                                        htmlFor={id}
                                        className="text-sm font-semibold"
                                    >
                                        {label}
                                    </Label>
                                    <Badge
                                        variant="outline"
                                        className="border-border/70 bg-background/80 px-2 py-1 text-[10px] font-semibold tracking-[0.14em] text-muted-foreground uppercase"
                                    >
                                        {surfaceLabel}
                                    </Badge>
                                </div>
                            </div>
                        </div>

                        <p className="mt-1 text-xs leading-5 text-muted-foreground">
                            {description}
                        </p>
                    </div>

                    {supportsDisplayMode ? (
                        <div className="grid gap-2 md:w-[220px] md:shrink-0">
                            <Label htmlFor={`${id}_display`}>
                                {t('Display mode')}
                            </Label>
                            <Select
                                value={displayMode}
                                onValueChange={(value) =>
                                    onDisplayModeChange(
                                        value as BrandDisplayMode,
                                    )
                                }
                            >
                                <SelectTrigger id={`${id}_display`}>
                                    <SelectValue
                                        placeholder={t('Choose display mode')}
                                    />
                                </SelectTrigger>
                                <SelectContent>
                                    {BRAND_DISPLAY_OPTIONS.map((option) => (
                                        <SelectItem
                                            key={option.value}
                                            value={option.value}
                                        >
                                            {option.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <p className="text-[11px] leading-5 text-muted-foreground">
                                {
                                    BRAND_DISPLAY_OPTIONS.find(
                                        (option) =>
                                            option.value === displayMode,
                                    )?.description
                                }
                            </p>
                        </div>
                    ) : null}
                </div>

                <div
                    className={cn(
                        'grid gap-3',
                        hasSecondaryPreview ? 'md:grid-cols-2' : '',
                    )}
                >
                    <div className="rounded-xl border border-border/70 bg-muted/15 p-4">
                        <p className="text-[11px] font-semibold tracking-[0.16em] text-muted-foreground uppercase">
                            {t('Current asset')}
                        </p>
                        <div className="mt-3 flex min-h-[120px] items-center justify-center rounded-xl border border-dashed border-border/70 bg-background/85 p-4">
                            {assetPreviewUrl ? (
                                <img
                                    src={assetPreviewUrl}
                                    alt=""
                                    className={cn(
                                        'h-12 w-auto max-w-full object-contain',
                                        previewClassName,
                                    )}
                                />
                            ) : (
                                <span className="text-center text-xs font-medium text-muted-foreground">
                                    {t('No uploaded asset yet')}
                                </span>
                            )}
                        </div>
                    </div>

                    {hasSecondaryPreview ? (
                        <div className="rounded-xl border border-border/70 bg-muted/15 p-4">
                            <p className="text-[11px] font-semibold tracking-[0.16em] text-muted-foreground uppercase">
                                {secondaryPreviewLabel ?? t('Surface preview')}
                            </p>
                            <div
                                className={cn(
                                    'mt-3 flex min-h-[120px] items-center rounded-xl border border-dashed border-border/70 p-4',
                                    surfacePreviewClassName ??
                                        'bg-gradient-to-br from-background to-muted/40',
                                )}
                            >
                                {supportsDisplayMode ? (
                                    <BrandLockup
                                        siteTitle={siteTitle}
                                        logoUrl={assetPreviewUrl}
                                        mode={displayMode}
                                        className="min-w-0 gap-3"
                                        logoClassName={cn(
                                            'h-11 max-w-[170px]',
                                            previewClassName,
                                        )}
                                        textClassName="truncate text-sm font-semibold tracking-tight text-foreground"
                                    />
                                ) : (
                                    renderedSecondaryPreview
                                )}
                            </div>
                        </div>
                    ) : null}
                </div>

                <div className="rounded-xl border border-dashed border-border/70 bg-muted/10 p-4">
                    <Input
                        id={id}
                        type="file"
                        accept={accept}
                        className="sr-only"
                        onChange={(event) =>
                            onChange(event.target.files?.[0] ?? null)
                        }
                    />

                    <div className="flex flex-col gap-3">
                        <div>
                            <p className="text-sm font-medium text-foreground">
                                {t('Upload replacement')}
                            </p>
                            <p className="mt-1 text-xs leading-5 text-muted-foreground">
                                {uploadHint}
                            </p>
                        </div>

                        <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                            <label
                                htmlFor={id}
                                className="inline-flex h-9 cursor-pointer items-center justify-center gap-2 rounded-lg border border-input bg-background px-3 text-sm font-medium text-foreground shadow-xs transition hover:bg-accent hover:text-accent-foreground"
                            >
                                <Upload className="size-4" />
                                {fileName
                                    ? t('Replace file')
                                    : t('Choose file')}
                            </label>

                            <p className="min-w-0 text-xs text-muted-foreground sm:max-w-[260px] sm:text-end">
                                {fileName ? (
                                    <span className="truncate font-medium text-foreground">
                                        {fileName}
                                    </span>
                                ) : (
                                    t('No file selected yet')
                                )}
                            </p>
                        </div>
                    </div>

                    {error ? (
                        <p className="mt-3 text-xs font-medium text-destructive">
                            {error}
                        </p>
                    ) : null}
                </div>
            </div>
        </div>
    );
}

function BrandingSection({ initial }: { initial: FormValues }) {
    const { t } = useT();
    const branding = useBranding();
    const brand = branding.site_title || 'Pitchbar';
    const form = useForm<{
        site_title: string;
        header_logo: File | null;
        footer_logo: File | null;
        dashboard_logo: File | null;
        header_logo_dark: File | null;
        footer_logo_dark: File | null;
        dashboard_logo_dark: File | null;
        favicon: File | null;
        header_brand_display: BrandDisplayMode;
        footer_brand_display: BrandDisplayMode;
        dashboard_brand_display: BrandDisplayMode;
        pitchbar_brand_url: string;
        pitchbar_brand_label: string;
        marketing_site_enabled: boolean;
        auth_aside_eyebrow: string;
        auth_aside_heading: string;
        auth_aside_lede: string;
        auth_aside_bullets: string[];
    }>({
        site_title: initial.site_title ?? '',
        header_logo: null,
        footer_logo: null,
        dashboard_logo: null,
        header_logo_dark: null,
        footer_logo_dark: null,
        dashboard_logo_dark: null,
        favicon: null,
        header_brand_display: initial.header_brand_display,
        footer_brand_display: initial.footer_brand_display,
        dashboard_brand_display: initial.dashboard_brand_display,
        pitchbar_brand_url: initial.pitchbar_brand_url ?? '',
        pitchbar_brand_label: initial.pitchbar_brand_label ?? '',
        marketing_site_enabled: initial.marketing_site_enabled ?? true,
        auth_aside_eyebrow: initial.auth_aside_eyebrow ?? '',
        auth_aside_heading: initial.auth_aside_heading ?? '',
        auth_aside_lede: initial.auth_aside_lede ?? '',
        auth_aside_bullets: Array.isArray(initial.auth_aside_bullets)
            ? initial.auth_aside_bullets
            : [],
    });

    const headerLogoPreview = useSelectedFilePreview(form.data.header_logo);
    const footerLogoPreview = useSelectedFilePreview(form.data.footer_logo);
    const dashboardLogoPreview = useSelectedFilePreview(
        form.data.dashboard_logo,
    );
    const faviconPreview = useSelectedFilePreview(form.data.favicon);

    const resetBrandingTransform = () => {
        form.transform((data) => data);
    };

    const submit = (e: React.FormEvent) => {
        e.preventDefault();

        const hasUploads = [
            form.data.header_logo,
            form.data.footer_logo,
            form.data.dashboard_logo,
            form.data.header_logo_dark,
            form.data.footer_logo_dark,
            form.data.dashboard_logo_dark,
            form.data.favicon,
        ].some((file) => file !== null);

        const onSuccess = () => {
            form.reset(
                'header_logo',
                'footer_logo',
                'dashboard_logo',
                'header_logo_dark',
                'footer_logo_dark',
                'dashboard_logo_dark',
                'favicon',
            );
        };

        if (!hasUploads) {
            resetBrandingTransform();

            form.patch(updateSystemSettings.url('branding'), {
                preserveScroll: true,
                onSuccess,
            });

            return;
        }

        // For multipart uploads, Laravel reliably honors method spoofing
        // when `_method` is sent in the POST body.
        form.transform((data) => ({
            ...data,
            _method: 'patch',
        }));

        form.post(updateSystemSettings.url('branding'), {
            preserveScroll: true,
            forceFormData: true,
            onSuccess,
            onFinish: resetBrandingTransform,
        });
    };

    return (
        <SectionShell
            icon={Palette}
            title={t('Site branding')}
            description={t(
                'Manage the global site title, uploaded logos for public and dashboard surfaces, the favicon, and the widget footer link used on free plans.',
            )}
        >
            <form onSubmit={submit} className="grid gap-5">
                <div className="rounded-[28px] border border-border/70 bg-gradient-to-br from-card via-card to-muted/25 p-6 shadow-sm">
                    <div className="grid gap-6 xl:grid-cols-[minmax(0,1fr)_460px] xl:items-start">
                        <div className="space-y-5">
                            <div className="space-y-3">
                                <Badge
                                    variant="outline"
                                    className="border-border/70 bg-background/70 px-2.5 py-1 text-[10px] font-semibold tracking-[0.16em] text-muted-foreground uppercase"
                                >
                                    {t('Brand system')}
                                </Badge>
                                <div>
                                    <h3 className="text-lg font-semibold tracking-tight text-foreground">
                                        {t(
                                            'Control every brand surface from one place',
                                        )}
                                    </h3>
                                    <p className="mt-1 max-w-2xl text-sm leading-6 text-muted-foreground">
                                        {t(
                                            'Update the brand title, preview how each surface renders, and replace assets without guessing how they will appear in the app.',
                                        )}
                                    </p>
                                </div>
                            </div>

                            <div className="grid gap-2.5 rounded-2xl border border-border/70 bg-background/75 p-4 shadow-xs">
                                <Label htmlFor="site_title">
                                    {t('Site title')}
                                </Label>
                                <Input
                                    id="site_title"
                                    autoComplete="off"
                                    value={form.data.site_title}
                                    onChange={(e) =>
                                        form.setData(
                                            'site_title',
                                            e.target.value,
                                        )
                                    }
                                    placeholder={brand}
                                />
                                <p className="text-xs leading-5 text-muted-foreground">
                                    {t(
                                        'Used in the browser title, shared app branding props, and text fallbacks when no custom logo is uploaded.',
                                    )}
                                </p>
                                {form.errors.site_title ? (
                                    <p className="text-xs font-medium text-destructive">
                                        {form.errors.site_title}
                                    </p>
                                ) : null}
                            </div>

                            <div className="flex flex-wrap gap-2">
                                <Badge
                                    variant="outline"
                                    className="border-border/70 bg-background/70 text-[11px] text-muted-foreground"
                                >
                                    {t('Landing + auth')}
                                </Badge>
                                <Badge
                                    variant="outline"
                                    className="border-border/70 bg-background/70 text-[11px] text-muted-foreground"
                                >
                                    {t('Dashboard shell')}
                                </Badge>
                                <Badge
                                    variant="outline"
                                    className="border-border/70 bg-background/70 text-[11px] text-muted-foreground"
                                >
                                    {t('Footer + widget badge')}
                                </Badge>
                                <Badge
                                    variant="outline"
                                    className="border-border/70 bg-background/70 text-[11px] text-muted-foreground"
                                >
                                    {t('Browser tab')}
                                </Badge>
                            </div>
                        </div>

                        <div className="grid gap-3 sm:grid-cols-2">
                            <BrandSurfacePreview
                                title={t('Header')}
                                caption={t('Landing and auth')}
                                icon={PanelTop}
                                siteTitle={
                                    form.data.site_title || t('Site title')
                                }
                                logoUrl={
                                    headerLogoPreview ?? initial.header_logo_url
                                }
                                mode={form.data.header_brand_display}
                                shellClassName="bg-gradient-to-br from-background via-background to-sky-500/5"
                            />

                            <BrandSurfacePreview
                                title={t('Dashboard')}
                                caption={t('App shell')}
                                icon={LayoutDashboard}
                                siteTitle={
                                    form.data.site_title || t('Site title')
                                }
                                logoUrl={
                                    dashboardLogoPreview ??
                                    initial.dashboard_logo_url
                                }
                                mode={form.data.dashboard_brand_display}
                                shellClassName="bg-gradient-to-br from-muted/25 via-background to-muted/45"
                            />

                            <BrandSurfacePreview
                                title={t('Footer')}
                                caption={t('Marketing footer + widget')}
                                icon={PanelBottom}
                                siteTitle={
                                    form.data.site_title || t('Site title')
                                }
                                logoUrl={
                                    footerLogoPreview ??
                                    initial.footer_logo_url ??
                                    headerLogoPreview ??
                                    initial.header_logo_url
                                }
                                mode={form.data.footer_brand_display}
                                shellClassName="bg-gradient-to-br from-[#17181b] to-[#22252b]"
                                textClassName="truncate text-sm font-semibold tracking-tight text-white"
                            />

                            <FaviconSurfacePreview
                                siteTitle={
                                    form.data.site_title || t('Site title')
                                }
                                faviconUrl={
                                    faviconPreview ?? initial.favicon_url
                                }
                            />
                        </div>
                    </div>
                </div>

                <div className="grid gap-5 lg:grid-cols-2">
                    <BrandingAssetField
                        id="header_logo"
                        label={t('Header logo')}
                        surfaceLabel={t('Public + auth')}
                        icon={PanelTop}
                        description={t(
                            'Used in the public landing-page header and the auth entry points.',
                        )}
                        accept="image/png,image/jpeg,image/webp,image/svg+xml"
                        currentUrl={initial.header_logo_url}
                        selectedFile={form.data.header_logo}
                        fileName={form.data.header_logo?.name}
                        error={form.errors.header_logo}
                        siteTitle={form.data.site_title || t('Site title')}
                        displayMode={form.data.header_brand_display}
                        onDisplayModeChange={(value) =>
                            form.setData('header_brand_display', value)
                        }
                        surfacePreviewClassName="bg-gradient-to-br from-background via-background to-sky-500/5"
                        onChange={(file) => form.setData('header_logo', file)}
                    />

                    <BrandingAssetField
                        id="header_logo_dark"
                        label={t('Header logo (dark mode)')}
                        surfaceLabel={t('Public + auth')}
                        icon={PanelTop}
                        description={t(
                            'Optional. Swapped in when the visitor or operator is in dark mode. Leave empty to reuse the light logo (works for transparent or duotone wordmarks).',
                        )}
                        accept="image/png,image/jpeg,image/webp,image/svg+xml"
                        currentUrl={initial.header_logo_dark_url}
                        selectedFile={form.data.header_logo_dark}
                        fileName={form.data.header_logo_dark?.name}
                        error={form.errors.header_logo_dark}
                        siteTitle={form.data.site_title || t('Site title')}
                        displayMode={form.data.header_brand_display}
                        onDisplayModeChange={(value) =>
                            form.setData('header_brand_display', value)
                        }
                        surfacePreviewClassName="bg-gradient-to-br from-[#0c0e12] via-[#101216] to-[#1a1d24]"
                        onChange={(file) =>
                            form.setData('header_logo_dark', file)
                        }
                    />

                    <BrandingAssetField
                        id="footer_logo"
                        label={t('Footer logo')}
                        surfaceLabel={t('Footer + widget')}
                        icon={PanelBottom}
                        description={t(
                            'Used in the public footer and the free-plan widget branding badge.',
                        )}
                        accept="image/png,image/jpeg,image/webp,image/svg+xml"
                        currentUrl={initial.footer_logo_url}
                        selectedFile={form.data.footer_logo}
                        fileName={form.data.footer_logo?.name}
                        error={form.errors.footer_logo}
                        siteTitle={form.data.site_title || t('Site title')}
                        displayMode={form.data.footer_brand_display}
                        onDisplayModeChange={(value) =>
                            form.setData('footer_brand_display', value)
                        }
                        surfacePreviewClassName="bg-gradient-to-br from-[#17181b] to-[#22252b]"
                        onChange={(file) => form.setData('footer_logo', file)}
                    />

                    <BrandingAssetField
                        id="footer_logo_dark"
                        label={t('Footer logo (dark mode)')}
                        surfaceLabel={t('Footer + widget')}
                        icon={PanelBottom}
                        description={t(
                            'Optional. Swapped in when the visitor or operator is in dark mode.',
                        )}
                        accept="image/png,image/jpeg,image/webp,image/svg+xml"
                        currentUrl={initial.footer_logo_dark_url}
                        selectedFile={form.data.footer_logo_dark}
                        fileName={form.data.footer_logo_dark?.name}
                        error={form.errors.footer_logo_dark}
                        siteTitle={form.data.site_title || t('Site title')}
                        displayMode={form.data.footer_brand_display}
                        onDisplayModeChange={(value) =>
                            form.setData('footer_brand_display', value)
                        }
                        surfacePreviewClassName="bg-gradient-to-br from-[#0c0e12] to-[#1a1d24]"
                        onChange={(file) =>
                            form.setData('footer_logo_dark', file)
                        }
                    />

                    <BrandingAssetField
                        id="dashboard_logo"
                        label={t('Dashboard logo')}
                        surfaceLabel={t('App shell')}
                        icon={LayoutDashboard}
                        description={t(
                            'Used in the logged-in app shell and platform admin sidebar.',
                        )}
                        accept="image/png,image/jpeg,image/webp,image/svg+xml"
                        currentUrl={initial.dashboard_logo_url}
                        selectedFile={form.data.dashboard_logo}
                        fileName={form.data.dashboard_logo?.name}
                        error={form.errors.dashboard_logo}
                        siteTitle={form.data.site_title || t('Site title')}
                        displayMode={form.data.dashboard_brand_display}
                        onDisplayModeChange={(value) =>
                            form.setData('dashboard_brand_display', value)
                        }
                        surfacePreviewClassName="bg-gradient-to-br from-muted/25 via-background to-muted/45"
                        onChange={(file) =>
                            form.setData('dashboard_logo', file)
                        }
                    />

                    <BrandingAssetField
                        id="dashboard_logo_dark"
                        label={t('Dashboard logo (dark mode)')}
                        surfaceLabel={t('App shell')}
                        icon={LayoutDashboard}
                        description={t(
                            'Optional. Swapped in when the operator is in dark mode.',
                        )}
                        accept="image/png,image/jpeg,image/webp,image/svg+xml"
                        currentUrl={initial.dashboard_logo_dark_url}
                        selectedFile={form.data.dashboard_logo_dark}
                        fileName={form.data.dashboard_logo_dark?.name}
                        error={form.errors.dashboard_logo_dark}
                        siteTitle={form.data.site_title || t('Site title')}
                        displayMode={form.data.dashboard_brand_display}
                        onDisplayModeChange={(value) =>
                            form.setData('dashboard_brand_display', value)
                        }
                        surfacePreviewClassName="bg-gradient-to-br from-[#0d1015] to-[#181c22]"
                        onChange={(file) =>
                            form.setData('dashboard_logo_dark', file)
                        }
                    />

                    <BrandingAssetField
                        id="favicon"
                        label={t('Favicon')}
                        surfaceLabel={t('Browser tab')}
                        icon={Globe2}
                        description={t(
                            'Used for browser tabs and the initial HTML shell before the app hydrates.',
                        )}
                        accept="image/png,image/jpeg,image/webp,image/svg+xml,image/x-icon"
                        currentUrl={initial.favicon_url}
                        selectedFile={form.data.favicon}
                        fileName={form.data.favicon?.name}
                        error={form.errors.favicon}
                        siteTitle={form.data.site_title || t('Site title')}
                        secondaryPreviewLabel={t('Tab preview')}
                        secondaryPreview={(previewUrl) => (
                            <div className="w-full rounded-xl border border-border/70 bg-background/95 p-3 shadow-xs">
                                <div className="flex items-center gap-2">
                                    {previewUrl ? (
                                        <img
                                            src={previewUrl}
                                            alt=""
                                            className="size-4 shrink-0 rounded-[4px] object-contain"
                                        />
                                    ) : (
                                        <span className="flex size-4 shrink-0 items-center justify-center rounded-[4px] bg-foreground text-[10px] font-bold text-background">
                                            {(
                                                form.data.site_title
                                                    .trim()
                                                    .charAt(0) || 'S'
                                            ).toUpperCase()}
                                        </span>
                                    )}
                                    <span className="truncate text-xs font-medium text-foreground">
                                        {form.data.site_title ||
                                            t('Site title')}
                                    </span>
                                </div>
                            </div>
                        )}
                        surfacePreviewClassName="bg-gradient-to-br from-background to-muted/35"
                        onChange={(file) => form.setData('favicon', file)}
                        previewClassName="h-8"
                    />
                </div>

                <div className="rounded-2xl border border-border/70 bg-card/70 p-5 shadow-sm">
                    <div className="grid gap-5 lg:grid-cols-[minmax(0,1fr)_280px] lg:items-start">
                        <div className="grid gap-4">
                            <div>
                                <h3 className="text-sm font-semibold text-foreground">
                                    {t('Widget footer link')}
                                </h3>
                                <p className="mt-1 text-xs leading-5 text-muted-foreground">
                                    {t(
                                        'This still controls the free-plan “Powered by …” link inside the visitor widget.',
                                    )}
                                </p>
                            </div>

                            <div className="grid gap-3 md:grid-cols-2">
                                <div className="grid gap-1">
                                    <Label htmlFor="pitchbar_brand_url">
                                        {t('Link URL')}
                                    </Label>
                                    <Input
                                        id="pitchbar_brand_url"
                                        type="url"
                                        autoComplete="off"
                                        value={form.data.pitchbar_brand_url}
                                        onChange={(e) =>
                                            form.setData(
                                                'pitchbar_brand_url',
                                                e.target.value,
                                            )
                                        }
                                        placeholder="https://pitchbar.thecodestudio.xyz"
                                    />
                                    {form.errors.pitchbar_brand_url ? (
                                        <p className="text-xs font-medium text-destructive">
                                            {form.errors.pitchbar_brand_url}
                                        </p>
                                    ) : null}
                                </div>

                                <div className="grid gap-1">
                                    <Label htmlFor="pitchbar_brand_label">
                                        {t('Label')}
                                    </Label>
                                    <Input
                                        id="pitchbar_brand_label"
                                        autoComplete="off"
                                        value={form.data.pitchbar_brand_label}
                                        onChange={(e) =>
                                            form.setData(
                                                'pitchbar_brand_label',
                                                e.target.value,
                                            )
                                        }
                                        placeholder={t('Powered by :brand', {
                                            brand,
                                        })}
                                    />
                                    {form.errors.pitchbar_brand_label ? (
                                        <p className="text-xs font-medium text-destructive">
                                            {form.errors.pitchbar_brand_label}
                                        </p>
                                    ) : null}
                                </div>
                            </div>

                            <Separator />

                            <p className="text-xs leading-5 text-muted-foreground">
                                {t(
                                    'Keep this label short so it reads cleanly inside the widget badge.',
                                )}
                            </p>
                        </div>

                        <div className="rounded-xl border border-border/70 bg-muted/15 p-4">
                            <p className="text-[11px] font-semibold tracking-[0.16em] text-muted-foreground uppercase">
                                {t('Widget badge preview')}
                            </p>
                            <div className="mt-3 flex min-h-[108px] items-end rounded-xl border border-dashed border-border/70 bg-[#111214] p-4">
                                <span className="inline-flex max-w-full items-center rounded-full bg-background px-3 py-2 text-xs font-medium text-foreground shadow-sm ring-1 ring-white/10">
                                    <span className="truncate">
                                        {form.data.pitchbar_brand_label ||
                                            t('Powered by :brand', { brand })}
                                    </span>
                                </span>
                            </div>
                            <p className="mt-3 text-[11px] leading-5 text-muted-foreground">
                                {t('Link target:')}{' '}
                                {form.data.pitchbar_brand_url ||
                                    t('No URL set yet')}
                            </p>
                        </div>
                    </div>
                </div>

                <div className="rounded-xl border border-border/70 bg-muted/15 p-4">
                    <label className="flex cursor-pointer items-start gap-3">
                        <input
                            type="checkbox"
                            checked={form.data.marketing_site_enabled}
                            onChange={(e) =>
                                form.setData(
                                    'marketing_site_enabled',
                                    e.target.checked,
                                )
                            }
                            className="mt-0.5 size-4 shrink-0 rounded border-border focus:ring-2 focus:ring-ring/40"
                        />
                        <div className="min-w-0">
                            <p className="text-sm font-medium text-foreground">
                                {t('Public marketing site')}
                            </p>
                            <p className="mt-1 text-xs leading-5 text-muted-foreground">
                                {t(
                                    'When on, anyone landing on /, /pricing, /changelog, or /documentation sees the public marketing site. Turn it off for a private SaaS install — visitors are redirected to /login instead, and search engines are told to skip the whole domain. Privacy / terms / auth flows stay reachable always.',
                                )}
                            </p>
                        </div>
                    </label>
                </div>

                {/* Auth-page side-panel copy (Aurora + Prism themes).
                    Blank = theme default copy. Buyer-reported: hard-
                    coded marketing-site phrasing leaked through to
                    workspaces whose product is not a marketing surface. */}
                <div className="grid gap-3 rounded-xl border border-border/70 bg-muted/15 p-4">
                    <div>
                        <h3 className="text-sm font-semibold text-foreground">
                            {t('Auth page side panel')}
                        </h3>
                        <p className="mt-1 text-xs leading-5 text-muted-foreground">
                            {t(
                                'Customise the copy that appears on the login / register / forgot-password pages of the Aurora and Prism marketing themes. Leave blank to use the theme’s default.',
                            )}
                        </p>
                    </div>
                    <div className="grid gap-3 md:grid-cols-2">
                        <div className="grid gap-1">
                            <Label htmlFor="auth_aside_eyebrow">
                                {t('Eyebrow tag')}
                            </Label>
                            <Input
                                id="auth_aside_eyebrow"
                                autoComplete="off"
                                value={form.data.auth_aside_eyebrow}
                                onChange={(e) =>
                                    form.setData(
                                        'auth_aside_eyebrow',
                                        e.target.value,
                                    )
                                }
                                placeholder="SECURE WORKSPACE"
                            />
                        </div>
                        <div className="grid gap-1">
                            <Label htmlFor="auth_aside_heading">
                                {t('Heading')}
                            </Label>
                            <Input
                                id="auth_aside_heading"
                                autoComplete="off"
                                value={form.data.auth_aside_heading}
                                onChange={(e) =>
                                    form.setData(
                                        'auth_aside_heading',
                                        e.target.value,
                                    )
                                }
                                placeholder="Sign in to the signal."
                            />
                        </div>
                    </div>
                    <div className="grid gap-1">
                        <Label htmlFor="auth_aside_lede">
                            {t('Lede paragraph')}
                        </Label>
                        <textarea
                            id="auth_aside_lede"
                            rows={3}
                            value={form.data.auth_aside_lede}
                            onChange={(e) =>
                                form.setData('auth_aside_lede', e.target.value)
                            }
                            className="min-h-[80px] w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
                            placeholder={t(
                                'Short paragraph shown under the heading. Speaks in your brand voice — keep under ~200 characters.',
                            )}
                        />
                    </div>
                    <div className="grid gap-1">
                        <Label>{t('Bullet points (up to 6)')}</Label>
                        {[0, 1, 2, 3, 4, 5].map((i) => (
                            <Input
                                key={i}
                                autoComplete="off"
                                value={form.data.auth_aside_bullets[i] ?? ''}
                                onChange={(e) => {
                                    const next = [
                                        ...form.data.auth_aside_bullets,
                                    ];
                                    next[i] = e.target.value;
                                    form.setData(
                                        'auth_aside_bullets',
                                        next.filter(
                                            (_, idx) =>
                                                idx < 6 &&
                                                (idx <= i ||
                                                    (next[idx] ?? '').trim() !==
                                                        ''),
                                        ),
                                    );
                                }}
                                placeholder={t('Bullet point :n', { n: i + 1 })}
                            />
                        ))}
                        <p className="text-xs text-muted-foreground">
                            {t(
                                'Empty rows are dropped on save. Leave all blank to fall back to the theme defaults.',
                            )}
                        </p>
                    </div>
                </div>

                <div className="flex justify-end">
                    <Button type="submit" disabled={form.processing}>
                        {t('Save branding')}
                    </Button>
                </div>
            </form>
        </SectionShell>
    );
}

function MarketingSection({
    summary,
    initial,
}: {
    summary: MarketingSummary;
    initial: FormValues;
}) {
    const { t } = useT();

    return (
        <>
            <SectionShell
                icon={Sparkles}
                title={t('Marketing homepage')}
                description={t(
                    'Manage the public landing page with structured fields instead of raw JSON. Missing keys still fall back to the server defaults when you save.',
                )}
                statusPill={<StatusPill configured={summary.customized} />}
            >
                <MarketingContentEditor
                    initial={initial.marketing_home_content}
                />
            </SectionShell>

            <MarketingThemeSection
                themeOptions={summary.theme_options ?? []}
                initialTheme={initial.marketing_theme}
            />

            <MarketingWidgetSection
                agentOptions={summary.agent_options ?? []}
                initialEnabled={initial.marketing_widget_enabled}
                initialAgentId={initial.marketing_widget_agent_id}
            />

            <PricingFaqsSection initial={initial.pricing_faqs ?? []} />
        </>
    );
}

function MarketingThemeSection({
    themeOptions,
    initialTheme,
}: {
    themeOptions: Array<{
        slug: string;
        name: string;
        description: string | null;
    }>;
    initialTheme: string;
}) {
    const { t } = useT();
    const form = useForm<{ marketing_theme: string }>({
        marketing_theme: initialTheme,
    });

    const activeTheme = themeOptions.find(
        (opt) => opt.slug === form.data.marketing_theme,
    );

    return (
        <SectionShell
            icon={Sparkles}
            title={t('Marketing theme')}
            description={t(
                'Swap the entire marketing-site layout (home, pricing, how-it-works, integrations, privacy, terms, changelog) to a different theme bundle. Operators add new themes under resources/js/pages/marketing-themes/{slug}/ — see the Marketing themes documentation page for the convention.',
            )}
            statusPill={
                <span className="rounded-full border border-border bg-background px-2 py-0.5 text-[10px] font-semibold tracking-wide text-foreground uppercase">
                    {activeTheme?.name ?? form.data.marketing_theme}
                </span>
            }
        >
            <form
                onSubmit={(e) => {
                    e.preventDefault();
                    form.patch(
                        updateSystemSettings({ section: 'marketing' }).url,
                        { preserveScroll: true },
                    );
                }}
                className="space-y-4"
            >
                <div className="grid gap-1.5">
                    <Label htmlFor="marketing-theme">{t('Active theme')}</Label>
                    <select
                        id="marketing-theme"
                        className="rounded-md border bg-background px-2 py-1.5 text-sm"
                        value={form.data.marketing_theme}
                        onChange={(e) =>
                            form.setData('marketing_theme', e.target.value)
                        }
                    >
                        {themeOptions.map((opt) => (
                            <option key={opt.slug} value={opt.slug}>
                                {opt.name}
                            </option>
                        ))}
                    </select>
                    {activeTheme?.description && (
                        <p className="text-xs text-muted-foreground">
                            {activeTheme.description}
                        </p>
                    )}
                    {themeOptions.length === 1 && (
                        <p className="text-xs text-muted-foreground">
                            {t(
                                'Only the built-in Harvest theme is installed. Add a folder at resources/js/pages/marketing-themes/{slug}/ with a theme.json manifest to register more themes.',
                            )}
                        </p>
                    )}
                </div>

                <div className="flex justify-end">
                    <Button type="submit" disabled={form.processing}>
                        {t('Save theme')}
                    </Button>
                </div>
            </form>
        </SectionShell>
    );
}

function MarketingWidgetSection({
    agentOptions,
    initialEnabled,
    initialAgentId,
}: {
    agentOptions: Array<{ id: string; label: string }>;
    initialEnabled: boolean;
    initialAgentId: string | null;
}) {
    const { t } = useT();
    const form = useForm<{
        marketing_widget_enabled: boolean;
        marketing_widget_agent_id: string | null;
    }>({
        marketing_widget_enabled: initialEnabled,
        marketing_widget_agent_id: initialAgentId,
    });

    return (
        <SectionShell
            icon={Sparkles}
            title={t('Marketing-site widget')}
            description={t(
                'Mount one of your published agents on the public marketing pages (/, /welcome, /marketing/*). Anonymous visitors get a real chat surface, not a demo sandbox.',
            )}
            statusPill={
                <StatusPill
                    configured={initialEnabled && initialAgentId !== null}
                />
            }
        >
            <form
                onSubmit={(e) => {
                    e.preventDefault();
                    form.patch(
                        updateSystemSettings({ section: 'marketing' }).url,
                        { preserveScroll: true },
                    );
                }}
                className="space-y-4"
            >
                <label className="flex cursor-pointer items-start gap-3 rounded-md border bg-background p-3">
                    <input
                        type="checkbox"
                        checked={form.data.marketing_widget_enabled}
                        onChange={(e) =>
                            form.setData(
                                'marketing_widget_enabled',
                                (e.target as HTMLInputElement).checked,
                            )
                        }
                        className="mt-0.5 size-4 shrink-0 rounded border-border text-foreground focus:ring-2 focus:ring-ring/40"
                    />
                    <div className="min-w-0">
                        <p className="text-sm font-medium text-foreground">
                            {t('Enable widget on marketing pages')}
                        </p>
                        <p className="mt-1 text-xs leading-5 text-muted-foreground">
                            {t(
                                'When off, the marketing site has no chat surface (default). When on, the selected agent below mounts for anonymous visitors only — signed-in admins never see it.',
                            )}
                        </p>
                    </div>
                </label>

                <div className="grid gap-1.5">
                    <Label htmlFor="marketing-widget-agent">
                        {t('Agent to mount')}
                    </Label>
                    <select
                        id="marketing-widget-agent"
                        className="rounded-md border bg-background px-2 py-1.5 text-sm"
                        value={form.data.marketing_widget_agent_id ?? ''}
                        onChange={(e) =>
                            form.setData(
                                'marketing_widget_agent_id',
                                e.target.value === '' ? null : e.target.value,
                            )
                        }
                        disabled={!form.data.marketing_widget_enabled}
                    >
                        <option value="">{t('— Pick an agent —')}</option>
                        {agentOptions.map((opt) => (
                            <option key={opt.id} value={opt.id}>
                                {opt.label}
                            </option>
                        ))}
                    </select>
                    {agentOptions.length === 0 && (
                        <p className="text-xs text-amber-700 dark:text-amber-300">
                            {t(
                                'No published agents found. Publish an agent first, then come back here to wire it up.',
                            )}
                        </p>
                    )}
                </div>

                <div className="flex justify-end">
                    <Button type="submit" disabled={form.processing}>
                        {t('Save marketing widget')}
                    </Button>
                </div>
            </form>
        </SectionShell>
    );
}

type FaqRow = { q: string; a: string };

function PricingFaqsSection({ initial }: { initial: FaqRow[] }) {
    const { t } = useT();
    const form = useForm<{ pricing_faqs: FaqRow[] }>({
        pricing_faqs: initial,
    });

    const update = (idx: number, field: 'q' | 'a', value: string) => {
        const next = [...form.data.pricing_faqs];
        next[idx] = { ...next[idx], [field]: value };
        form.setData('pricing_faqs', next);
    };

    const add = () => {
        form.setData('pricing_faqs', [
            ...form.data.pricing_faqs,
            { q: '', a: '' },
        ]);
    };

    const remove = (idx: number) => {
        form.setData(
            'pricing_faqs',
            form.data.pricing_faqs.filter((_, i) => i !== idx),
        );
    };

    return (
        <SectionShell
            icon={Sparkles}
            title={t('Pricing page FAQ')}
            description={t(
                'Edit the FAQ shown on the public /pricing page. Saved entries override the default copy; clear all rows to fall back to defaults.',
            )}
        >
            <form
                onSubmit={(e) => {
                    e.preventDefault();
                    form.patch(
                        updateSystemSettings({ section: 'pricing' }).url,
                    );
                }}
                className="space-y-3"
            >
                {form.data.pricing_faqs.map((row, i) => (
                    <div
                        key={i}
                        className="grid gap-2 rounded-lg border bg-muted/15 p-3"
                    >
                        <Input
                            placeholder={t('Question')}
                            value={row.q}
                            onChange={(e) => update(i, 'q', e.target.value)}
                            maxLength={240}
                        />
                        <textarea
                            placeholder={t('Answer')}
                            value={row.a}
                            onChange={(e) => update(i, 'a', e.target.value)}
                            maxLength={2000}
                            rows={3}
                            className="rounded-md border bg-background p-2 text-sm"
                        />
                        <div className="flex justify-end">
                            <Button
                                type="button"
                                variant="ghost"
                                size="sm"
                                onClick={() => remove(i)}
                            >
                                {t('Remove')}
                            </Button>
                        </div>
                    </div>
                ))}
                <div className="flex justify-between gap-2">
                    <Button type="button" variant="outline" onClick={add}>
                        {t('Add FAQ')}
                    </Button>
                    <Button type="submit" disabled={form.processing}>
                        {t('Save FAQ')}
                    </Button>
                </div>
            </form>
        </SectionShell>
    );
}

const INTEGRATION_KINDS: {
    key: keyof IntegrationToggleState;
    label: string;
}[] = [
    { key: 'slack', label: 'Slack' },
    { key: 'webhooks', label: 'Outbound webhooks' },
    { key: 'notion', label: 'Notion' },
    { key: 'google', label: 'Google Drive (Docs)' },
    { key: 'wordpress', label: 'WordPress & WooCommerce' },
];

type IntegrationToggleState = {
    slack?: boolean;
    notion?: boolean;
    google?: boolean;
    webhooks?: boolean;
    wordpress?: boolean;
};

function IntegrationsToggleSection({
    initial,
}: {
    initial: IntegrationToggleState;
}) {
    const { t } = useT();
    const form = useForm<{ integrations_enabled: IntegrationToggleState }>({
        integrations_enabled: initial,
    });

    const toggle = (kind: keyof IntegrationToggleState, on: boolean) => {
        form.setData('integrations_enabled', {
            ...form.data.integrations_enabled,
            [kind]: on,
        });
    };

    return (
        <SectionShell
            icon={Sparkles}
            title={t('Integrations available to workspaces')}
            description={t(
                'Turn an integration off here to hide its card from every workspace owner under /app/integrations. Disabled integrations still preserve any existing connections in the DB.',
            )}
        >
            <form
                onSubmit={(e) => {
                    e.preventDefault();
                    form.patch(
                        updateSystemSettings({ section: 'integrations' }).url,
                    );
                }}
                className="space-y-3"
            >
                <div className="grid gap-2">
                    {INTEGRATION_KINDS.map(({ key, label }) => {
                        const current =
                            form.data.integrations_enabled[key] !== false;

                        return (
                            <label
                                key={key}
                                className="flex items-center justify-between rounded-lg border bg-muted/15 p-3"
                            >
                                <span className="text-sm font-medium">
                                    {t(label)}
                                </span>
                                <input
                                    type="checkbox"
                                    checked={current}
                                    onChange={(e) =>
                                        toggle(key, e.target.checked)
                                    }
                                    className="size-4"
                                />
                            </label>
                        );
                    })}
                </div>
                <div className="flex justify-end">
                    <Button type="submit" disabled={form.processing}>
                        {t('Save integrations')}
                    </Button>
                </div>
            </form>
        </SectionShell>
    );
}

type IntegrationCardRow = {
    key: string;
    name: string;
    category: string;
    tagline: string;
    description: string;
};

function IntegrationCardsSection({
    initial,
}: {
    initial: IntegrationCardRow[];
}) {
    const { t } = useT();
    const form = useForm<{ integration_cards: IntegrationCardRow[] }>({
        integration_cards: initial,
    });

    const updateField = (
        index: number,
        field: keyof Omit<IntegrationCardRow, 'key'>,
        value: string,
    ) => {
        const next = form.data.integration_cards.map((row, i) =>
            i === index ? { ...row, [field]: value } : row,
        );
        form.setData('integration_cards', next);
    };

    return (
        <SectionShell
            icon={Sparkles}
            title={t('Integration card copy')}
            description={t(
                'Edit the name, category, tagline, and description of each card on the public /integrations page. Leave a field blank to fall back to the shipped default.',
            )}
        >
            <form
                onSubmit={(e) => {
                    e.preventDefault();
                    form.patch(
                        updateSystemSettings({ section: 'integrations' }).url,
                    );
                }}
                className="space-y-4"
            >
                <div className="space-y-4">
                    {form.data.integration_cards.map((row, index) => (
                        <div
                            key={row.key}
                            className="space-y-3 rounded-lg border bg-muted/15 p-4"
                        >
                            <div className="text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                                {row.key}
                            </div>
                            <div className="grid gap-3 sm:grid-cols-2">
                                <label className="space-y-1 text-sm">
                                    <span className="font-medium">
                                        {t('Name')}
                                    </span>
                                    <Input
                                        value={row.name}
                                        onChange={(e) =>
                                            updateField(
                                                index,
                                                'name',
                                                e.target.value,
                                            )
                                        }
                                    />
                                </label>
                                <label className="space-y-1 text-sm">
                                    <span className="font-medium">
                                        {t('Category')}
                                    </span>
                                    <Input
                                        value={row.category}
                                        onChange={(e) =>
                                            updateField(
                                                index,
                                                'category',
                                                e.target.value,
                                            )
                                        }
                                    />
                                </label>
                            </div>
                            <label className="space-y-1 text-sm">
                                <span className="font-medium">
                                    {t('Tagline')}
                                </span>
                                <Input
                                    value={row.tagline}
                                    onChange={(e) =>
                                        updateField(
                                            index,
                                            'tagline',
                                            e.target.value,
                                        )
                                    }
                                />
                            </label>
                            <label className="space-y-1 text-sm">
                                <span className="font-medium">
                                    {t('Description')}
                                </span>
                                <textarea
                                    rows={3}
                                    value={row.description}
                                    onChange={(e) =>
                                        updateField(
                                            index,
                                            'description',
                                            e.target.value,
                                        )
                                    }
                                    className="w-full rounded-md border bg-background p-2 text-sm"
                                />
                            </label>
                        </div>
                    ))}
                </div>
                <div className="flex justify-end">
                    <Button type="submit" disabled={form.processing}>
                        {t('Save card copy')}
                    </Button>
                </div>
            </form>
        </SectionShell>
    );
}

function PrivacySection({
    summary,
    initial,
}: {
    summary: PrivacySummary;
    initial: FormValues;
}) {
    const { t } = useT();

    return (
        <SectionShell
            icon={Shield}
            title={t('Privacy & GDPR')}
            description={t(
                'Manage the public privacy policy and the GDPR request guidance shown on the marketing site.',
            )}
            statusPill={<StatusPill configured={summary.customized} />}
        >
            <PrivacyPolicyEditor initial={initial.privacy_policy_content} />
        </SectionShell>
    );
}

function ReadOnlyHealthSection({
    icon: Icon,
    title,
    description,
    statusPill,
    rows,
    testEndpoint,
    testLabel,
}: {
    icon: LucideIcon;
    title: string;
    description: string;
    statusPill?: React.ReactNode;
    rows: { label: string; value: React.ReactNode }[];
    testEndpoint?: string;
    testLabel?: string;
}) {
    return (
        <SectionShell
            icon={Icon}
            title={title}
            description={description}
            statusPill={statusPill}
            testEndpoint={testEndpoint}
            testLabel={testLabel}
        >
            <div className="space-y-1.5">
                {rows.map((r) => (
                    <div
                        key={r.label}
                        className="grid grid-cols-[140px_1fr] gap-3 text-sm"
                    >
                        <span className="text-muted-foreground">{r.label}</span>
                        <span className="font-mono text-xs break-all">
                            {r.value === null ||
                            r.value === '' ||
                            r.value === undefined ? (
                                <span className="text-muted-foreground/60">
                                    —
                                </span>
                            ) : (
                                r.value
                            )}
                        </span>
                    </div>
                ))}
            </div>
        </SectionShell>
    );
}

type TabKey =
    | 'billing'
    | 'ai'
    | 'mail'
    | 'integrations'
    | 'features'
    | 'cron'
    | 'health';

const TAB_KEYS: TabKey[] = [
    'billing',
    'ai',
    'mail',
    'integrations',
    'features',
    'cron',
    'health',
];

export default function SystemSettings({
    page,
    sections,
    form: initial,
}: Props) {
    const { t } = useT();

    const TABS: { key: TabKey; label: string; icon: LucideIcon }[] = [
        { key: 'billing', label: t('Billing'), icon: CreditCard },
        { key: 'ai', label: t('AI providers'), icon: Sparkles },
        { key: 'mail', label: t('Mail'), icon: Mail },
        { key: 'integrations', label: t('Integrations'), icon: Workflow },
        { key: 'features', label: t('Features'), icon: Sparkles },
        { key: 'cron', label: t('Cron worker'), icon: Workflow },
        { key: 'health', label: t('Health'), icon: HardDrive },
    ];

    // Persist the active tab in the URL hash so a refresh / share-link
    // lands the visitor back where they were. Falls back to the first
    // tab when the hash is missing or unknown.
    const initialTab: TabKey =
        typeof window !== 'undefined' &&
        TAB_KEYS.includes(window.location.hash.slice(1) as TabKey)
            ? (window.location.hash.slice(1) as TabKey)
            : 'billing';
    const [active, setActive] = useState<TabKey>(initialTab);

    const switchTab = (key: TabKey) => {
        setActive(key);

        if (typeof window !== 'undefined') {
            // history.replaceState avoids polluting back/forward history
            // with every tab click — refreshes still land on the right tab.
            window.history.replaceState(
                null,
                '',
                `${window.location.pathname}#${key}`,
            );
        }
    };

    const pageMeta: Record<
        SettingsPage,
        { headTitle: string; title: string; description: string }
    > = {
        system: {
            headTitle: t('System settings'),
            title: t('System config'),
            description: t(
                'Edit provider keys and run smoke tests. Sensitive values are encrypted at rest and never sent back to the browser; leave a secret blank to keep its current value.',
            ),
        },
        branding: {
            headTitle: t('Branding settings'),
            title: t('Branding'),
            description: t(
                'Manage the global site title, uploaded logos, favicon, and widget footer branding from one place.',
            ),
        },
        marketing: {
            headTitle: t('Marketing site settings'),
            title: t('Marketing site'),
            description: t(
                'Edit the landing page using structured fields so content stays easy to manage and review.',
            ),
        },
        privacy: {
            headTitle: t('Privacy settings'),
            title: t('Privacy & GDPR'),
            description: t(
                'Control the public privacy policy copy, contact details, and visitor rights guidance from the admin settings area.',
            ),
        },
    };

    return (
        <>
            <Head title={pageMeta[page].headTitle} />
            <Heading
                variant="small"
                title={pageMeta[page].title}
                description={pageMeta[page].description}
            />

            {page === 'system' && (
                <div
                    className="mt-4 flex gap-1 overflow-x-auto border-b"
                    role="tablist"
                    aria-label={t('System config sections')}
                >
                    {TABS.map((tab) => {
                        const Icon = tab.icon;
                        const isActive = active === tab.key;

                        return (
                            <button
                                key={tab.key}
                                type="button"
                                role="tab"
                                aria-selected={isActive}
                                aria-controls={`system-tab-${tab.key}`}
                                onClick={() => switchTab(tab.key)}
                                className={`-mb-px flex items-center gap-2 border-b-2 px-3 py-2 text-sm whitespace-nowrap transition ${
                                    isActive
                                        ? 'border-foreground font-medium text-foreground'
                                        : 'border-transparent text-muted-foreground hover:text-foreground'
                                }`}
                            >
                                <Icon className="size-4" />
                                {tab.label}
                            </button>
                        );
                    })}
                </div>
            )}

            <div
                className="mt-4 space-y-4"
                role="tabpanel"
                id={`system-tab-${page === 'system' ? active : page}`}
            >
                {page === 'system' && active === 'billing' && (
                    <>
                        <GatewayTogglesSection initial={initial} />
                        <StripeSection
                            summary={sections.stripe}
                            initial={initial}
                        />
                        <PayPalSection
                            summary={sections.paypal}
                            initial={initial}
                        />
                        <RazorpaySection
                            summary={sections.razorpay}
                            initial={initial}
                        />
                    </>
                )}

                {page === 'system' && active === 'ai' && (
                    <>
                        <CloudflareSection
                            summary={sections.llm}
                            initial={initial}
                        />
                        <OpenAiSection
                            summary={sections.llm}
                            initial={initial}
                        />
                        <OpenRouterSection
                            summary={sections.llm}
                            initial={initial}
                        />
                        <RoutingSection initial={initial} />
                        <ByokGlobalSection initial={initial} />
                    </>
                )}

                {page === 'system' && active === 'mail' && (
                    <>
                        <MailSection
                            summary={sections.mail}
                            initial={initial}
                        />
                        <AdminDigestSection initial={initial} />
                    </>
                )}

                {page === 'system' && active === 'integrations' && (
                    <>
                        <IntegrationsToggleSection
                            initial={initial.integrations_enabled ?? {}}
                        />
                        <IntegrationCardsSection
                            initial={initial.integration_cards ?? []}
                        />
                        <WordpressPluginSection initial={initial} />
                    </>
                )}

                {page === 'system' && active === 'features' && (
                    <>
                        <SignupVerificationSection initial={initial} />
                    </>
                )}

                {page === 'system' && active === 'cron' && (
                    <CronWorkerSection summary={sections.cron_worker} />
                )}

                {page === 'branding' && <BrandingSection initial={initial} />}

                {page === 'marketing' && (
                    <MarketingSection
                        summary={sections.marketing}
                        initial={initial}
                    />
                )}

                {page === 'privacy' && (
                    <PrivacySection
                        summary={sections.privacy}
                        initial={initial}
                    />
                )}

                {page === 'system' && active === 'health' && (
                    <>
                        <ReadOnlyHealthSection
                            icon={Sparkles}
                            title={t('LLM (chat) probe')}
                            description={t(
                                'Verifies the currently-resolved chat provider responds end-to-end.',
                            )}
                            statusPill={
                                <StatusPill
                                    configured={sections.llm.configured}
                                />
                            }
                            testEndpoint="/settings/system/test/llm"
                            testLabel={t('Run chat probe')}
                            rows={[
                                {
                                    label: 'LLM_PROVIDER',
                                    value: sections.llm.provider_env,
                                },
                                {
                                    label: t('Resolved'),
                                    value: sections.llm.resolved,
                                },
                            ]}
                        />

                        <ReadOnlyHealthSection
                            icon={Sparkles}
                            title={t('LLM (embed) probe')}
                            description={t(
                                'Verifies the embed endpoint produces a vector.',
                            )}
                            statusPill={
                                <StatusPill
                                    configured={sections.llm.configured}
                                />
                            }
                            testEndpoint="/settings/system/test/embed"
                            testLabel={t('Run embed probe')}
                            rows={[
                                {
                                    label: t('Resolved'),
                                    value: sections.llm.resolved,
                                },
                            ]}
                        />

                        <ReadOnlyHealthSection
                            icon={HardDrive}
                            title={t('Cache (Redis)')}
                            description={t(
                                'Backs queues, sessions, and short-TTL RAG history.',
                            )}
                            statusPill={
                                <StatusPill
                                    configured={sections.cache.configured}
                                />
                            }
                            testEndpoint="/settings/system/test/cache"
                            testLabel={t('Run write/read probe')}
                            rows={[
                                {
                                    label: t('Driver'),
                                    value: sections.cache.driver,
                                },
                                {
                                    label: t('Redis host'),
                                    value: sections.cache.redis_host,
                                },
                                {
                                    label: t('Redis port'),
                                    value: sections.cache.redis_port,
                                },
                            ]}
                        />

                        <ReadOnlyHealthSection
                            icon={Database}
                            title={t('Vector store')}
                            description={t(
                                'Embedding storage + similarity search. Provider auto-picks from configured keys.',
                            )}
                            statusPill={
                                <StatusPill
                                    configured={sections.vector.configured}
                                />
                            }
                            rows={[
                                {
                                    label: 'VECTOR_PROVIDER',
                                    value: sections.vector.provider_env,
                                },
                                {
                                    label: t('Resolved'),
                                    value: sections.vector.resolved,
                                },
                                {
                                    label: t('Vectorize index'),
                                    value: sections.vector.vectorize_index,
                                },
                                {
                                    label: t('Qdrant URL'),
                                    value: sections.vector.qdrant_url,
                                },
                            ]}
                        />

                        <ReadOnlyHealthSection
                            icon={Radio}
                            title={t('Reverb (WebSocket)')}
                            description={t(
                                'Live broadcasts for the inbox + widget human-takeover.',
                            )}
                            statusPill={
                                <StatusPill
                                    configured={sections.reverb.configured}
                                />
                            }
                            rows={[
                                {
                                    label: t('App key'),
                                    value: sections.reverb.app_key,
                                },
                                {
                                    label: t('Host'),
                                    value: sections.reverb.host,
                                },
                                {
                                    label: t('Port'),
                                    value: sections.reverb.port,
                                },
                                {
                                    label: t('Scheme'),
                                    value: sections.reverb.scheme,
                                },
                            ]}
                        />
                    </>
                )}
            </div>
        </>
    );
}

SystemSettings.layout = {
    breadcrumbs: [
        {
            title: 'System config',
            href: '/settings/system',
        },
    ],
};
