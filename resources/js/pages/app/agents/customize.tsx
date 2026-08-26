import { Head, router, useForm } from '@inertiajs/react';
import {
    FormInput,
    Image as ImageIcon,
    LayoutPanelLeft,
    MailCheck,
    MessageSquareQuote,
    Palette,
    ShieldCheck,
    Sparkles,
    Trash2,
    X,
} from 'lucide-react';
import { useRef, useState } from 'react';
import { LeadFormBuilder } from '@/components/agents/lead-form-builder';
import type { LeadFormField } from '@/components/agents/lead-form-builder';
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
import { useBranding } from '@/hooks/use-branding';
import AppLayout from '@/layouts/app-layout';
import { useT } from '@/lib/i18n';
import {
    customize as agentCustomize,
    index as agentsIndex,
    show as showAgent,
    update as updateAgent,
} from '@/routes/agents';
import {
    destroy as launcherIconDestroy,
    store as launcherIconStore,
} from '@/routes/agents/launcher-icon';
import type { BreadcrumbItem } from '@/types';

type WidgetPosition = 'bottom-center' | 'bottom-right' | 'bottom-left';

type Theme = {
    primary?: string;
    accent?: string;
    radius?: number;
    font?: string;
    position?: WidgetPosition;
    launcher_icon_url?: string | null;
    default_open?: boolean;
    launcher_size?: 'sm' | 'md' | 'lg';
    color_scheme?: 'light' | 'dark' | 'auto';
    header_logo_url?: string | null;
    bar_width?: number;
    starter_prompts_position?: 'top' | 'bottom';
};

function usePositionOptions(): ReadonlyArray<{
    value: WidgetPosition;
    title: string;
    blurb: string;
}> {
    const { t } = useT();

    return [
        {
            value: 'bottom-center',
            title: t('Centered bar'),
            blurb: t(
                'Default — the omnibar sits across the bottom-center of the page.',
            ),
        },
        {
            value: 'bottom-right',
            title: t('Bottom right'),
            blurb: t(
                'Floating bubble pinned to the bottom-right corner. Familiar Intercom / Drift / Tawk style.',
            ),
        },
        {
            value: 'bottom-left',
            title: t('Bottom left'),
            blurb: t(
                'Same as right, mirrored. Good for sites whose right edge is busy with other widgets.',
            ),
        },
    ];
}

type Persona = {
    name?: string;
    tone?: 'friendly' | 'expert' | 'concise';
};

type Guardrails = {
    avoid?: string[];
    max_chars?: number;
};

type Agent = {
    id: string;
    name: string;
    persona: Persona | null;
    theme: Theme | null;
    guardrails: Guardrails | null;
    starter_prompts: string[] | null;
    require_lead_before_chat?: boolean | null;
    lead_form_fields?: LeadFormField[] | null;
    lead_prompt_strategy?:
        | 'engagement'
        | 'first_turn'
        | 'keyword_only'
        | 'never'
        | null;
};

type Props = { agent: Agent };

const MAX_PROMPTS = 6;
const MAX_PROMPT_LENGTH = 80;

function CustomizeSection({
    icon,
    title,
    description,
    children,
}: {
    icon: React.ReactNode;
    title: string;
    description: string;
    children: React.ReactNode;
}) {
    return (
        <section className="grid gap-5 border-b px-4 py-4 last:border-b-0 sm:px-5 sm:py-5 lg:grid-cols-[220px_1fr] lg:gap-8">
            <div className="flex gap-3 lg:block">
                <span className="flex size-9 shrink-0 items-center justify-center rounded-md border bg-background text-muted-foreground shadow-xs lg:mb-3">
                    {icon}
                </span>
                <div className="min-w-0">
                    <h2 className="text-sm font-medium text-foreground">
                        {title}
                    </h2>
                    <p className="mt-1 max-w-sm text-xs leading-5 text-muted-foreground">
                        {description}
                    </p>
                </div>
            </div>
            <div className="grid gap-4">{children}</div>
        </section>
    );
}

