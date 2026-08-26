import { Head, router, useForm } from '@inertiajs/react';
import {
    Loader2,
    MessageSquareQuote,
    Palette,
    Sparkles,
    X,
} from 'lucide-react';
import { useMemo, useState } from 'react';
import { useConfirm } from '@/components/confirm-dialog-provider';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useT } from '@/lib/i18n';

type Theme = {
    primary?: string;
    accent?: string;
    radius?: number;
    launcher_label?: string;
};

type Persona = {
    name?: string;
    tone?: 'friendly' | 'expert' | 'concise';
};

type Guardrails = {
    avoid?: string[];
    max_chars?: number;
};

type Defaults = {
    theme: Theme;
    persona: Persona;
    guardrails: Guardrails;
    starter_prompts: string[];
};

type Props = {
    scope: 'workspace' | 'platform';
    effective: Defaults;
    workspace_overrides: Partial<Defaults>;
    platform_defaults: Defaults;
    agents_count: number;
    endpoints: {
        update: string;
        apply_to_all: string | null;
    };
};

const MAX_PROMPTS = 6;
const MAX_PROMPT_LENGTH = 80;

function useToneOptions(): Array<{
    value: 'friendly' | 'expert' | 'concise';
    label: string;
    sample: string;
}> {
    const { t } = useT();

    return [
        {
            value: 'friendly',
            label: t('Friendly'),
            sample: t('Sure thing — happy to help! Here\u2019s what I found.'),
        },
        {
            value: 'expert',
            label: t('Expert'),
            sample: t(
                'The pricing page lists three tiers; the differences are below.',
            ),
        },
        {
            value: 'concise',
            label: t('Concise'),
            sample: t(
                'Pricing: $29 / $99 / custom. Pro adds priority support.',
            ),
        },
    ];
}

function Section({
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
        <section className="border-b last:border-b-0">
            <div className="flex items-start gap-3 px-5 pt-5">
                <span className="flex size-8 shrink-0 items-center justify-center rounded-md border bg-background text-muted-foreground shadow-xs">
                    {icon}
                </span>
                <div className="min-w-0 flex-1">
                    <h2 className="text-sm font-medium text-foreground">
                        {title}
                    </h2>
                    <p className="mt-0.5 text-xs leading-5 text-muted-foreground">
                        {description}
                    </p>
                </div>
            </div>
            <div className="px-5 pt-4 pb-5">{children}</div>
        </section>
    );
}

/**
 * Compact swatch + hex pair. Color picker is the visual primary; the
 * hex input is below for paste-in. Stacks cleanly even at 220px wide.
 */
function ColorField({
    id,
    label,
    value,
    placeholder,
    onChange,
}: {
    id: string;
    label: string;
    value: string;
    placeholder: string;
    onChange: (v: string) => void;
}) {
    return (
        <div className="grid gap-1.5">
            <Label htmlFor={id}>{label}</Label>
            <div className="flex items-center gap-2">
                <input
                    id={id}
                    type="color"
                    value={value || placeholder}
                    onChange={(e) => onChange(e.target.value)}
                    className="h-9 w-12 shrink-0 cursor-pointer rounded-md border bg-background p-1"
                />
                <Input
                    value={value}
                    onChange={(e) => onChange(e.target.value)}
                    placeholder={placeholder}
                    className="flex-1 font-mono text-xs"
                />
            </div>
        </div>
    );
}

/**
 * Live preview that mirrors the actual visitor widget — same white
 * capsule pill at the bottom, colored "orb" circle on the left, input
 * placeholder showing the launcher_label, accent-colored send button
 * on the right. Above the pill: a sample chat bubble using the
 * selected persona/tone, and accent-bordered starter chips.
 *
 * The real widget lives in resources/widget/src/ui/Bar.tsx; this is
 * not the bundle, just a same-shape mock so admins can iterate on
 * colour + persona without saving + reloading + re-opening the
 * launcher on a real site.
 */
