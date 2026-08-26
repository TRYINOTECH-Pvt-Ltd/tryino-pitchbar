import { Head, router } from '@inertiajs/react';
import {
    ArrowLeft,
    Loader2,
    RefreshCcw,
    Sparkles,
    TriangleAlert,
} from 'lucide-react';
import { useState } from 'react';
import { VerticalConfidencePill } from '@/components/agents/vertical-confidence-pill';
import { VerticalOverwriteDialog } from '@/components/agents/vertical-overwrite-dialog';
import { VerticalPresetPreview } from '@/components/agents/vertical-preset-preview';
import type { PresetPreview } from '@/components/agents/vertical-preset-preview';
import { VerticalRadioGrid } from '@/components/agents/vertical-radio-grid';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { useT } from '@/lib/i18n';
import { isVerticalId, VERTICAL_BY_ID } from '@/lib/verticals';
import type { VerticalId } from '@/lib/verticals';
import {
    index as agentsIndex,
    show as showAgent,
    vertical as agentVertical,
} from '@/routes/agents';
import { apply, detect } from '@/routes/agents/vertical';
import type { BreadcrumbItem } from '@/types';

type VerticalSignals = {
    type?: string;
    confidence?: number;
    detected_at?: string;
    detected_url?: string;
    signals?: string[];
};

type Agent = {
    id: string;
    name: string;
    site_type: VerticalId | null;
    vertical_signals: VerticalSignals | null;
    starter_prompts: string[] | null;
    guardrails: { max_chars?: number } | null;
    theme: { launcher_label?: string } | null;
};

type Props = {
    agent: Agent;
    preset_preview: Record<VerticalId, PresetPreview>;
};

function csrf(): string {
    return (
        (
            document.querySelector(
                'meta[name="csrf-token"]',
            ) as HTMLMetaElement | null
        )?.content ?? ''
    );
}