export default function Customize({ agent }: Props) {
    const { t } = useT();
    const branding = useBranding();
    const brand = branding.site_title || 'Pitchbar';
    const POSITION_OPTIONS = usePositionOptions();
    const breadcrumbs: BreadcrumbItem[] = [
        { title: t('Agents'), href: agentsIndex.url() },
        { title: agent.name, href: showAgent({ agent: agent.id }).url },
        {
            title: t('Customize'),
            href: agentCustomize({ agent: agent.id }).url,
        },
    ];

    const form = useForm<{
        persona: Persona;
        theme: Theme;
        guardrails: Guardrails;
        starter_prompts: string[];
        require_lead_before_chat: boolean;
        lead_form_fields: LeadFormField[] | null;
        lead_prompt_strategy:
            | 'engagement'
            | 'first_turn'
            | 'keyword_only'
            | 'never';
    }>({
        persona: agent.persona ?? {
            name: agent.name,
            tone: 'friendly' as const,
        },
        theme: agent.theme ?? {
            primary: '#111827',
            accent: '#10b981',
            radius: 12,
            position: 'bottom-center',
            default_open: true,
        },
        guardrails: agent.guardrails ?? { avoid: [], max_chars: 2500 },
        starter_prompts: agent.starter_prompts ?? [],
        require_lead_before_chat: agent.require_lead_before_chat ?? false,
        lead_form_fields: agent.lead_form_fields ?? null,
        lead_prompt_strategy:
            agent.lead_prompt_strategy ?? ('engagement' as const),
    });

    const [pendingPrompt, setPendingPrompt] = useState('');
    const [iconUploading, setIconUploading] = useState(false);
    const [iconError, setIconError] = useState<string | null>(null);
    const iconInputRef = useRef<HTMLInputElement | null>(null);
    const launcherIconUrl = form.data.theme.launcher_icon_url ?? null;

    const pickIcon = (e: React.ChangeEvent<HTMLInputElement>) => {
        const file = e.target.files?.[0];

        if (!file) {
            return;
        }

        setIconUploading(true);
        setIconError(null);

        const fd = new FormData();
        fd.append('icon', file);

        router.post(launcherIconStore.url({ agent: agent.id }), fd, {
            forceFormData: true,
            preserveScroll: true,
            preserveState: true,
            onSuccess: (page) => {
                const next = (page.props as { agent?: Agent }).agent?.theme
                    ?.launcher_icon_url;

                if (next) {
                    form.setData('theme', {
                        ...form.data.theme,
                        launcher_icon_url: next,
                    });
                }
            },
            onError: (errors) => {
                const first =
                    (errors as Record<string, string>).icon ??
                    t('Upload failed. Try a smaller image.');
                setIconError(first);
            },
            onFinish: () => {
                setIconUploading(false);

                if (iconInputRef.current) {
                    iconInputRef.current.value = '';
                }
            },
        });
    };

    const removeIcon = () => {
        setIconError(null);
        router.delete(launcherIconDestroy.url({ agent: agent.id }), {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                form.setData('theme', {
                    ...form.data.theme,
                    launcher_icon_url: null,
                });
            },
        });
    };

    const addPrompt = () => {
        const value = pendingPrompt.trim();

        if (
            !value ||
            form.data.starter_prompts.length >= MAX_PROMPTS ||
            form.data.starter_prompts.includes(value)
        ) {
            return;
        }

        form.setData('starter_prompts', [
            ...form.data.starter_prompts,
            value.slice(0, MAX_PROMPT_LENGTH),
        ]);
        setPendingPrompt('');
    };

    const removePrompt = (index: number) => {
        form.setData(
            'starter_prompts',
            form.data.starter_prompts.filter((_, i) => i !== index),
        );
    };

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        form.patch(updateAgent.url({ agent: agent.id }), {
            preserveScroll: true,
        });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`${agent.name} · customize`} />
            <div className="grid flex-1 grid-cols-1 gap-4 p-4 lg:grid-cols-[minmax(640px,1fr)_minmax(360px,440px)]">
                <Card className="gap-0 overflow-hidden border-border/80">
                    <CardHeader className="border-b bg-muted/20 px-4 py-4 sm:px-5">
                        <CardTitle className="text-base">
                            {t('Customize widget voice and look')}
                        </CardTitle>
                        <CardDescription>
                            {t(
                                'Tune how the agent presents itself before visitors ever send the first message.',
                            )}
                        </CardDescription>
                    </CardHeader>
                    <form onSubmit={submit}>
                        <CustomizeSection
                            icon={<MessageSquareQuote className="size-4" />}
                            title={t('Persona')}
                            description={t(
                                'Choose the name shown in the widget so the conversation feels branded and deliberate.',
                            )}
                        >
                            <div className="grid gap-1.5 sm:max-w-sm">
                                <Label htmlFor="persona-name">
                                    {t('Display name')}
                                </Label>
                                <Input
                                    id="persona-name"
                                    placeholder={t(':brand Assistant', {
                                        brand,
                                    })}
                                    value={
                                        (form.data.persona as Persona).name ??
                                        ''
                                    }
                                    onChange={(e) =>
                                        form.setData('persona', {
                                            ...form.data.persona,
                                            name: e.target.value,
                                        })
                                    }
                                />
                            </div>
                        </CustomizeSection>

                        <CustomizeSection
                            icon={<Palette className="size-4" />}
                            title={t('Theme')}
                            description={t(
                                'Adjust the primary and accent colors used by the widget shell and call-to-action surfaces.',
                            )}
                        >
                            <div className="grid gap-4 sm:grid-cols-2">
                                <div className="grid gap-1.5">
                                    <Label htmlFor="primary-color">
                                        {t('Primary color')}
                                    </Label>
                                    <Input
                                        id="primary-color"
                                        type="color"
                                        value={
                                            form.data.theme.primary ?? '#111827'
                                        }
                                        onChange={(e) =>
                                            form.setData('theme', {
                                                ...form.data.theme,
                                                primary: e.target.value,
                                            })
                                        }
                                    />
                                </div>
                                <div className="grid gap-1.5">
                                    <Label htmlFor="accent-color">
                                        {t('Accent')}
                                    </Label>
                                    <Input
                                        id="accent-color"
                                        type="color"
                                        value={
                                            form.data.theme.accent ?? '#10b981'
                                        }
                                        onChange={(e) =>
                                            form.setData('theme', {
                                                ...form.data.theme,
                                                accent: e.target.value,
                                            })
                                        }
                                    />
                                </div>
                            </div>
                            <div className="flex flex-wrap gap-2">
                                <span className="text-xs text-muted-foreground">
                                    {t('Preview swatches:')}
                                </span>
                                <span className="inline-flex items-center gap-2 rounded-full border bg-background px-2.5 py-1 text-xs text-muted-foreground">
                                    <span
                                        className="size-2.5 rounded-full"
                                        style={{
                                            background:
                                                form.data.theme.primary ??
                                                '#111827',
                                        }}
                                    />
                                    {t('Primary')}
                                </span>
                                <span className="inline-flex items-center gap-2 rounded-full border bg-background px-2.5 py-1 text-xs text-muted-foreground">
                                    <span
                                        className="size-2.5 rounded-full"
                                        style={{
                                            background:
                                                form.data.theme.accent ??
                                                '#10b981',
                                        }}
                                    />
                                    {t('Accent')}
                                </span>
                            </div>
                        </CustomizeSection>

                        <CustomizeSection
                            icon={<ImageIcon className="size-4" />}
                            title={t('Launcher')}
                            description={t(
                                'Customize the launcher pill visitors first see — its icon, and whether the chat panel auto-opens on page load.',
                            )}
                        >
                            <div className="grid gap-2">
                                <Label>{t('Custom icon')}</Label>
                                <p className="text-xs leading-5 text-muted-foreground">
                                    {t(
                                        'Square PNG, JPG, WEBP, or SVG up to 256KB. Replaces the default purple orb.',
                                    )}
                                </p>
                                <div className="flex flex-wrap items-center gap-3">
                                    {launcherIconUrl ? (
                                        <img
                                            src={launcherIconUrl}
                                            alt=""
                                            className="size-12 rounded-full border border-border bg-white object-cover shadow-xs"
                                        />
                                    ) : (
                                        <div
                                            aria-hidden="true"
                                            className="size-12 rounded-full shadow-xs"
                                            style={{
                                                background:
                                                    'radial-gradient(circle at 30% 30%, #a78bfa 0%, #6366f1 35%, #1e1b4b 90%)',
                                            }}
                                        />
                                    )}
                                    <input
                                        ref={iconInputRef}
                                        type="file"
                                        accept="image/png,image/jpeg,image/webp,image/svg+xml"
                                        className="hidden"
                                        onChange={pickIcon}
                                    />
                                    <Button
                                        type="button"
                                        variant="outline"
                                        disabled={iconUploading}
                                        onClick={() =>
                                            iconInputRef.current?.click()
                                        }
                                    >
                                        {iconUploading
                                            ? t('Uploading…')
                                            : launcherIconUrl
                                              ? t('Replace icon')
                                              : t('Upload icon')}
                                    </Button>
                                    {launcherIconUrl && (
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            onClick={removeIcon}
                                            disabled={iconUploading}
                                        >
                                            <Trash2 className="size-4" />
                                            <span className="ms-1">
                                                {t('Remove')}
                                            </span>
                                        </Button>
                                    )}
                                </div>
                                {iconError && (
                                    <p className="text-xs text-destructive">
                                        {iconError}
                                    </p>
                                )}
                            </div>

                            <label className="flex cursor-pointer items-start gap-3 rounded-md border bg-background p-3">
                                <input
                                    type="checkbox"
                                    checked={
                                        form.data.theme.default_open !== false
                                    }
                                    onChange={(e) =>
                                        form.setData('theme', {
                                            ...form.data.theme,
                                            default_open: (
                                                e.target as HTMLInputElement
                                            ).checked,
                                        })
                                    }
                                    className="mt-0.5 size-4 shrink-0 rounded border-border text-foreground focus:ring-2 focus:ring-ring/40"
                                />
                                <div className="min-w-0">
                                    <p className="text-sm font-medium text-foreground">
                                        {t('Auto-open on page load')}
                                    </p>
                                    <p className="mt-1 text-xs leading-5 text-muted-foreground">
                                        {t(
                                            'When on, the chat panel is open by default the first time a visitor lands. Once a visitor closes the bar manually, that choice persists across reloads regardless of this setting.',
                                        )}
                                    </p>
                                </div>
                            </label>

                            <div className="grid gap-2">
                                <Label>{t('Launcher size')}</Label>
                                <div
                                    role="radiogroup"
                                    aria-label={t('Launcher size')}
                                    className="grid grid-cols-3 gap-2"
                                >
                                    {(['sm', 'md', 'lg'] as const).map((sz) => {
                                        const checked =
                                            (form.data.theme.launcher_size ??
                                                'md') === sz;

                                        return (
                                            <label
                                                key={sz}
                                                className={`flex cursor-pointer items-center justify-center rounded-md border px-3 py-2 text-xs transition-colors ${
                                                    checked
                                                        ? 'border-foreground bg-muted/30 ring-1 ring-foreground/20'
                                                        : 'border-border hover:border-foreground/40'
                                                }`}
                                            >
                                                <input
                                                    type="radio"
                                                    name="launcher_size"
                                                    value={sz}
                                                    checked={checked}
                                                    onChange={() =>
                                                        form.setData('theme', {
                                                            ...form.data.theme,
                                                            launcher_size: sz,
                                                        })
                                                    }
                                                    className="sr-only"
                                                />
                                                {sz === 'sm'
                                                    ? t('Small')
                                                    : sz === 'lg'
                                                      ? t('Large')
                                                      : t('Medium')}
                                            </label>
                                        );
                                    })}
                                </div>
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="bar-width">
                                    {t('Bar width')}
                                </Label>
                                <div className="flex items-center gap-3">
                                    <input
                                        id="bar-width"
                                        type="range"
                                        min={320}
                                        max={800}
                                        step={20}
                                        value={form.data.theme.bar_width ?? 560}
                                        onChange={(e) =>
                                            form.setData('theme', {
                                                ...form.data.theme,
                                                bar_width: parseInt(
                                                    e.target.value,
                                                    10,
                                                ),
                                            })
                                        }
                                        className="flex-1"
                                    />
                                    <span className="w-16 text-end font-mono text-xs text-muted-foreground tabular-nums">
                                        {form.data.theme.bar_width ?? 560}
                                        px
                                    </span>
                                </div>
                                <p className="text-xs leading-5 text-muted-foreground">
                                    {t(
                                        'Maximum bar width (320–800). Smaller bars feel less intrusive on landing pages; the widget auto-shrinks below this on narrow screens.',
                                    )}
                                </p>
                            </div>

                            <div className="grid gap-2">
                                <Label>{t('Colour scheme')}</Label>
                                <div
                                    role="radiogroup"
                                    aria-label={t('Colour scheme')}
                                    className="grid grid-cols-3 gap-2"
                                >
                                    {(['light', 'dark', 'auto'] as const).map(
                                        (mode) => {
                                            const checked =
                                                (form.data.theme.color_scheme ??
                                                    'light') === mode;

                                            return (
                                                <label
                                                    key={mode}
                                                    className={`flex cursor-pointer items-center justify-center rounded-md border px-3 py-2 text-xs transition-colors ${
                                                        checked
                                                            ? 'border-foreground bg-muted/30 ring-1 ring-foreground/20'
                                                            : 'border-border hover:border-foreground/40'
                                                    }`}
                                                >
                                                    <input
                                                        type="radio"
                                                        name="color_scheme"
                                                        value={mode}
                                                        checked={checked}
                                                        onChange={() =>
                                                            form.setData(
                                                                'theme',
                                                                {
                                                                    ...form.data
                                                                        .theme,
                                                                    color_scheme:
                                                                        mode,
                                                                },
                                                            )
                                                        }
                                                        className="sr-only"
                                                    />
                                                    {mode === 'light'
                                                        ? t('Light')
                                                        : mode === 'dark'
                                                          ? t('Dark')
                                                          : t('Auto')}
                                                </label>
                                            );
                                        },
                                    )}
                                </div>
                                <p className="text-xs leading-5 text-muted-foreground">
                                    {t(
                                        'Auto follows the visitor’s OS preference (prefers-color-scheme).',
                                    )}
                                </p>
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="header-logo-url">
                                    {t('Header logo URL')}
                                </Label>
                                <Input
                                    id="header-logo-url"
                                    type="url"
                                    placeholder={t(
                                        'https://yourdomain.com/logo.svg',
                                    )}
                                    value={
                                        form.data.theme.header_logo_url ?? ''
                                    }
                                    onChange={(e) =>
                                        form.setData('theme', {
                                            ...form.data.theme,
                                            header_logo_url:
                                                e.target.value === ''
                                                    ? null
                                                    : e.target.value,
                                        })
                                    }
                                />
                                <p className="text-xs leading-5 text-muted-foreground">
                                    {t(
                                        'Optional small logo shown next to the persona name in the chat panel header.',
                                    )}
                                </p>
                            </div>
                        </CustomizeSection>

                        <CustomizeSection
                            icon={<LayoutPanelLeft className="size-4" />}
                            title={t('Position')}
                            description={t(
                                'Where the widget sits on the page. Centered bar is the default; corner bubbles match the Intercom / Drift / Tawk pattern.',
                            )}
                        >
                            <div
                                role="radiogroup"
                                aria-label={t('Widget position')}
                                className="grid auto-rows-fr gap-2 sm:grid-cols-3"
                            >
                                {POSITION_OPTIONS.map((option) => {
                                    const checked =
                                        (form.data.theme.position ??
                                            'bottom-center') === option.value;

                                    return (
                                        <label
                                            key={option.value}
                                            className={`flex h-full cursor-pointer flex-col gap-1 rounded-md border bg-background p-3 text-start transition-colors hover:border-foreground/40 ${
                                                checked
                                                    ? 'border-foreground bg-muted/30 ring-1 ring-foreground/20'
                                                    : 'border-border'
                                            }`}
                                        >
                                            <input
                                                type="radio"
                                                name="widget-position"
                                                value={option.value}
                                                checked={checked}
                                                onChange={() =>
                                                    form.setData('theme', {
                                                        ...form.data.theme,
                                                        position: option.value,
                                                    })
                                                }
                                                className="sr-only"
                                            />
                                            <span className="text-sm font-medium text-foreground">
                                                {option.title}
                                            </span>
                                            <span className="text-xs leading-5 text-muted-foreground">
                                                {option.blurb}
                                            </span>
                                        </label>
                                    );
                                })}
                            </div>
                        </CustomizeSection>

                        <CustomizeSection
                            icon={<MailCheck className="size-4" />}
                            title={t('Pre-chat lead capture')}
                            description={t(
                                'Optional gate that asks for a name + email before the chat surface unlocks. Higher capture rate; the visitor is still motivated to identify themselves.',
                            )}
                        >
                            <label className="flex cursor-pointer items-start gap-3 rounded-md border bg-background p-3">
                                <input
                                    type="checkbox"
                                    checked={form.data.require_lead_before_chat}
                                    onChange={(e) =>
                                        form.setData(
                                            'require_lead_before_chat',
                                            (e.target as HTMLInputElement)
                                                .checked,
                                        )
                                    }
                                    className="mt-0.5 size-4 shrink-0 rounded border-border text-foreground focus:ring-2 focus:ring-ring/40"
                                />
                                <div className="min-w-0">
                                    <p className="text-sm font-medium text-foreground">
                                        {t('Require name + email before chat')}
                                    </p>
                                    <p className="mt-1 text-xs leading-5 text-muted-foreground">
                                        {t(
                                            "When on, visitors see a Name + Email form before the chat panel opens. Once submitted, the chat unlocks on the same panel — no reload. The same visitor doesn't see the form again on refresh.",
                                        )}
                                    </p>
                                </div>
                            </label>
                        </CustomizeSection>

                        <CustomizeSection
                            icon={<FormInput className="size-4" />}
                            title={t('Lead form fields')}
                            description={t(
                                'Choose which fields the lead form asks for. The same schema renders both in the inline mid-chat form and the pre-chat gate.',
                            )}
                        >
                            <LeadFormBuilder
                                value={form.data.lead_form_fields}
                                onChange={(next) =>
                                    form.setData('lead_form_fields', next)
                                }
                            />

                            <div className="mt-5 grid gap-2 rounded-md border bg-background p-3">
                                <p className="text-sm font-medium text-foreground">
                                    {t('When should the form appear?')}
                                </p>
                                <p className="text-xs leading-5 text-muted-foreground">
                                    {t(
                                        'Pre-chat gate (above) shows the form before chat unlocks. This setting controls the in-chat (mid-conversation) prompt instead.',
                                    )}
                                </p>
                                {(
                                    [
                                        {
                                            value: 'engagement',
                                            title: t(
                                                'After engagement (recommended)',
                                            ),
                                            blurb: t(
                                                'Surface on a high-intent keyword (pricing, demo, contact, etc.) OR after the visitor has sent 3 messages, whichever comes first.',
                                            ),
                                        },
                                        {
                                            value: 'first_turn',
                                            title: t(
                                                'On the visitor’s first message',
                                            ),
                                            blurb: t(
                                                'Prompt every visitor immediately on their first turn. Best for sales-led agents where every conversation is a lead.',
                                            ),
                                        },
                                        {
                                            value: 'keyword_only',
                                            title: t('Only on intent keywords'),
                                            blurb: t(
                                                'Wait for the visitor to ask about pricing, a demo, contact, etc. Skip the 3-turn engagement fallback.',
                                            ),
                                        },
                                        {
                                            value: 'never',
                                            title: t('Never auto-prompt'),
                                            blurb: t(
                                                'Disable the in-chat form. You handle lead capture another way (CTA card, escalation, etc.).',
                                            ),
                                        },
                                    ] as const
                                ).map((opt) => {
                                    const checked =
                                        form.data.lead_prompt_strategy ===
                                        opt.value;

                                    return (
                                        <label
                                            key={opt.value}
                                            className={`flex cursor-pointer gap-3 rounded-md border p-2.5 transition-colors ${
                                                checked
                                                    ? 'border-foreground/40 bg-muted/40'
                                                    : 'border-border'
                                            }`}
                                        >
                                            <input
                                                type="radio"
                                                name="lead_prompt_strategy"
                                                value={opt.value}
                                                checked={checked}
                                                onChange={() =>
                                                    form.setData(
                                                        'lead_prompt_strategy',
                                                        opt.value,
                                                    )
                                                }
                                                className="mt-0.5 size-4 shrink-0 text-foreground focus:ring-2 focus:ring-ring/40"
                                            />
                                            <div className="min-w-0">
                                                <p className="text-sm font-medium text-foreground">
                                                    {opt.title}
                                                </p>
                                                <p className="mt-0.5 text-xs leading-5 text-muted-foreground">
                                                    {opt.blurb}
                                                </p>
                                            </div>
                                        </label>
                                    );
                                })}
                            </div>
                        </CustomizeSection>

                        <CustomizeSection
                            icon={<ShieldCheck className="size-4" />}
                            title={t('Guardrails')}
                            description={t(
                                'Control how long replies can be so the assistant stays concise and readable inside the widget.',
                            )}
                        >
                            <div className="grid gap-1.5 sm:max-w-xs">
                                <Label htmlFor="max-response-length">
                                    {t('Max response length')}
                                </Label>
                                <Input
                                    id="max-response-length"
                                    type="number"
                                    value={
                                        (form.data.guardrails as Guardrails)
                                            .max_chars ?? 2500
                                    }
                                    onChange={(e) =>
                                        form.setData('guardrails', {
                                            ...form.data.guardrails,
                                            max_chars: parseInt(
                                                e.target.value,
                                                10,
                                            ),
                                        })
                                    }
                                />
                            </div>
                        </CustomizeSection>

                        <CustomizeSection
                            icon={<Sparkles className="size-4" />}
                            title={t('Starter prompts')}
                            description={t(
                                'Up to :max short questions shown before the conversation starts. Visitors can tap one to send it instantly.',
                                { max: MAX_PROMPTS },
                            )}
                        >
                            <div className="flex flex-wrap gap-2">
                                {form.data.starter_prompts.map(
                                    (prompt, index) => (
                                        <span
                                            key={`${prompt}-${index}`}
                                            className="inline-flex items-center gap-1 rounded-full border bg-muted/50 px-3 py-1 text-xs"
                                        >
                                            {prompt}
                                            <button
                                                type="button"
                                                onClick={() =>
                                                    removePrompt(index)
                                                }
                                                aria-label={t(
                                                    'Remove ":prompt"',
                                                    { prompt },
                                                )}
                                                className="-me-1 rounded-full p-0.5 text-muted-foreground hover:text-foreground"
                                            >
                                                <X className="size-3" />
                                            </button>
                                        </span>
                                    ),
                                )}
                                {form.data.starter_prompts.length === 0 && (
                                    <p className="text-xs text-muted-foreground">
                                        {t('No starter prompts yet.')}
                                    </p>
                                )}
                            </div>
                            <div className="flex flex-col gap-2 sm:flex-row">
                                <Input
                                    placeholder={t("e.g. What's your pricing?")}
                                    value={pendingPrompt}
                                    maxLength={MAX_PROMPT_LENGTH}
                                    disabled={
                                        form.data.starter_prompts.length >=
                                        MAX_PROMPTS
                                    }
                                    onChange={(e) =>
                                        setPendingPrompt(e.target.value)
                                    }
                                    onKeyDown={(e) => {
                                        if (e.key === 'Enter') {
                                            e.preventDefault();
                                            addPrompt();
                                        }
                                    }}
                                />
                                <Button
                                    type="button"
                                    variant="outline"
                                    disabled={
                                        !pendingPrompt.trim() ||
                                        form.data.starter_prompts.length >=
                                            MAX_PROMPTS
                                    }
                                    onClick={addPrompt}
                                    className="w-full sm:w-auto"
                                >
                                    {t('Add prompt')}
                                </Button>
                            </div>
                            <p className="text-xs text-muted-foreground tabular-nums">
                                {t(
                                    ':used/:max prompts used · max :length chars each',
                                    {
                                        used: form.data.starter_prompts.length,
                                        max: MAX_PROMPTS,
                                        length: MAX_PROMPT_LENGTH,
                                    },
                                )}
                            </p>

                            <div className="mt-3 grid gap-2 rounded-md border bg-background p-3">
                                <Label>{t('Chip placement')}</Label>
                                <div
                                    role="radiogroup"
                                    aria-label={t('Chip placement')}
                                    className="grid grid-cols-2 gap-2"
                                >
                                    {(['top', 'bottom'] as const).map((pos) => {
                                        const checked =
                                            (form.data.theme
                                                .starter_prompts_position ??
                                                'top') === pos;

                                        return (
                                            <label
                                                key={pos}
                                                className={`flex cursor-pointer items-center justify-center rounded-md border px-3 py-2 text-xs transition-colors ${
                                                    checked
                                                        ? 'border-foreground bg-muted/30 ring-1 ring-foreground/20'
                                                        : 'border-border hover:border-foreground/40'
                                                }`}
                                            >
                                                <input
                                                    type="radio"
                                                    name="starter_prompts_position"
                                                    value={pos}
                                                    checked={checked}
                                                    onChange={() =>
                                                        form.setData('theme', {
                                                            ...form.data.theme,
                                                            starter_prompts_position:
                                                                pos,
                                                        })
                                                    }
                                                    className="sr-only"
                                                />
                                                {pos === 'top'
                                                    ? t('Above bar')
                                                    : t('Below bar')}
                                            </label>
                                        );
                                    })}
                                </div>
                                <p className="text-xs leading-5 text-muted-foreground">
                                    {t(
                                        'Where the suggestion chips render relative to the input bar. Above is the recommended default — easier to scan before the visitor starts typing.',
                                    )}
                                </p>
                            </div>
                        </CustomizeSection>

                        <CardFooter className="flex flex-col-reverse gap-2 border-t bg-muted/10 px-4 py-4 sm:flex-row sm:justify-end sm:px-5">
                            <Button
                                type="submit"
                                disabled={form.processing}
                                className="w-full sm:w-auto"
                            >
                                {t('Save customization')}
                            </Button>
                        </CardFooter>
                    </form>
                </Card>

                <Card className="gap-0 self-start overflow-hidden lg:sticky lg:top-4">
                    <CardHeader className="border-b bg-muted/20 px-4 py-4 sm:px-5">
                        <CardTitle className="text-base">
                            {t('Live preview')}
                        </CardTitle>
                        <CardDescription>
                            {t(
                                'A quick approximation of how the widget will feel to a visitor.',
                            )}
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="px-4 py-5 sm:px-5">
                        <div
                            className="rounded-[24px] border bg-white p-4 shadow-sm"
                            style={{
                                borderRadius: form.data.theme.radius ?? 12,
                            }}
                        >
                            <div className="flex items-center justify-between gap-3">
                                <div>
                                    <p className="text-sm font-medium text-slate-900">
                                        {(form.data.persona as Persona).name ??
                                            agent.name}
                                    </p>
                                    <p className="text-xs text-slate-500">
                                        {t('Ready to help')}
                                    </p>
                                </div>
                                <span
                                    className="size-3 rounded-full"
                                    style={{
                                        background:
                                            form.data.theme.accent ?? '#10b981',
                                    }}
                                />
                            </div>

                            <div className="mt-4 space-y-3">
                                <div className="max-w-[85%] rounded-2xl rounded-es-md bg-slate-100 px-3 py-2 text-sm text-slate-700">
                                    {t(
                                        'Hi! I can help with pricing, plans, or the best next step.',
                                    )}
                                </div>
                                <div
                                    className="ms-auto inline-flex max-w-[85%] rounded-2xl rounded-ee-md px-3 py-2 text-sm text-white"
                                    style={{
                                        background:
                                            form.data.theme.primary ??
                                            '#111827',
                                    }}
                                >
                                    {t('Ask anything')}
                                </div>
                            </div>

                            {form.data.starter_prompts.length > 0 && (
                                <div className="mt-4 flex flex-wrap gap-2">
                                    {form.data.starter_prompts
                                        .slice(0, 3)
                                        .map((prompt, index) => (
                                            <span
                                                key={`${prompt}-${index}`}
                                                className="rounded-full border border-slate-200 px-3 py-1 text-xs text-slate-600"
                                            >
                                                {prompt}
                                            </span>
                                        ))}
                                </div>
                            )}
                        </div>
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
