import { Head, router, useForm } from '@inertiajs/react';
import { Beaker, Trash2 } from 'lucide-react';
import { useConfirm } from '@/components/confirm-dialog-provider';
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
import { index as agentExperimentsIndex } from '@/routes/agents/experiments';
import type { BreadcrumbItem } from '@/types';

type Variant = { id: string; name: string; weight: number };

type Experiment = {
    id: string;
    name: string;
    kind: 'persona' | 'cta' | 'trigger';
    status: 'draft' | 'running' | 'stopped';
    started_at: string | null;
    stopped_at: string | null;
    variants: Variant[];
};

type Props = {
    agent: { id: string; name: string };
    experiments: Experiment[];
};

const STATUS_COLOR: Record<string, string> = {
    draft: 'bg-amber-500/15 text-amber-700 dark:text-amber-400',
    running: 'bg-emerald-500/15 text-emerald-700 dark:text-emerald-400',
    stopped: 'bg-zinc-500/15 text-zinc-700 dark:text-zinc-400',
};

export default function Experiments({ agent, experiments }: Props) {
    const { t, tChoice } = useT();
    const confirm = useConfirm();
    const breadcrumbs: BreadcrumbItem[] = [
        { title: t('Agents'), href: agentsIndex.url() },
        { title: agent.name, href: showAgent({ agent: agent.id }).url },
        {
            title: t('Experiments'),
            href: agentExperimentsIndex({ agent: agent.id }).url,
        },
    ];

    type VariantConfig = {
        persona?: { name?: string; tone?: string };
    };
    type VariantForm = {
        name: string;
        weight: number;
        config?: VariantConfig;
    };

    const form = useForm<{
        name: string;
        kind: 'persona' | 'cta' | 'trigger';
        variants: VariantForm[];
    }>({
        name: '',
        kind: 'cta',
        variants: [
            { name: 'control', weight: 50, config: {} },
            { name: 'treatment', weight: 50, config: {} },
        ],
    });

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        form.post(`/app/agents/${agent.id}/experiments`, {
            onSuccess: () => form.reset(),
        });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`${agent.name} · experiments`} />
            <div className="flex flex-1 flex-col gap-4 p-4">
                <h1 className="text-2xl font-semibold tracking-tight">
                    {t('A/B testing')}
                </h1>
                <p className="text-sm text-muted-foreground">
                    {t(
                        'Test persona, CTA, or trigger variations on a percentage of visitors. Sticky assignment per visitor.',
                    )}
                </p>

                <Card className="max-w-5xl gap-0 overflow-hidden border-border/80">
                    <CardHeader className="border-b bg-muted/20 px-4 py-4 sm:px-5">
                        <div className="flex items-start gap-3">
                            <span className="flex size-9 shrink-0 items-center justify-center rounded-md border bg-background text-muted-foreground shadow-xs">
                                <Beaker className="size-4" />
                            </span>
                            <div className="min-w-0">
                                <CardTitle className="text-sm">
                                    {t('Create experiment')}
                                </CardTitle>
                                <CardDescription className="mt-1 text-xs leading-5">
                                    {t(
                                        'Test different personas, CTAs, or triggers on a portion of your traffic with sticky visitor assignment.',
                                    )}
                                </CardDescription>
                            </div>
                        </div>
                    </CardHeader>
                    <form onSubmit={submit}>
                        <CardContent className="grid gap-5 px-4 py-4 sm:px-5 sm:py-5">
                            <div className="grid gap-4 md:grid-cols-2">
                                <div className="grid gap-1.5">
                                    <Label htmlFor="name">{t('Name')}</Label>
                                    <Input
                                        id="name"
                                        placeholder={t('CTA copy test')}
                                        value={form.data.name}
                                        aria-invalid={Boolean(form.errors.name)}
                                        onChange={(e) =>
                                            form.setData('name', e.target.value)
                                        }
                                    />
                                    <InputError message={form.errors.name} />
                                </div>
                                <div className="grid gap-1.5">
                                    <Label htmlFor="kind">{t('Kind')}</Label>
                                    <Select
                                        value={form.data.kind}
                                        onValueChange={(value) =>
                                            form.setData(
                                                'kind',
                                                value as
                                                    | 'persona'
                                                    | 'cta'
                                                    | 'trigger',
                                            )
                                        }
                                    >
                                        <SelectTrigger id="kind">
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="persona">
                                                {t('Persona')}
                                            </SelectItem>
                                            <SelectItem value="cta">
                                                {t('CTA')}
                                            </SelectItem>
                                            <SelectItem value="trigger">
                                                {t('Trigger')}
                                            </SelectItem>
                                        </SelectContent>
                                    </Select>
                                </div>
                            </div>

                            <div className="grid gap-3">
                                <div className="flex items-center justify-between gap-3">
                                    <div>
                                        <Label>{t('Variants')}</Label>
                                        <p className="mt-1 text-xs text-muted-foreground">
                                            {t(
                                                'Weights are relative traffic shares. Keep the total roughly balanced so comparisons stay readable.',
                                            )}
                                        </p>
                                    </div>
                                    <Button
                                        type="button"
                                        variant="outline"
                                        size="sm"
                                        disabled={
                                            form.data.variants.length >= 5
                                        }
                                        onClick={() =>
                                            form.setData('variants', [
                                                ...form.data.variants,
                                                {
                                                    name: `variant_${form.data.variants.length + 1}`,
                                                    weight: 50,
                                                    config: {},
                                                },
                                            ])
                                        }
                                    >
                                        {t('Add variant')}
                                    </Button>
                                </div>
                                <div className="grid gap-2 rounded-lg border bg-muted/15 p-3 sm:p-4">
                                    {form.data.variants.map(
                                        (variant, index) => (
                                            <div
                                                key={index}
                                                className="grid gap-2 rounded-md border bg-background p-2 sm:grid-cols-[minmax(0,1fr)_96px_auto]"
                                            >
                                                <Input
                                                    placeholder={t(
                                                        'Variant name',
                                                    )}
                                                    value={variant.name}
                                                    onChange={(e) => {
                                                        const next = [
                                                            ...form.data
                                                                .variants,
                                                        ];
                                                        next[index] = {
                                                            ...next[index],
                                                            name: e.target
                                                                .value,
                                                        };
                                                        form.setData(
                                                            'variants',
                                                            next,
                                                        );
                                                    }}
                                                />
                                                <Input
                                                    type="number"
                                                    min={1}
                                                    max={100}
                                                    value={variant.weight}
                                                    onChange={(e) => {
                                                        const next = [
                                                            ...form.data
                                                                .variants,
                                                        ];
                                                        next[index] = {
                                                            ...next[index],
                                                            weight: parseInt(
                                                                e.target.value,
                                                                10,
                                                            ),
                                                        };
                                                        form.setData(
                                                            'variants',
                                                            next,
                                                        );
                                                    }}
                                                />
                                                <Button
                                                    type="button"
                                                    variant="ghost"
                                                    size="sm"
                                                    disabled={
                                                        form.data.variants
                                                            .length <= 2
                                                    }
                                                    onClick={() => {
                                                        form.setData(
                                                            'variants',
                                                            form.data.variants.filter(
                                                                (_, i) =>
                                                                    i !== index,
                                                            ),
                                                        );
                                                    }}
                                                >
                                                    <Trash2 className="size-4" />
                                                </Button>
                                                {form.data.kind ===
                                                    'persona' && (
                                                    <div className="grid gap-2 sm:col-span-3 sm:grid-cols-2">
                                                        <Input
                                                            placeholder={t(
                                                                'Persona name (e.g. Aria) — defaults to variant name',
                                                            )}
                                                            value={
                                                                variant.config
                                                                    ?.persona
                                                                    ?.name ?? ''
                                                            }
                                                            onChange={(e) => {
                                                                const next = [
                                                                    ...form.data
                                                                        .variants,
                                                                ];
                                                                next[index] = {
                                                                    ...next[
                                                                        index
                                                                    ],
                                                                    config: {
                                                                        ...next[
                                                                            index
                                                                        ]
                                                                            .config,
                                                                        persona:
                                                                            {
                                                                                ...next[
                                                                                    index
                                                                                ]
                                                                                    .config
                                                                                    ?.persona,
                                                                                name: e
                                                                                    .target
                                                                                    .value,
                                                                            },
                                                                    },
                                                                };
                                                                form.setData(
                                                                    'variants',
                                                                    next,
                                                                );
                                                            }}
                                                        />
                                                        <Input
                                                            placeholder={t(
                                                                'Persona tone (e.g. warm and concise)',
                                                            )}
                                                            value={
                                                                variant.config
                                                                    ?.persona
                                                                    ?.tone ?? ''
                                                            }
                                                            onChange={(e) => {
                                                                const next = [
                                                                    ...form.data
                                                                        .variants,
                                                                ];
                                                                next[index] = {
                                                                    ...next[
                                                                        index
                                                                    ],
                                                                    config: {
                                                                        ...next[
                                                                            index
                                                                        ]
                                                                            .config,
                                                                        persona:
                                                                            {
                                                                                ...next[
                                                                                    index
                                                                                ]
                                                                                    .config
                                                                                    ?.persona,
                                                                                tone: e
                                                                                    .target
                                                                                    .value,
                                                                            },
                                                                    },
                                                                };
                                                                form.setData(
                                                                    'variants',
                                                                    next,
                                                                );
                                                            }}
                                                        />
                                                    </div>
                                                )}
                                            </div>
                                        ),
                                    )}
                                </div>
                            </div>
                        </CardContent>
                        <CardFooter className="flex flex-col-reverse gap-2 border-t bg-muted/10 px-4 py-4 sm:flex-row sm:justify-end sm:px-5">
                            <Button
                                type="submit"
                                disabled={form.processing}
                                className="w-full sm:w-auto"
                            >
                                {t('Create experiment')}
                            </Button>
                        </CardFooter>
                    </form>
                </Card>

                <Card className="p-4">
                    <h2 className="mb-3 font-medium">
                        {t('Existing experiments')}
                    </h2>
                    {experiments.length === 0 ? (
                        <EmptyState
                            icon={Beaker}
                            title={t('No experiments running')}
                            description={t(
                                'Test variants of persona, system prompt, CTAs, or triggers on a slice of visitors and compare conversation outcomes side-by-side.',
                            )}
                        />
                    ) : (
                        <div className="divide-y">
                            {experiments.map((exp) => (
                                <div
                                    key={exp.id}
                                    className="flex items-start justify-between gap-3 py-3"
                                >
                                    <div className="min-w-0 flex-1">
                                        <div className="flex items-center gap-2">
                                            <p className="text-sm font-medium">
                                                {exp.name}
                                            </p>
                                            <span
                                                className={`rounded px-2 py-0.5 text-xs ${STATUS_COLOR[exp.status] ?? ''}`}
                                            >
                                                {t(exp.status)}
                                            </span>
                                        </div>
                                        <p className="mt-1 text-xs text-muted-foreground">
                                            {exp.kind} ·{' '}
                                            {tChoice(
                                                ':count variant|:count variants',
                                                exp.variants.length,
                                                { count: exp.variants.length },
                                            )}
                                            :{' '}
                                            {exp.variants
                                                .map(
                                                    (v) =>
                                                        `${v.name} (${v.weight})`,
                                                )
                                                .join(', ')}
                                        </p>
                                    </div>
                                    <div className="flex gap-2">
                                        {exp.status !== 'running' && (
                                            <Button
                                                size="sm"
                                                onClick={() =>
                                                    router.post(
                                                        `/app/experiments/${exp.id}/start`,
                                                    )
                                                }
                                            >
                                                {t('Start')}
                                            </Button>
                                        )}
                                        {exp.status === 'running' && (
                                            <Button
                                                size="sm"
                                                variant="outline"
                                                onClick={() =>
                                                    router.post(
                                                        `/app/experiments/${exp.id}/stop`,
                                                    )
                                                }
                                            >
                                                {t('Stop')}
                                            </Button>
                                        )}
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            onClick={async () => {
                                                const ok = await confirm({
                                                    title: t(
                                                        'Delete experiment?',
                                                    ),
                                                    message: t(
                                                        'Delete this experiment?',
                                                    ),
                                                    confirmLabel: t('Delete'),
                                                    danger: true,
                                                });

                                                if (!ok) {
                                                    return;
                                                }

                                                router.delete(
                                                    `/app/experiments/${exp.id}`,
                                                );
                                            }}
                                        >
                                            <Trash2 className="size-4" />
                                        </Button>
                                    </div>
                                </div>
                            ))}
                        </div>
                    )}
                </Card>
            </div>
        </AppLayout>
    );
}