export default function Vertical({
    agent: initialAgent,
    preset_preview,
}: Props) {
    const { t } = useT();
    const [agent, setAgent] = useState(initialAgent);
    const initialChosen: VerticalId =
        agent.site_type && isVerticalId(agent.site_type)
            ? agent.site_type
            : 'generic';
    const [chosen, setChosen] = useState<VerticalId>(initialChosen);
    const [redetecting, setRedetecting] = useState(false);
    const [detectError, setDetectError] = useState<string | null>(null);
    const [applying, setApplying] = useState(false);
    const [overwriteOpen, setOverwriteOpen] = useState(false);

    const previewSlug: VerticalId = chosen ?? 'generic';
    const preview = preset_preview[previewSlug];

    const breadcrumbs: BreadcrumbItem[] = [
        { title: t('Agents'), href: agentsIndex.url() },
        { title: agent.name, href: showAgent.url({ agent: agent.id }) },
        { title: t('Site type'), href: agentVertical({ agent: agent.id }).url },
    ];

    const willOverwrite = (): boolean => {
        if (chosen === agent.site_type) {
            return false;
        }

        const startersSet = (agent.starter_prompts ?? []).length > 0;
        const launcherSet = !!agent.theme?.launcher_label?.trim();
        const maxCharsSet = typeof agent.guardrails?.max_chars === 'number';

        return startersSet || launcherSet || maxCharsSet;
    };

    const submitApply = async (force: boolean) => {
        setApplying(true);

        try {
            const res = await fetch(apply.url({ agent: agent.id }), {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': csrf(),
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({ site_type: chosen, force }),
            });

            if (!res.ok) {
                throw new Error(`HTTP ${res.status}`);
            }

            const json = (await res.json()) as { data: Agent };
            setAgent(json.data);
            setOverwriteOpen(false);
            router.reload({ only: ['agent'] });
        } catch (e) {
            setDetectError(
                e instanceof Error ? e.message : t('Could not apply preset.'),
            );
        } finally {
            setApplying(false);
        }
    };

    const onApplyClick = () => {
        if (willOverwrite()) {
            setOverwriteOpen(true);

            return;
        }

        void submitApply(false);
    };

    const onRedetect = async () => {
        const url =
            agent.vertical_signals?.detected_url ?? window.location.origin;
        setRedetecting(true);
        setDetectError(null);

        try {
            const res = await fetch(detect.url({ agent: agent.id }), {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': csrf(),
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({ url }),
            });

            if (!res.ok) {
                throw new Error(`HTTP ${res.status}`);
            }

            // Reload Inertia props so the page picks up the new
            // vertical_signals (cached on the agent row by the server).
            router.reload({ only: ['agent'] });
        } catch (e) {
            setDetectError(
                e instanceof Error ? e.message : t('Re-detection failed.'),
            );
        } finally {
            setRedetecting(false);
        }
    };

    const meta = VERTICAL_BY_ID[initialChosen];
    const Icon = meta.icon;
    const detectedConfidence = agent.vertical_signals?.confidence ?? null;
    const detectedAt = agent.vertical_signals?.detected_at ?? null;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`${agent.name} — Site type`} />
            <div className="mx-auto flex w-full max-w-5xl flex-col gap-6 p-6">
                <div className="flex items-start justify-between gap-3">
                    <div>
                        <h1 className="text-2xl font-semibold tracking-tight">
                            {t('Site type')}
                        </h1>
                        <p className="mt-1 text-sm text-muted-foreground">
                            {t(
                                'The vertical preset tunes starter prompts, response style, and capabilities for the kind of site this agent lives on.',
                            )}
                        </p>
                    </div>
                    <Button
                        variant="ghost"
                        onClick={() =>
                            router.visit(showAgent.url({ agent: agent.id }))
                        }
                    >
                        <ArrowLeft className="size-4" /> {t('Back to agent')}
                    </Button>
                </div>

                <div className="grid gap-6 lg:grid-cols-[minmax(0,1fr)_420px]">
                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2">
                                <Sparkles className="size-4" />
                                {t('Current vertical')}
                            </CardTitle>
                            <CardDescription>
                                {agent.site_type
                                    ? t(
                                          'You can change this any time. We will not touch your custom system prompt.',
                                      )
                                    : t(
                                          'No preset chosen yet. Pick one to get vertical-specific defaults.',
                                      )}
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="space-y-5">
                            <div className="flex items-center justify-between gap-3 rounded-lg border border-border bg-muted/30 px-4 py-3">
                                <span className="flex items-center gap-3">
                                    <span className="inline-flex size-9 items-center justify-center rounded-md border border-border bg-background">
                                        <Icon className="size-4" />
                                    </span>
                                    <span>
                                        <span className="block text-sm font-semibold">
                                            {meta.name}
                                        </span>
                                        <span className="block text-xs text-muted-foreground">
                                            {detectedAt
                                                ? t('Auto-detected :date', {
                                                      date: new Date(
                                                          detectedAt,
                                                      ).toLocaleDateString(),
                                                  })
                                                : meta.shortDesc}
                                        </span>
                                    </span>
                                </span>
                                {typeof detectedConfidence === 'number' &&
                                    detectedConfidence > 0 && (
                                        <VerticalConfidencePill
                                            score={detectedConfidence}
                                        />
                                    )}
                            </div>

                            <div className="flex items-center justify-between">
                                <Button
                                    variant="outline"
                                    onClick={onRedetect}
                                    disabled={redetecting}
                                >
                                    {redetecting ? (
                                        <>
                                            <Loader2 className="size-4 animate-spin" />
                                            {t('Detecting…')}
                                        </>
                                    ) : (
                                        <>
                                            <RefreshCcw className="size-4" />
                                            {t('Re-detect')}
                                        </>
                                    )}
                                </Button>
                                <span className="text-xs text-muted-foreground">
                                    {t(
                                        'Re-detection fetches the homepage again.',
                                    )}
                                </span>
                            </div>

                            {detectError && (
                                <div className="flex items-start gap-2 rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-900">
                                    <TriangleAlert className="mt-0.5 size-3.5 shrink-0" />
                                    <span>{detectError}</span>
                                </div>
                            )}

                            <div>
                                <p className="mb-2 text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                                    {t('Change vertical')}
                                </p>
                                <VerticalRadioGrid
                                    value={chosen}
                                    onChange={setChosen}
                                    detectedId={
                                        isVerticalId(
                                            agent.vertical_signals?.type,
                                        )
                                            ? (agent.vertical_signals
                                                  ?.type as VerticalId)
                                            : null
                                    }
                                />
                            </div>

                            <div className="flex justify-end">
                                <Button
                                    onClick={onApplyClick}
                                    disabled={
                                        applying || chosen === agent.site_type
                                    }
                                >
                                    {applying
                                        ? t('Applying…')
                                        : t('Apply preset')}
                                </Button>
                            </div>
                        </CardContent>
                    </Card>

                    <div className="space-y-3">
                        <p className="text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                            {t('What this preset does')}
                        </p>
                        {preview ? (
                            <VerticalPresetPreview preview={preview} />
                        ) : null}
                    </div>
                </div>
            </div>

            <VerticalOverwriteDialog
                open={overwriteOpen}
                onOpenChange={setOverwriteOpen}
                targetVertical={chosen}
                onConfirm={() => void submitApply(true)}
                pending={applying}
            />
        </AppLayout>
    );
}
