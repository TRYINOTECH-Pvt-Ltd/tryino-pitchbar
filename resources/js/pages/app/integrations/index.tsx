import { Head, router, useForm, usePage } from '@inertiajs/react';
import {
    BookOpen,
    CheckCircle2,
    Copy,
    FileText,
    Globe,
    RefreshCcw,
    Send,
    Slack,
    ShoppingBag,
} from 'lucide-react';
import { useEffect, useState } from 'react';
import { useConfirm } from '@/components/confirm-dialog-provider';
import InputError from '@/components/input-error';
import { PlanLimitBanner } from '@/components/plan-limit-banner';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useBranding } from '@/hooks/use-branding';
import AppLayout from '@/layouts/app-layout';
import { useT } from '@/lib/i18n';
import { relativeTime } from '@/lib/relative-time';
import {
    destroy as destroyIntegration,
    index as integrationsIndex,
} from '@/routes/integrations';
import { store as storeSlack } from '@/routes/integrations/slack';
import {
    destroy as destroyWebhook,
    store as storeWebhook,
    update as updateWebhook,
} from '@/routes/integrations/webhooks';
import { start as startGoogle } from '@/routes/oauth/google';
import { start as startNotion } from '@/routes/oauth/notion';

type Integration = {
    id: string;
    kind: 'slack' | 'notion' | 'google' | string;
    is_configured: boolean;
    webhook_hint: string | null;
    status: string;
    last_sync_at: string | null;
    summary: string | null;
};

type WordpressConnection = {
    agent_id: string;
    agent_name: string;
    site_url: string | null;
    plugin_version: string | null;
    wordpress_version: string | null;
    woocommerce_active: boolean | null;
    last_seen_at: string | null;
};

type IntegrationsEnabledMap = {
    slack: boolean;
    notion: boolean;
    google: boolean;
    webhooks: boolean;
    wordpress: boolean;
};

type WordpressPluginConfig = {
    download_url: string | null;
    help_text: string | null;
};

type Props = {
    integrations: Integration[];
    wordpressConnections: WordpressConnection[];
    webhookSubscriptions: WebhookSubscription[];
    webhookEventOptions: WebhookEventOption[];
    integrationsEnabled: IntegrationsEnabledMap;
    wordpressPlugin?: WordpressPluginConfig;
};

type WebhookSubscription = {
    id: string;
    url: string;
    host: string | null;
    enabled: boolean;
    events: string[];
    event_labels: string[];
    secret_hint: string;
    created_at: string | null;
    updated_at: string | null;
};

type WebhookEventOption = {
    value: string;
    label: string;
};