function WidgetPreview({ defaults }: { defaults: Defaults }) {
    const { t } = useT();
    const TONE_OPTIONS = useToneOptions();
    const primary = defaults.theme.primary || '#111827';
    const accent = defaults.theme.accent || '#10b981';
    const radius = defaults.theme.radius ?? 12;
    const launcherLabel =
        defaults.theme.launcher_label?.trim() || t('Ask anything');
    const personaName = defaults.persona.name?.trim() || t('Assistant');
    const tone =
        TONE_OPTIONS.find((o) => o.value === defaults.persona.tone) ??
        TONE_OPTIONS[0];

    return (
        <div className="sticky top-6 space-y-3">
            <div className="overflow-hidden rounded-2xl border border-border/70 bg-gradient-to-br from-slate-100 to-slate-50 dark:from-slate-900/60 dark:to-slate-950/40">
                <div className="flex items-center justify-between border-b border-border/50 bg-card/60 px-4 py-2 backdrop-blur-sm">
                    <span className="text-[11px] font-semibold tracking-wide text-muted-foreground uppercase">
                        {t('Live preview')}
                    </span>
                    <span className="text-[11px] text-muted-foreground">
                        {t('Visitor view')}
                    </span>
                </div>

                <div className="flex flex-col gap-3 px-5 py-6">
                    {/* Sample chat bubble — only shown if there's a tone sample */}
                    <div
                        className="max-w-[260px] self-start"
                        style={{
                            background: 'white',
                            borderRadius: radius,
                            boxShadow: '0 14px 32px rgba(0,0,0,0.06)',
                            padding: '10px 12px',
                        }}
                    >
                        <div
                            className="text-[10px] font-semibold tracking-wide uppercase"
                            style={{ color: '#64748b' }}
                        >
                            {personaName}
                        </div>
                        <div
                            className="mt-1 text-[13px] leading-snug"
                            style={{ color: '#0f172a' }}
                        >
                            {tone.sample}
                        </div>
                    </div>

                    {/* Starter prompt chips — show if any are set */}
                    {defaults.starter_prompts.length > 0 && (
                        <div className="flex flex-wrap justify-center gap-1.5 py-1">
                            {defaults.starter_prompts
                                .slice(0, 3)
                                .map((prompt, i) => (
                                    <span
                                        key={i}
                                        className="rounded-full border bg-white px-3 py-1 text-[11px] text-slate-900 shadow-sm"
                                        style={{
                                            borderColor: 'rgba(15,23,42,0.08)',
                                        }}
                                    >
                                        {prompt || t('Chip :n', { n: i + 1 })}
                                    </span>
                                ))}
                        </div>
                    )}

                    {/* The real launcher pill — white capsule with Orb + input + send */}
                    <div
                        className="flex items-center gap-2.5 self-stretch"
                        style={{
                            background: 'white',
                            borderRadius: 9999,
                            boxShadow: '0 14px 32px rgba(0,0,0,0.16)',
                            padding: '6px 8px 6px 8px',
                        }}
                    >
                        {/* Colored orb (uses primary as base, accent as ring) */}
                        <span className="relative inline-flex size-9 shrink-0 items-center justify-center">
                            <span
                                className="absolute inset-0 rounded-full"
                                style={{
                                    background: `radial-gradient(circle at 30% 30%, ${accent}, ${primary})`,
                                }}
                            />
                            <span
                                className="absolute inset-1 rounded-full opacity-80"
                                style={{
                                    background: `radial-gradient(circle at 70% 70%, ${primary}, transparent 60%)`,
                                }}
                            />
                        </span>

                        {/* Input placeholder showing launcher_label */}
                        <span
                            className="flex-1 truncate text-[13px]"
                            style={{ color: '#94a3b8' }}
                        >
                            {launcherLabel}
                        </span>

                        {/* Accent-colored send button */}
                        <span
                            className="inline-flex size-8 shrink-0 items-center justify-center text-white"
                            style={{
                                background: accent,
                                borderRadius: 9999,
                            }}
                        >
                            <svg
                                width="14"
                                height="14"
                                viewBox="0 0 24 24"
                                fill="none"
                                stroke="currentColor"
                                strokeWidth="2.5"
                                strokeLinecap="round"
                                strokeLinejoin="round"
                            >
                                <path d="M5 12l14-7-7 14-2-5-5-2z" />
                            </svg>
                        </span>
                    </div>
                </div>
            </div>
            <p className="text-[11px] leading-relaxed text-muted-foreground">
                {t(
                    'Mirrors the real omnibar — white capsule, orb on the left, accent-coloured send. Reply length and tone tune the LLM at runtime, not the visual.',
                )}
            </p>
        </div>
    );
}

