import { Head, router, useForm } from '@inertiajs/react';
import { BarChart3, Trash2 } from 'lucide-react';
import { useAlert, useConfirm } from '@/components/confirm-dialog-provider';
import { EmptyState } from '@/components/empty-state';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardFooter,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { useT } from '@/lib/i18n';
import { index as agentsIndex, show as showAgent } from '@/routes/agents';
import { index as agentCtasIndex } from '@/routes/agents/ctas';
import { destroy as ctaDestroy } from '@/routes/cta';
import type { BreadcrumbItem } from '@/types';

type Cta = {
    id: string;
    name: string;
    label: string;
    kind: string;
    enabled: boolean;
    target: { url?: string } | null;
};

type Props = {
    agent: { id: string; name: string };
    rules: Cta[];
};

const KINDS = ['buy', 'demo', 'signup', 'book', 'link'] as const;

export default function Ctas({ agent, rules }: Props) {
    const { t } = useT();
    const confirm = useConfirm();
    const alert = useAlert();
    const form = useForm<{
        name: string;
        label: string;
        kind: string;
        url: string;
        forward_context: boolean;
        context_fields: string[];
    }>({
        name: '',
        label: '',
        kind: 'demo',
        url: '',
        forward_context: false,
        context_fields: [],
    });

    const breadcrumbs: BreadcrumbItem[] = [
        { title: t('Agents'), href: agentsIndex.url() },
        { title: agent.name, href: showAgent({ agent: agent.id }).url },
        { title: t('CTAs'), href: agentCtasIndex({ agent: agent.id }).url },
    ];

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        form.transform((data) => ({
            name: data.name,
            label: data.label,
            kind: data.kind,
            target: {
                url: data.url,
                // D2: when forward_context is on, the server appends a
                // signed payload to the outbound URL on every click.
                ...(data.forward_context && data.context_fields.length > 0
                    ? {
                          forward_context: true,
                          context_fields: data.context_fields,
                      }
                    : {}),
            },
            conditions: {},
        }));
        form.post(`/app/agents/${agent.id}/ctas`, {
            onSuccess: () => form.reset(),
        });
    };

    const toggleField = (field: string) => {
        const next = form.data.context_fields.includes(field)
            ? form.data.context_fields.filter((f) => f !== field)
            : [...form.data.context_fields, field];
        form.setData('context_fields', next);
    };

    const FORWARDABLE_FIELDS = [
        'conversation_id',
        'visitor_email',
        'visitor_name',
        'captured_fields',
        'page_url',
        'agent_id',
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`${agent.name} · CTAs`} />
            <div className="flex flex-1 flex-col gap-4 p-4">
                <h1 className="text-2xl font-semibold tracking-tight">
                    {t('Calls to action')}
                </h1>
                <p className="text-sm text-muted-foreground">
                    {t(
                        'Buttons that surface in conversations to drive demos, sign-ups, or purchases.',
                    )}
                </p>

                <Card className="max-w-5xl gap-0 overflow-hidden border-border/80">
                    <CardHeader className="border-b bg-muted/20 px-4 py-4 sm:px-5">
                        <div className="flex items-start gap-3">
                            <span className="flex size-9 shrink-0 items-center justify-center rounded-md border bg-background text-muted-foreground shadow-xs">
                                <BarChart3 className="size-4" />
                            </span>
                            <div className="min-w-0">
                                <CardTitle className="text-sm">
                                    {t('Add a CTA')}
                                </CardTitle>
                                <CardDescription className="mt-1 text-xs leading-5">
                                    {t(
                                        'Create a button the assistant can surface during a conversation when a visitor is ready to act.',
                                    )}
                                </CardDescription>
                            </div>
                        </div>
                    </CardHeader>
                    <form onSubmit={submit}>
                        <CardContent className="grid gap-4 px-4 py-4 sm:px-5 sm:py-5 md:grid-cols-2 xl:grid-cols-4">
                            <div className="grid gap-1.5">
                                <Label htmlFor="name">
                                    {t('Name (internal)')}
                                </Label>
                                <Input
                                    id="name"
                                    placeholder="book_demo_primary"
                                    value={form.data.name}
                                    aria-invalid={Boolean(form.errors.name)}
                                    onChange={(e) =>
                                        form.setData('name', e.target.value)
                                    }
                                />
                                <InputError message={form.errors.name} />
                            </div>
                            <div className="grid gap-1.5">
                                <Label htmlFor="label">
                                    {t('Button label')}
                                </Label>
                                <Input
                                    id="label"
                                    placeholder={t('Book a demo')}
                                    value={form.data.label}
                                    aria-invalid={Boolean(form.errors.label)}
                                    onChange={(e) =>
                                        form.setData('label', e.target.value)
                                    }
                                />
                                <InputError message={form.errors.label} />
                            </div>
                            <div className="grid gap-1.5">
                                <Label htmlFor="kind">{t('Kind')}</Label>
                                <Select
                                    value={form.data.kind}
                                    onValueChange={(value) =>
                                        form.setData('kind', value)
                                    }
                                >
                                    <SelectTrigger id="kind">
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {KINDS.map((kind) => (
                                            <SelectItem key={kind} value={kind}>
                                                {kind}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                <InputError message={form.errors.kind} />
                            </div>
                            <div className="grid gap-1.5">
                                <Label htmlFor="url">{t('Target URL')}</Label>
                                <Input
                                    id="url"
                                    type="url"
                                    placeholder="https://your-site.com/demo"
                                    value={form.data.url}
                                    aria-invalid={Boolean(form.errors.url)}
                                    onChange={(e) =>
                                        form.setData('url', e.target.value)
                                    }
                                />
                                <InputError message={form.errors.url} />
                            </div>

                            {/* D2: signed conversation context. When
                                enabled, every click on this CTA opens
                                the URL with a base64-encoded JSON
                                payload + HMAC signature. The receiving
                                site verifies via the workspace's
                                cta_context_secret. See /documentation/
                                cta-context for HMAC verify samples. */}
                            <div className="grid gap-1.5 md:col-span-2 xl:col-span-4">
                                <Label className="flex items-center gap-2">
                                    <input
                                        type="checkbox"
                                        checked={form.data.forward_context}
                                        onChange={(e) =>
                                            form.setData(
                                                'forward_context',
                                                e.target.checked,
                                            )
                                        }
                                    />
                                    <span>
                                        {t(
                                            'Forward conversation context to the target URL (signed)',
                                        )}
                                    </span>
                                </Label>
                                {form.data.forward_context && (
                                    <div className="flex flex-wrap gap-2 rounded-md border bg-muted/30 p-3 text-xs">
                                        {FORWARDABLE_FIELDS.map((f) => (
                                            <label
                                                key={f}
                                                className="inline-flex items-center gap-1.5"
                                            >
                                                <input
                                                    type="checkbox"
                                                    checked={form.data.context_fields.includes(
                                                        f,
                                                    )}
                                                    onChange={() =>
                                                        toggleField(f)
                                                    }
                                                />
                                                <code>{f}</code>
                                            </label>
                                        ))}
                                    </div>
                                )}
                            </div>
                        </CardContent>
                        <CardFooter className="flex flex-col-reverse gap-2 border-t bg-muted/10 px-4 py-4 sm:flex-row sm:justify-end sm:px-5">
                            <Button
                                type="submit"
                                disabled={form.processing}
                                className="w-full sm:w-auto"
                            >
                                {t('Add CTA')}
                            </Button>
                        </CardFooter>
                    </form>
                </Card>

                <Card className="p-4">
                    <h2 className="mb-3 font-medium">{t('Existing')}</h2>
                    {rules.length === 0 ? (
                        <EmptyState
                            icon={BarChart3}
                            title={t('No CTAs configured yet')}
                            description={t(
                                "CTAs are buttons the bot can surface mid-conversation to push a visitor toward a demo, signup, or specific URL — most useful when you tie them to keywords like 'pricing' or 'enterprise'.",
                            )}
                        />
                    ) : (
                        <div className="divide-y">
                            {rules.map((r) => (
                                <div
                                    key={r.id}
                                    className="flex items-center justify-between py-3"
                                >
                                    <div className="min-w-0 flex-1">
                                        <p className="text-sm font-medium">
                                            {r.label}
                                        </p>
                                        <p className="text-xs text-muted-foreground">
                                            {r.kind} →{' '}
                                            {r.target?.url ?? t('(no target)')}
                                        </p>
                                    </div>
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        onClick={async () => {
                                            const ok = await confirm({
                                                title: t('Delete CTA?'),
                                                message: t('Delete?'),
                                                confirmLabel: t('Delete'),
                                                danger: true,
                                            });

                                            if (!ok) {
                                                return;
                                            }

                                            router.delete(
                                                ctaDestroy.url({
                                                    ctaRule: r.id,
                                                }),
                                                {
                                                    preserveScroll: true,
                                                    onError: (errors) => {
                                                        const message =
                                                            Object.values(
                                                                errors,
                                                            )[0] ??
                                                            t(
                                                                'Could not delete CTA. Try again.',
                                                            );
                                                        void alert({
                                                            title: t(
                                                                'Delete failed',
                                                            ),
                                                            message:
                                                                String(message),
                                                            confirmLabel:
                                                                t('OK'),
                                                        });
                                                    },
                                                    onSuccess: () => {
                                                        router.reload({
                                                            only: ['rules'],
                                                        });
                                                    },
                                                },
                                            );
                                        }}
                                    >
                                        <Trash2 className="size-4" />
                                    </Button>
                                </div>
                            ))}
                        </div>
                    )}
                </Card>
            </div>
        </AppLayout>
    );
}