export default function IntegrationsPage({
    integrations,
    wordpressConnections,
    webhookSubscriptions,
    webhookEventOptions,
    integrationsEnabled,
    wordpressPlugin,
}: Props) {
    const { t } = useT();
    const confirm = useConfirm();
    const branding = useBranding();
    const brand = branding.site_title || 'Pitchbar';
    const pageProps = usePage().props as {
        flash?: {
            rotated_secret?: string;
            rotated_subscription_id?: string;
        };
    };
    const rotatedSecret = pageProps.flash?.rotated_secret ?? null;
    const rotatedSubscriptionId =
        pageProps.flash?.rotated_subscription_id ?? null;
    const [showRotatedSecret, setShowRotatedSecret] = useState<{
        id: string;
        secret: string;
    } | null>(null);
    const [copied, setCopied] = useState(false);

    useEffect(() => {
        if (rotatedSecret && rotatedSubscriptionId) {
            // eslint-disable-next-line react-hooks/set-state-in-effect -- surface the one-shot rotated secret delivered via server flash
            setShowRotatedSecret({
                id: rotatedSubscriptionId,
                secret: rotatedSecret,
            });
            setCopied(false);
        }
    }, [rotatedSecret, rotatedSubscriptionId]);
    const slack = integrations.find((i) => i.kind === 'slack') ?? null;
    const notion = integrations.find((i) => i.kind === 'notion') ?? null;
    const google = integrations.find((i) => i.kind === 'google') ?? null;
    const [showForm, setShowForm] = useState(slack === null);
    const [showWebhookForm, setShowWebhookForm] = useState(
        webhookSubscriptions.length === 0,
    );
    const [editingWebhookId, setEditingWebhookId] = useState<string | null>(
        null,
    );
    const defaultWebhookEvents =
        webhookEventOptions.length > 0
            ? webhookEventOptions.map((option) => option.value)
            : ['lead.captured'];

    const form = useForm<{ webhook_url: string; send_test: boolean }>({
        webhook_url: '',
        send_test: true,
    });

    const webhookForm = useForm<{
        url: string;
        secret: string;
        enabled: boolean;
        events: string[];
    }>({
        url: '',
        secret: '',
        enabled: true,
        events: defaultWebhookEvents,
    });

    const startCreatingWebhook = () => {
        setEditingWebhookId(null);
        webhookForm.reset();
        webhookForm.clearErrors();
        setShowWebhookForm(true);
    };

    const startEditingWebhook = (subscription: WebhookSubscription) => {
        setEditingWebhookId(subscription.id);
        webhookForm.clearErrors();
        webhookForm.setData('url', subscription.url);
        webhookForm.setData('secret', '');
        webhookForm.setData('enabled', subscription.enabled);
        webhookForm.setData(
            'events',
            subscription.events.length > 0
                ? subscription.events
                : defaultWebhookEvents,
        );
        setShowWebhookForm(true);
    };

    const resetWebhookForm = () => {
        setEditingWebhookId(null);
        webhookForm.reset();
        webhookForm.clearErrors();
        setShowWebhookForm(false);
    };

    const toggleWebhookEvent = (value: string, checked: boolean) => {
        const nextEvents = checked
            ? Array.from(new Set([...webhookForm.data.events, value]))
            : webhookForm.data.events.filter((event) => event !== value);

        webhookForm.setData('events', nextEvents);
    };

    return (
        <AppLayout
            breadcrumbs={[
                {
                    title: t('Integrations'),
                    href: integrationsIndex.url(),
                },
            ]}
        >
            <Head title={t('Integrations')} />
            <div className="flex flex-1 flex-col gap-4 p-4">
                <div>
                    <h1 className="text-2xl font-semibold tracking-tight">
                        {t('Integrations')}
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        {t(
                            'Push captured leads to the tools your team already lives in.',
                        )}
                    </p>
                </div>

                <PlanLimitBanner resource="integration" />

                {integrationsEnabled.slack && (
                    <Card className="p-5">
                        <div className="flex items-start justify-between gap-4">
                            <div className="flex items-start gap-3">
                                <Slack className="mt-0.5 size-6 text-purple-600" />
                                <div>
                                    <p className="font-medium">{t('Slack')}</p>
                                    <p className="mt-0.5 text-sm text-muted-foreground">
                                        {t(
                                            'Get a message in any channel as soon as a visitor hands over their email.',
                                        )}
                                    </p>
                                    {slack?.is_configured && (
                                        <p className="mt-2 inline-flex flex-wrap items-center gap-1 text-xs text-emerald-600 dark:text-emerald-400">
                                            <CheckCircle2 className="size-3.5" />{' '}
                                            {t('Connected')}
                                            {slack.webhook_hint && (
                                                <span className="text-muted-foreground">
                                                    · {slack.webhook_hint}
                                                </span>
                                            )}
                                            {slack.summary && (
                                                <span className="text-muted-foreground">
                                                    · {slack.summary}
                                                </span>
                                            )}
                                        </p>
                                    )}
                                </div>
                            </div>
                            <div className="flex shrink-0 gap-2">
                                {slack?.is_configured && (
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        onClick={async () => {
                                            const ok = await confirm({
                                                title: t('Disconnect Slack?'),
                                                message: t('Disconnect Slack?'),
                                                confirmLabel: t('Disconnect'),
                                                danger: true,
                                            });

                                            if (!ok) {
                                                return;
                                            }

                                            router.delete(
                                                destroyIntegration.url(
                                                    slack.id,
                                                ),
                                            );
                                        }}
                                    >
                                        {t('Disconnect')}
                                    </Button>
                                )}
                                {!showForm && (
                                    <Button
                                        size="sm"
                                        onClick={() => setShowForm(true)}
                                    >
                                        {slack?.is_configured
                                            ? t('Replace')
                                            : t('Connect')}
                                    </Button>
                                )}
                            </div>
                        </div>

                        {showForm && (
                            <div className="mt-5 rounded-lg border bg-muted/15">
                                <div className="grid gap-5 p-4 sm:p-5 lg:grid-cols-[220px_1fr] lg:gap-8">
                                    <div>
                                        <p className="text-sm font-medium">
                                            {t('Connect webhook')}
                                        </p>
                                        <p className="mt-1 text-xs leading-5 text-muted-foreground">
                                            {t(
                                                'Paste the incoming webhook URL for the Slack channel where new leads should be delivered.',
                                            )}
                                        </p>
                                    </div>

                                    <form
                                        onSubmit={(e) => {
                                            e.preventDefault();
                                            form.post(storeSlack.url(), {
                                                onSuccess: () => {
                                                    form.reset('webhook_url');
                                                    setShowForm(false);
                                                },
                                            });
                                        }}
                                        className="grid gap-4"
                                    >
                                        <div className="grid gap-1.5">
                                            <Label htmlFor="webhook">
                                                {t(
                                                    'Slack Incoming Webhook URL',
                                                )}
                                            </Label>
                                            <Input
                                                id="webhook"
                                                type="url"
                                                placeholder="https://hooks.slack.com/services/T.../B.../..."
                                                value={form.data.webhook_url}
                                                aria-invalid={Boolean(
                                                    form.errors.webhook_url,
                                                )}
                                                onChange={(e) =>
                                                    form.setData(
                                                        'webhook_url',
                                                        e.target.value,
                                                    )
                                                }
                                            />
                                            <p className="text-xs text-muted-foreground">
                                                {t(
                                                    'Create one in Slack: Apps → Incoming Webhooks → New Configuration → pick a channel → copy the URL.',
                                                )}
                                            </p>
                                            <InputError
                                                message={
                                                    form.errors.webhook_url
                                                }
                                            />
                                        </div>

                                        <label
                                            htmlFor="send-test"
                                            className="flex items-start gap-3 rounded-md border bg-background px-3 py-2"
                                        >
                                            <Checkbox
                                                id="send-test"
                                                checked={form.data.send_test}
                                                onCheckedChange={(checked) =>
                                                    form.setData(
                                                        'send_test',
                                                        checked === true,
                                                    )
                                                }
                                            />
                                            <span>
                                                <span className="block text-sm font-medium">
                                                    {t('Send a test message')}
                                                </span>
                                                <span className="mt-0.5 block text-xs text-muted-foreground">
                                                    {t(
                                                        'Useful for confirming the webhook is wired to the right channel before the next real lead arrives.',
                                                    )}
                                                </span>
                                            </span>
                                        </label>

                                        <div className="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                                            <Button
                                                type="button"
                                                variant="ghost"
                                                size="sm"
                                                onClick={() =>
                                                    setShowForm(false)
                                                }
                                                className="w-full sm:w-auto"
                                            >
                                                {t('Cancel')}
                                            </Button>
                                            <Button
                                                type="submit"
                                                size="sm"
                                                disabled={form.processing}
                                                className="w-full sm:w-auto"
                                            >
                                                {form.processing
                                                    ? t('Connecting…')
                                                    : t('Save connection')}
                                            </Button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        )}
                    </Card>
                )}

                {integrationsEnabled.webhooks && (
                    <Card className="p-5">
                        <div className="flex items-start justify-between gap-4">
                            <div className="flex items-start gap-3">
                                <Send className="mt-0.5 size-6 text-amber-600" />
                                <div>
                                    <p className="font-medium">
                                        {t('Outbound webhooks')}
                                    </p>
                                    <p className="mt-0.5 text-sm text-muted-foreground">
                                        {t(
                                            'POST signed lead events into your CRM, workflow engine, or internal automation.',
                                        )}
                                    </p>
                                    <p className="mt-2 text-xs text-muted-foreground">
                                        {t('Every request includes')}{' '}
                                        <span className="font-mono text-[11px]">
                                            X-Pitchbar-Signature:
                                            t=&lt;unix&gt;,v1=&lt;hmac&gt;
                                        </span>{' '}
                                        {t('where the HMAC is SHA-256 over')}{' '}
                                        <span className="font-mono text-[11px]">
                                            {'<timestamp>.<raw body>'}
                                        </span>
                                        .
                                    </p>
                                </div>
                            </div>
                            <div className="flex shrink-0 gap-2">
                                {!showWebhookForm && (
                                    <Button
                                        size="sm"
                                        onClick={startCreatingWebhook}
                                    >
                                        {t('Add endpoint')}
                                    </Button>
                                )}
                            </div>
                        </div>

                        <div className="mt-5 grid gap-3">
                            {webhookSubscriptions.length === 0 ? (
                                <div className="rounded-lg border border-dashed bg-muted/10 px-4 py-5 text-sm text-muted-foreground">
                                    {t('No outbound webhook endpoints yet.')}
                                </div>
                            ) : (
                                webhookSubscriptions.map((subscription) => (
                                    <div
                                        key={subscription.id}
                                        className="rounded-xl border border-border/80 bg-muted/10 p-4"
                                    >
                                        <div className="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                                            <div>
                                                <div className="flex flex-wrap items-center gap-2">
                                                    <p className="font-medium">
                                                        {subscription.host ??
                                                            t(
                                                                'Custom endpoint',
                                                            )}
                                                    </p>
                                                    <span
                                                        className={`inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-medium ${
                                                            subscription.enabled
                                                                ? 'bg-emerald-500/15 text-emerald-700 dark:text-emerald-400'
                                                                : 'bg-amber-500/15 text-amber-700 dark:text-amber-400'
                                                        }`}
                                                    >
                                                        {subscription.enabled
                                                            ? t('Enabled')
                                                            : t('Paused')}
                                                    </span>
                                                </div>
                                                <p className="mt-1 text-xs break-all text-muted-foreground">
                                                    {subscription.url}
                                                </p>
                                                <div className="mt-3 flex flex-wrap items-center gap-2 text-xs text-muted-foreground">
                                                    {subscription.event_labels.map(
                                                        (label) => (
                                                            <span
                                                                key={`${subscription.id}-${label}`}
                                                                className="rounded-full border border-border/80 px-2 py-1"
                                                            >
                                                                {label}
                                                            </span>
                                                        ),
                                                    )}
                                                    <span className="rounded-full border border-border/80 px-2 py-1 font-mono text-[11px]">
                                                        {t('Secret')}{' '}
                                                        {
                                                            subscription.secret_hint
                                                        }
                                                    </span>
                                                </div>
                                            </div>

                                            <div className="flex shrink-0 flex-wrap gap-2">
                                                <Button
                                                    variant="ghost"
                                                    size="sm"
                                                    onClick={() =>
                                                        startEditingWebhook(
                                                            subscription,
                                                        )
                                                    }
                                                >
                                                    {t('Edit')}
                                                </Button>
                                                <Button
                                                    variant="ghost"
                                                    size="sm"
                                                    onClick={async () => {
                                                        const ok =
                                                            await confirm({
                                                                title: t(
                                                                    'Rotate secret?',
                                                                ),
                                                                message: t(
                                                                    'A new signing secret will be generated. Old secret stops working immediately. Update your verifier with the new value.',
                                                                ),
                                                                confirmLabel:
                                                                    t('Rotate'),
                                                                danger: true,
                                                            });

                                                        if (!ok) {
                                                            return;
                                                        }

                                                        router.post(
                                                            `/app/integrations/webhooks/${subscription.id}/rotate-secret`,
                                                            {},
                                                            {
                                                                preserveScroll: true,
                                                            },
                                                        );
                                                    }}
                                                >
                                                    <RefreshCcw className="mr-1 h-3.5 w-3.5" />
                                                    {t('Rotate secret')}
                                                </Button>
                                                <Button
                                                    variant="ghost"
                                                    size="sm"
                                                    onClick={async () => {
                                                        const ok =
                                                            await confirm({
                                                                title: t(
                                                                    'Delete webhook?',
                                                                ),
                                                                message: t(
                                                                    'Delete this webhook endpoint?',
                                                                ),
                                                                confirmLabel:
                                                                    t('Delete'),
                                                                danger: true,
                                                            });

                                                        if (!ok) {
                                                            return;
                                                        }

                                                        router.delete(
                                                            destroyWebhook.url(
                                                                subscription.id,
                                                            ),
                                                        );
                                                    }}
                                                >
                                                    {t('Delete')}
                                                </Button>
                                            </div>
                                        </div>
                                    </div>
                                ))
                            )}
                        </div>

                        {showWebhookForm && (
                            <div className="mt-5 rounded-lg border bg-muted/15">
                                <div className="grid gap-5 p-4 sm:p-5 lg:grid-cols-[220px_1fr] lg:gap-8">
                                    <div>
                                        <p className="text-sm font-medium">
                                            {editingWebhookId === null
                                                ? t('Add endpoint')
                                                : t('Edit endpoint')}
                                        </p>
                                        <p className="mt-1 text-xs leading-5 text-muted-foreground">
                                            {t(
                                                'Configure where signed lead events should land. Rotate the secret any time you need to invalidate an old receiver.',
                                            )}
                                        </p>
                                    </div>

                                    <form
                                        onSubmit={(event) => {
                                            event.preventDefault();

                                            const onSuccess = () => {
                                                webhookForm.reset();
                                                setEditingWebhookId(null);
                                                setShowWebhookForm(false);
                                            };

                                            if (editingWebhookId === null) {
                                                webhookForm.post(
                                                    storeWebhook.url(),
                                                    {
                                                        onSuccess,
                                                    },
                                                );

                                                return;
                                            }

                                            webhookForm.patch(
                                                updateWebhook.url(
                                                    editingWebhookId,
                                                ),
                                                {
                                                    onSuccess,
                                                },
                                            );
                                        }}
                                        className="grid gap-4"
                                    >
                                        <div className="grid gap-1.5">
                                            <Label htmlFor="outbound-webhook-url">
                                                {t('Endpoint URL')}
                                            </Label>
                                            <Input
                                                id="outbound-webhook-url"
                                                type="url"
                                                placeholder="https://example.com/webhooks/pitchbar"
                                                value={webhookForm.data.url}
                                                aria-invalid={Boolean(
                                                    webhookForm.errors.url,
                                                )}
                                                onChange={(event) =>
                                                    webhookForm.setData(
                                                        'url',
                                                        event.target.value,
                                                    )
                                                }
                                            />
                                            <p className="text-xs text-muted-foreground">
                                                {t(
                                                    ':brand sends JSON with a signed HMAC header so your receiver can verify the request body.',
                                                    { brand },
                                                )}
                                            </p>
                                            <InputError
                                                message={webhookForm.errors.url}
                                            />
                                        </div>

                                        <div className="grid gap-1.5">
                                            <Label htmlFor="outbound-webhook-secret">
                                                {editingWebhookId === null
                                                    ? t('Signing secret')
                                                    : t(
                                                          'Rotate signing secret',
                                                      )}
                                            </Label>
                                            <Input
                                                id="outbound-webhook-secret"
                                                type="password"
                                                placeholder={
                                                    editingWebhookId === null
                                                        ? t(
                                                              'Use at least 12 characters',
                                                          )
                                                        : t(
                                                              'Leave blank to keep the current secret',
                                                          )
                                                }
                                                value={webhookForm.data.secret}
                                                aria-invalid={Boolean(
                                                    webhookForm.errors.secret,
                                                )}
                                                onChange={(event) =>
                                                    webhookForm.setData(
                                                        'secret',
                                                        event.target.value,
                                                    )
                                                }
                                            />
                                            <p className="text-xs text-muted-foreground">
                                                {t('Recompute')}{' '}
                                                <span className="font-mono text-[11px]">
                                                    hash_hmac('sha256',
                                                    '&lt;timestamp&gt;.&lt;raw
                                                    body&gt;', secret)
                                                </span>{' '}
                                                {t(
                                                    'on the receiver and compare it to the header value.',
                                                )}
                                            </p>
                                            <InputError
                                                message={
                                                    webhookForm.errors.secret
                                                }
                                            />
                                        </div>

                                        <div className="grid gap-3 rounded-md border bg-background px-3 py-3">
                                            <div>
                                                <p className="text-sm font-medium">
                                                    {t('Events')}
                                                </p>
                                                <p className="mt-0.5 text-xs text-muted-foreground">
                                                    {t(
                                                        'Choose which platform events get pushed to this endpoint.',
                                                    )}
                                                </p>
                                            </div>

                                            {webhookEventOptions.map(
                                                (option) => (
                                                    <label
                                                        key={option.value}
                                                        htmlFor={`event-${option.value}`}
                                                        className="flex items-start gap-3 rounded-md border bg-muted/10 px-3 py-2"
                                                    >
                                                        <Checkbox
                                                            id={`event-${option.value}`}
                                                            checked={webhookForm.data.events.includes(
                                                                option.value,
                                                            )}
                                                            onCheckedChange={(
                                                                checked,
                                                            ) =>
                                                                toggleWebhookEvent(
                                                                    option.value,
                                                                    checked ===
                                                                        true,
                                                                )
                                                            }
                                                        />
                                                        <span className="block text-sm font-medium">
                                                            {option.label}
                                                        </span>
                                                    </label>
                                                ),
                                            )}

                                            <InputError
                                                message={
                                                    webhookForm.errors.events
                                                }
                                            />
                                        </div>

                                        <label
                                            htmlFor="enable-webhook"
                                            className="flex items-start gap-3 rounded-md border bg-background px-3 py-2"
                                        >
                                            <Checkbox
                                                id="enable-webhook"
                                                checked={
                                                    webhookForm.data.enabled
                                                }
                                                onCheckedChange={(checked) =>
                                                    webhookForm.setData(
                                                        'enabled',
                                                        checked === true,
                                                    )
                                                }
                                            />
                                            <span>
                                                <span className="block text-sm font-medium">
                                                    {t('Endpoint enabled')}
                                                </span>
                                                <span className="mt-0.5 block text-xs text-muted-foreground">
                                                    {t(
                                                        'Pause delivery without deleting the endpoint configuration.',
                                                    )}
                                                </span>
                                            </span>
                                        </label>

                                        <div className="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                                            <Button
                                                type="button"
                                                variant="ghost"
                                                size="sm"
                                                onClick={resetWebhookForm}
                                                className="w-full sm:w-auto"
                                            >
                                                {t('Cancel')}
                                            </Button>
                                            <Button
                                                type="submit"
                                                size="sm"
                                                disabled={
                                                    webhookForm.processing
                                                }
                                                className="w-full sm:w-auto"
                                            >
                                                {webhookForm.processing
                                                    ? t('Saving…')
                                                    : editingWebhookId === null
                                                      ? t('Save endpoint')
                                                      : t('Update endpoint')}
                                            </Button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        )}
                    </Card>
                )}

                {integrationsEnabled.wordpress && (
                    <Card className="p-5">
                        <div className="flex items-start gap-3">
                            <Globe className="mt-0.5 size-6 text-blue-600" />
                            <div className="min-w-0 flex-1">
                                <div className="flex flex-wrap items-center gap-2">
                                    <p className="font-medium">
                                        {t('WordPress & WooCommerce')}
                                    </p>
                                    {wordpressConnections.length > 0 && (
                                        <span className="inline-flex items-center gap-1 rounded-full bg-emerald-500/10 px-2 py-0.5 text-xs font-medium text-emerald-600 dark:text-emerald-400">
                                            <CheckCircle2 className="size-3" />
                                            {wordpressConnections.length === 1
                                                ? t('1 site connected')
                                                : t(':n sites connected', {
                                                      n: String(
                                                          wordpressConnections.length,
                                                      ),
                                                  })}
                                        </span>
                                    )}
                                </div>
                                <p className="mt-0.5 text-sm text-muted-foreground">
                                    {t(
                                        'Install the :brand plugin on your WordPress site to embed the chat widget, sync posts and products as agent knowledge, and (with WooCommerce) enable order lookup, coupon apply, and abandoned-cart triggers.',
                                        { brand },
                                    )}
                                </p>

                                {wordpressConnections.length === 0 ? (
                                    <p className="mt-3 text-xs text-muted-foreground">
                                        {t(
                                            'No WordPress sites connected yet. Install the :brand plugin from CodeCanyon (or your :brand admin) on your WP site, paste this workspace URL + an API token, and pick the agent you want to attach.',
                                            { brand },
                                        )}
                                    </p>
                                ) : (
                                    <ul className="mt-3 space-y-2">
                                        {wordpressConnections.map((row) => (
                                            <li
                                                key={row.agent_id}
                                                className="rounded-md border border-border bg-muted/30 px-3 py-2 text-sm"
                                            >
                                                <div className="flex flex-wrap items-center justify-between gap-2">
                                                    <div className="flex min-w-0 items-center gap-2">
                                                        <span className="truncate font-medium">
                                                            {row.agent_name}
                                                        </span>
                                                        {row.woocommerce_active && (
                                                            <span className="inline-flex items-center gap-1 rounded-full bg-purple-500/10 px-1.5 py-0.5 text-[10px] font-semibold tracking-wide text-purple-600 uppercase dark:text-purple-400">
                                                                <ShoppingBag className="size-3" />
                                                                {t(
                                                                    'WooCommerce',
                                                                )}
                                                            </span>
                                                        )}
                                                    </div>
                                                    {row.last_seen_at && (
                                                        <span
                                                            className="shrink-0 text-xs text-muted-foreground"
                                                            title={
                                                                row.last_seen_at
                                                            }
                                                        >
                                                            {t(
                                                                'Last seen :when',
                                                                {
                                                                    when: relativeTime(
                                                                        row.last_seen_at,
                                                                    ),
                                                                },
                                                            )}
                                                        </span>
                                                    )}
                                                </div>
                                                <div className="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-muted-foreground">
                                                    {row.site_url && (
                                                        <a
                                                            href={row.site_url}
                                                            target="_blank"
                                                            rel="noopener noreferrer"
                                                            className="truncate font-mono text-foreground/70 hover:underline"
                                                        >
                                                            {row.site_url.replace(
                                                                /^https?:\/\//,
                                                                '',
                                                            )}
                                                        </a>
                                                    )}
                                                    {row.plugin_version && (
                                                        <span>
                                                            {t('Plugin v:v', {
                                                                v: row.plugin_version,
                                                            })}
                                                        </span>
                                                    )}
                                                    {row.wordpress_version && (
                                                        <span>
                                                            {t('WordPress :v', {
                                                                v: row.wordpress_version,
                                                            })}
                                                        </span>
                                                    )}
                                                </div>
                                            </li>
                                        ))}
                                    </ul>
                                )}

                                <p className="mt-3 text-xs text-muted-foreground">
                                    {wordpressPlugin?.help_text ??
                                        t(
                                            'Need the plugin? Download it from your CodeCanyon receipt, or read the setup guide:',
                                        )}{' '}
                                    {wordpressPlugin?.download_url && (
                                        <>
                                            <a
                                                href={
                                                    wordpressPlugin.download_url
                                                }
                                                target="_blank"
                                                rel="noopener noreferrer"
                                                className="font-medium underline"
                                            >
                                                {t('Download plugin')}
                                            </a>
                                            {' · '}
                                        </>
                                    )}
                                    <a
                                        href="/documentation/wordpress-install"
                                        className="font-medium underline"
                                    >
                                        {t('WordPress install & connect')}
                                    </a>
                                    .
                                </p>
                            </div>
                        </div>
                    </Card>
                )}

                {integrationsEnabled.notion && (
                    <Card className="p-5">
                        <div className="flex items-start justify-between gap-4">
                            <div className="flex items-start gap-3">
                                <BookOpen className="mt-0.5 size-6 text-slate-700 dark:text-slate-300" />
                                <div>
                                    <p className="font-medium">{t('Notion')}</p>
                                    <p className="mt-0.5 text-sm text-muted-foreground">
                                        {t(
                                            'Index pages from your Notion workspace as agent knowledge — no manual export.',
                                        )}
                                    </p>
                                    {notion?.is_configured && (
                                        <p className="mt-2 inline-flex flex-wrap items-center gap-1 text-xs text-emerald-600 dark:text-emerald-400">
                                            <CheckCircle2 className="size-3.5" />{' '}
                                            {t('Connected')}
                                            {notion.webhook_hint && (
                                                <span className="text-muted-foreground">
                                                    · {notion.webhook_hint}
                                                </span>
                                            )}
                                            {notion.summary && (
                                                <span className="text-muted-foreground">
                                                    · {notion.summary}
                                                </span>
                                            )}
                                        </p>
                                    )}
                                </div>
                            </div>
                            <div className="flex shrink-0 gap-2">
                                {notion?.is_configured && (
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        onClick={async () => {
                                            const ok = await confirm({
                                                title: t('Disconnect Notion?'),
                                                message:
                                                    t('Disconnect Notion?'),
                                                confirmLabel: t('Disconnect'),
                                                danger: true,
                                            });

                                            if (!ok) {
                                                return;
                                            }

                                            router.delete(
                                                destroyIntegration.url(
                                                    notion.id,
                                                ),
                                            );
                                        }}
                                    >
                                        {t('Disconnect')}
                                    </Button>
                                )}
                                <Button
                                    size="sm"
                                    onClick={() => {
                                        // OAuth flows MUST use a top-level
                                        // navigation, not an Inertia
                                        // router.visit (which is XHR under
                                        // the hood). The `/app/oauth/notion/
                                        // connect` endpoint returns a 302
                                        // to api.notion.com; following that
                                        // via XHR triggers a CORS preflight
                                        // that Notion (correctly) rejects.
                                        // Buyer report 2026-05-29.
                                        window.location.href =
                                            startNotion.url();
                                    }}
                                >
                                    {notion?.is_configured
                                        ? t('Reconnect')
                                        : t('Connect Notion')}
                                </Button>
                            </div>
                        </div>
                        {notion?.is_configured && (
                            <p className="mt-3 text-xs text-muted-foreground">
                                {t(
                                    "Once connected, paste a Notion page URL on any agent's Sources page to ingest it.",
                                )}
                            </p>
                        )}
                    </Card>
                )}

                {integrationsEnabled.google && (
                    <Card className="p-5">
                        <div className="flex items-start justify-between gap-4">
                            <div className="flex items-start gap-3">
                                <FileText className="mt-0.5 size-6 text-blue-600" />
                                <div>
                                    <p className="font-medium">
                                        {t('Google Drive (Docs)')}
                                    </p>
                                    <p className="mt-0.5 text-sm text-muted-foreground">
                                        {t(
                                            'Index Google Docs as agent knowledge with read-only Drive access.',
                                        )}
                                    </p>
                                    {google?.is_configured && (
                                        <p className="mt-2 inline-flex flex-wrap items-center gap-1 text-xs text-emerald-600 dark:text-emerald-400">
                                            <CheckCircle2 className="size-3.5" />{' '}
                                            {t('Connected')}
                                            {google.summary && (
                                                <span className="text-muted-foreground">
                                                    · {google.summary}
                                                </span>
                                            )}
                                        </p>
                                    )}
                                </div>
                            </div>
                            <div className="flex shrink-0 gap-2">
                                {google?.is_configured && (
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        onClick={async () => {
                                            const ok = await confirm({
                                                title: t('Disconnect Google?'),
                                                message:
                                                    t('Disconnect Google?'),
                                                confirmLabel: t('Disconnect'),
                                                danger: true,
                                            });

                                            if (!ok) {
                                                return;
                                            }

                                            router.delete(
                                                destroyIntegration.url(
                                                    google.id,
                                                ),
                                            );
                                        }}
                                    >
                                        {t('Disconnect')}
                                    </Button>
                                )}
                                <Button
                                    size="sm"
                                    onClick={() => {
                                        // OAuth = top-level navigation, not
                                        // XHR. See the Notion button above
                                        // for the full reasoning. Buyer
                                        // report 2026-05-29.
                                        window.location.href =
                                            startGoogle.url();
                                    }}
                                >
                                    {google?.is_configured
                                        ? t('Reconnect')
                                        : t('Connect Google')}
                                </Button>
                            </div>
                        </div>
                        {google?.is_configured && (
                            <p className="mt-3 text-xs text-muted-foreground">
                                {t(
                                    "Once connected, paste a Google Doc URL on any agent's Sources page to ingest it.",
                                )}
                            </p>
                        )}
                    </Card>
                )}
            </div>

            {showRotatedSecret && (
                <div
                    className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4"
                    onClick={() => setShowRotatedSecret(null)}
                >
                    <div
                        className="w-full max-w-lg rounded-xl border border-border bg-card p-6 shadow-2xl"
                        onClick={(e) => e.stopPropagation()}
                    >
                        <h3 className="text-lg font-semibold">
                            {t('New webhook signing secret')}
                        </h3>
                        <p className="mt-2 text-sm text-muted-foreground">
                            {t(
                                'Copy this value now and paste it into your verifier. It will not be shown again — if you lose it you must rotate again.',
                            )}
                        </p>
                        <div className="mt-4 flex items-center gap-2 rounded-lg border bg-muted/30 p-3 font-mono text-sm">
                            <code className="flex-1 break-all">
                                {showRotatedSecret.secret}
                            </code>
                            <Button
                                size="sm"
                                variant="ghost"
                                onClick={async () => {
                                    try {
                                        await navigator.clipboard.writeText(
                                            showRotatedSecret.secret,
                                        );
                                        setCopied(true);
                                    } catch {
                                        setCopied(false);
                                    }
                                }}
                            >
                                {copied ? (
                                    <>
                                        <CheckCircle2 className="mr-1 h-4 w-4" />
                                        {t('Copied')}
                                    </>
                                ) : (
                                    <>
                                        <Copy className="mr-1 h-4 w-4" />
                                        {t('Copy')}
                                    </>
                                )}
                            </Button>
                        </div>
                        <div className="mt-5 flex justify-end">
                            <Button onClick={() => setShowRotatedSecret(null)}>
                                {t('Done')}
                            </Button>
                        </div>
                    </div>
                </div>
            )}
        </AppLayout>
    );
}