export default function WidgetSettings({
    scope,
    effective,
    workspace_overrides,
    agents_count,
    endpoints,
}: Props) {
    const { t } = useT();
    const confirm = useConfirm();
    const TONE_OPTIONS = useToneOptions();
    const form = useForm<Defaults>({
        theme: effective.theme,
        persona: effective.persona,
        guardrails: effective.guardrails,
        starter_prompts: effective.starter_prompts,
    });

    const isPlatform = scope === 'platform';

    const [pushingToAll, setPushingToAll] = useState(false);

    const isUsingPlatformDefault = useMemo(
        () => Object.keys(workspace_overrides).length === 0,
        [workspace_overrides],
    );

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        form.patch(endpoints.update, { preserveScroll: true });
    };

    const applyToAll = async () => {
        if (!endpoints.apply_to_all || agents_count === 0) {
            return;
        }

        const ok = await confirm({
            title: t('Apply to all agents?'),
            message: t(
                'This replaces theme, persona, starter prompts, and reply length on ALL :count agents in this workspace with the workspace defaults you just saved.\n\nPer-agent customisations will be overwritten. Continue?',
                { count: agents_count },
            ),
            confirmLabel: t('Apply'),
            danger: true,
        });

        if (!ok) {
            return;
        }

        setPushingToAll(true);
        router.post(
            endpoints.apply_to_all,
            {},
            {
                preserveScroll: true,
                onFinish: () => setPushingToAll(false),
            },
        );
    };

    const updatePrompt = (i: number, val: string) => {
        const next = [...form.data.starter_prompts];
        next[i] = val.slice(0, MAX_PROMPT_LENGTH);
        form.setData('starter_prompts', next);
    };

    const removePrompt = (i: number) => {
        const next = form.data.starter_prompts.filter((_, idx) => idx !== i);
        form.setData('starter_prompts', next);
    };

    const addPrompt = () => {
        if (form.data.starter_prompts.length >= MAX_PROMPTS) {
            return;
        }

        form.setData('starter_prompts', [...form.data.starter_prompts, '']);
    };

    return (
        <>
            <Head
                title={
                    isPlatform
                        ? t('Platform widget defaults')
                        : t('Widget defaults')
                }
            />
            <h1 className="sr-only">
                {isPlatform
                    ? t('Platform widget defaults')
                    : t('Widget defaults')}
            </h1>
            <div className="flex flex-col gap-2 border-b pb-5">
                <Heading
                    variant="small"
                    title={
                        isPlatform
                            ? t('Platform-wide widget defaults')
                            : t('Widget defaults')
                    }
                    description={
                        isPlatform
                            ? t(
                                  'Sets the look, voice, and starter prompts NEW workspaces inherit on creation. Workspace owners override these via Settings → Widget.',
                              )
                            : t(
                                  'Set the look, voice, and starter prompts every new agent in this workspace inherits at creation.',
                              )
                    }
                />
                <p className="text-xs text-muted-foreground">
                    {isPlatform
                        ? t(
                              'Existing workspaces are not affected — defaults apply only to NEW workspaces.',
                          )
                        : isUsingPlatformDefault
                          ? t(
                                'Currently inheriting the platform-wide defaults. Saving creates a workspace override.',
                            )
                          : t(
                                'Workspace override active — changes only affect this workspace.',
                            )}
                </p>
            </div>

            <form
                onSubmit={submit}
                className="grid gap-6 pt-6 lg:grid-cols-[minmax(0,1fr)_minmax(280px,360px)]"
            >
                <div className="rounded-xl border bg-card">
                    <Section
                        icon={<Palette className="size-4" />}
                        title={t('Theme')}
                        description={t(
                            'Colour palette and corner radius applied to the visitor widget.',
                        )}
                    >
                        <div className="grid gap-4 sm:grid-cols-2">
                            <ColorField
                                id="primary"
                                label={t('Primary colour')}
                                value={form.data.theme.primary ?? ''}
                                placeholder="#111827"
                                onChange={(v) =>
                                    form.setData('theme', {
                                        ...form.data.theme,
                                        primary: v,
                                    })
                                }
                            />
                            <ColorField
                                id="accent"
                                label={t('Accent colour')}
                                value={form.data.theme.accent ?? ''}
                                placeholder="#10b981"
                                onChange={(v) =>
                                    form.setData('theme', {
                                        ...form.data.theme,
                                        accent: v,
                                    })
                                }
                            />
                            <div className="grid gap-1.5">
                                <Label htmlFor="radius">
                                    {t('Corner radius')}
                                </Label>
                                <div className="flex items-center gap-3">
                                    <input
                                        id="radius"
                                        type="range"
                                        min={0}
                                        max={32}
                                        value={form.data.theme.radius ?? 12}
                                        onChange={(e) =>
                                            form.setData('theme', {
                                                ...form.data.theme,
                                                radius: Number(e.target.value),
                                            })
                                        }
                                        className="flex-1"
                                    />
                                    <span className="w-10 text-end font-mono text-xs text-muted-foreground tabular-nums">
                                        {form.data.theme.radius ?? 12}px
                                    </span>
                                </div>
                            </div>
                            <div className="grid gap-1.5">
                                <Label htmlFor="launcher_label">
                                    {t('Launcher label')}
                                </Label>
                                <Input
                                    id="launcher_label"
                                    type="text"
                                    maxLength={48}
                                    placeholder={t('Ask anything')}
                                    value={form.data.theme.launcher_label ?? ''}
                                    onChange={(e) =>
                                        form.setData('theme', {
                                            ...form.data.theme,
                                            launcher_label: e.target.value,
                                        })
                                    }
                                />
                                <p className="text-[11px] leading-snug text-muted-foreground">
                                    {t(
                                        "Leave blank to use the vertical preset's suggestion.",
                                    )}
                                </p>
                            </div>
                        </div>
                    </Section>

                    <Section
                        icon={<MessageSquareQuote className="size-4" />}
                        title={t('Persona')}
                        description={t(
                            "The agent's display name and conversational tone.",
                        )}
                    >
                        <div className="grid gap-4">
                            <div className="grid gap-1.5">
                                <Label htmlFor="persona_name">
                                    {t('Display name')}
                                </Label>
                                <Input
                                    id="persona_name"
                                    type="text"
                                    maxLength={64}
                                    placeholder={t('Assistant')}
                                    value={form.data.persona.name ?? ''}
                                    onChange={(e) =>
                                        form.setData('persona', {
                                            ...form.data.persona,
                                            name: e.target.value,
                                        })
                                    }
                                    className="sm:max-w-md"
                                />
                            </div>
                            <div className="grid gap-1.5">
                                <Label>{t('Tone')}</Label>
                                <div className="grid gap-2 sm:grid-cols-3">
                                    {TONE_OPTIONS.map((option) => {
                                        const active =
                                            form.data.persona.tone ===
                                            option.value;

                                        return (
                                            <button
                                                key={option.value}
                                                type="button"
                                                onClick={() =>
                                                    form.setData('persona', {
                                                        ...form.data.persona,
                                                        tone: option.value,
                                                    })
                                                }
                                                className={`rounded-lg border p-3 text-start transition ${
                                                    active
                                                        ? 'border-foreground bg-muted/30 text-foreground shadow-xs'
                                                        : 'border-border text-muted-foreground hover:border-foreground/40 hover:text-foreground'
                                                }`}
                                            >
                                                <span className="block text-sm font-medium">
                                                    {option.label}
                                                </span>
                                                <span className="mt-1 block text-[11px] leading-snug">
                                                    {option.sample}
                                                </span>
                                            </button>
                                        );
                                    })}
                                </div>
                            </div>
                            <div className="grid gap-1.5 sm:max-w-md">
                                <Label htmlFor="max_chars">
                                    {t('Reply length cap')}
                                </Label>
                                <div className="flex items-center gap-3">
                                    <input
                                        id="max_chars"
                                        type="range"
                                        min={400}
                                        max={4000}
                                        step={100}
                                        value={
                                            form.data.guardrails.max_chars ??
                                            2500
                                        }
                                        onChange={(e) =>
                                            form.setData('guardrails', {
                                                ...form.data.guardrails,
                                                max_chars: Number(
                                                    e.target.value,
                                                ),
                                            })
                                        }
                                        className="flex-1"
                                    />
                                    <span className="w-16 text-end font-mono text-xs text-muted-foreground tabular-nums">
                                        {form.data.guardrails.max_chars ?? 2500}
                                    </span>
                                </div>
                                <p className="text-[11px] leading-snug text-muted-foreground">
                                    {t(
                                        "Hard cap on the assistant's reply. Lower values force tighter answers.",
                                    )}
                                </p>
                            </div>
                        </div>
                    </Section>

                    <Section
                        icon={<Sparkles className="size-4" />}
                        title={t('Starter prompts')}
                        description={t(
                            'Up to 6 chips visitors see before sending their first message.',
                        )}
                    >
                        <div className="grid gap-2">
                            {form.data.starter_prompts.length === 0 && (
                                <p className="rounded-md border border-dashed bg-muted/20 px-3 py-3 text-xs text-muted-foreground">
                                    {t(
                                        "No starter prompts yet. New agents will fall back to the vertical preset's suggestions.",
                                    )}
                                </p>
                            )}
                            {form.data.starter_prompts.map((prompt, i) => (
                                <div
                                    key={i}
                                    className="flex items-center gap-2"
                                >
                                    <span className="font-mono text-xs text-muted-foreground tabular-nums">
                                        {i + 1}.
                                    </span>
                                    <Input
                                        type="text"
                                        value={prompt}
                                        maxLength={MAX_PROMPT_LENGTH}
                                        placeholder={t('Chip :n', {
                                            n: i + 1,
                                        })}
                                        onChange={(e) =>
                                            updatePrompt(i, e.target.value)
                                        }
                                    />
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="icon"
                                        onClick={() => removePrompt(i)}
                                        title={t('Remove')}
                                    >
                                        <X className="size-4" />
                                    </Button>
                                </div>
                            ))}
                            {form.data.starter_prompts.length < MAX_PROMPTS && (
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    onClick={addPrompt}
                                    className="w-fit"
                                >
                                    {t('+ Add starter prompt')}
                                </Button>
                            )}
                        </div>
                    </Section>

                    <div className="flex flex-col-reverse gap-3 border-t bg-muted/20 px-5 py-4 sm:flex-row sm:items-center sm:justify-end">
                        {!isPlatform && endpoints.apply_to_all && (
                            <Button
                                type="button"
                                variant="outline"
                                onClick={applyToAll}
                                disabled={
                                    pushingToAll ||
                                    form.processing ||
                                    agents_count === 0
                                }
                                title={
                                    agents_count === 0
                                        ? t(
                                              'No agents yet — defaults will apply on the next agent created',
                                          )
                                        : t(
                                              'Push these defaults onto all :count existing agent(s)',
                                              { count: agents_count },
                                          )
                                }
                            >
                                {pushingToAll && (
                                    <Loader2 className="me-1 size-3.5 animate-spin" />
                                )}
                                {agents_count > 0
                                    ? t('Apply to all (:count)', {
                                          count: agents_count,
                                      })
                                    : t('Apply to all')}
                            </Button>
                        )}
                        <Button type="submit" disabled={form.processing}>
                            {form.processing && (
                                <Loader2 className="me-1 size-3.5 animate-spin" />
                            )}
                            {t('Save defaults')}
                        </Button>
                    </div>
                </div>

                <WidgetPreview defaults={form.data} />
            </form>

            {!isPlatform && (
                <p className="mt-3 text-xs text-muted-foreground">
                    {t('Platform-wide defaults are set by super-admins under')}{' '}
                    <code className="rounded bg-muted px-1 py-0.5 font-mono text-[11px]">
                        {t('Settings → Widget defaults')}
                    </code>
                    .{' '}
                    {t(
                        'Workspace defaults override the platform default; per-agent customisations override both.',
                    )}
                </p>
            )}
        </>
    );
}
