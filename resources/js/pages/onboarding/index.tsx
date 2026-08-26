import { Head, router } from '@inertiajs/react';
import { Check, Copy, Loader2, Sparkles, TriangleAlert } from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';
import { VerticalConfidencePill } from '@/components/agents/vertical-confidence-pill';
import { VerticalRadioGrid } from '@/components/agents/vertical-radio-grid';
import { DirArrow } from '@/components/dir-icon';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { useT } from '@/lib/i18n';
import { isVerticalId, VERTICAL_BY_ID } from '@/lib/verticals';
import type { DetectionResult, VerticalId } from '@/lib/verticals';
import { dashboard } from '@/routes';
import { index as agentsSourcesIndex } from '@/routes/agents/sources';

type AgentSummary = {
    id: string;
    name: string;
    is_published: boolean;
    sources_count: number;
    sources_indexed: number;
};

type WorkspaceSummary = {
    id: string;
    name: string;
};

type DiscoverResponse = {
    data: {
        root: string;
        sitemap_urls: string[];
        probed_urls: string[];
        total: number;
    };
};

type PlanSummary = {
    id: string;
    name: string;
    price_cents: number;
    interval?: 'month' | 'year';
    monthly_conversations?: number;
};

type Props = {
    agent: AgentSummary;
    workspace: WorkspaceSummary;
    is_new_agent: boolean;
    start_url: string | null;
    widget: { src: string; agent_id: string };
    current_plan: { id: string; name: string; price_cents: number } | null;
    available_plans: PlanSummary[];
};

type Step = 'connect' | 'detect' | 'pick' | 'install';

function csrf(): string {
    return (
        (
            document.querySelector(
                'meta[name="csrf-token"]',
            ) as HTMLMetaElement | null
        )?.content ?? ''
    );
}

export default function Onboarding({
    agent: initialAgent,
    workspace,
    is_new_agent: isNewAgent,
    start_url,
    widget,
    current_plan: currentPlan,
    available_plans: availablePlans,
}: Props) {
    const { t } = useT();
    const [step, setStep] = useState<Step>(
        initialAgent.sources_count === 0 ? 'connect' : 'install',
    );
    const [domain, setDomain] = useState(start_url ?? '');
    const [discovering, setDiscovering] = useState(false);
    const [discoverError, setDiscoverError] = useState<string | null>(null);
    const [discovered, setDiscovered] = useState<
        DiscoverResponse['data'] | null
    >(null);
    const [picked, setPicked] = useState<Record<string, boolean>>({});
    const [adding, setAdding] = useState(false);
    const [agent, setAgent] = useState(initialAgent);
    const pollRef = useRef<number | null>(null);
    // Detect-step state. Set when discovery succeeds and the wizard
    // advances to the new 'detect' step inserted between connect and pick.
    const [detection, setDetection] = useState<DetectionResult | null>(null);
    const [detecting, setDetecting] = useState(false);
    const [detectError, setDetectError] = useState<string | null>(null);
    const [chosenVertical, setChosenVertical] = useState<VerticalId | null>(
        null,
    );
    const [applying, setApplying] = useState(false);
    // Inline-rename state for the "Setting up: AGENT NAME" header.
    // Visitors are confused if the wizard configures an auto-named
    // agent ("Demo Customer's Workspace agent") without showing them
    // they CAN rename it. So we expose a pencil-icon edit button.
    const [editingName, setEditingName] = useState(false);
    const [draftName, setDraftName] = useState(initialAgent.name);
    const [savingName, setSavingName] = useState(false);
    const [nameError, setNameError] = useState<string | null>(null);

    const saveName = async () => {
        const trimmed = draftName.trim();

        if (trimmed === '' || trimmed === agent.name) {
            setEditingName(false);
            setDraftName(agent.name);
            setNameError(null);

            return;
        }

        setSavingName(true);
        setNameError(null);

        try {
            // The controller's update() returns a `back()` 302 redirect (Inertia
            // pattern). We don't want to follow it with PATCH — that 405s on
            // /onboarding. `redirect: 'manual'` keeps the request itself
            // successful and gives us an opaqueredirect we can treat as OK.
            const res = await fetch(`/app/agents/${agent.id}`, {
                method: 'PATCH',
                credentials: 'same-origin',
                redirect: 'manual',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': csrf(),
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({ name: trimmed }),
            });

            if (!res.ok && res.type !== 'opaqueredirect') {
                throw new Error(`HTTP ${res.status}`);
            }

            setAgent((a) => ({ ...a, name: trimmed }));
            setEditingName(false);
        } catch (e) {
            setNameError(
                e instanceof Error ? e.message : t('Could not save name.'),
            );
        } finally {
            setSavingName(false);
        }
    };

    const allUrls = useMemo(
        () =>
            discovered
                ? [...discovered.sitemap_urls, ...discovered.probed_urls]
                : [],
        [discovered],
    );
    const pickedCount = useMemo(
        () => Object.values(picked).filter(Boolean).length,
        [picked],
    );

    const snippet = `<script src="${widget.src}" data-agent-id="${widget.agent_id}" defer></script>`;

    const discover = async () => {
        if (!domain) {
            return;
        }

        setDiscovering(true);
        setDiscoverError(null);

        try {
            const res = await fetch(
                `/app/agents/${agent.id}/sources/discover`,
                {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Content-Type': 'application/json',
                        Accept: 'application/json',
                        'X-CSRF-TOKEN': csrf(),
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    body: JSON.stringify({ url: domain }),
                },
            );

            if (!res.ok) {
                const text = await res.text();

                throw new Error(text || `HTTP ${res.status}`);
            }

            const json = (await res.json()) as DiscoverResponse;
            setDiscovered(json.data);
            // Pre-tick the first 8 sitemap URLs and all probed paths.
            const initial: Record<string, boolean> = {};
            json.data.sitemap_urls
                .slice(0, 8)
                .forEach((u) => (initial[u] = true));
            json.data.probed_urls.forEach((u) => (initial[u] = true));
            setPicked(initial);
            // Advance to the new detect step. Detection auto-fires when
            // the step renders (effect below).
            setStep('detect');
        } catch (e) {
            setDiscoverError(
                e instanceof Error ? e.message : t('Discovery failed.'),
            );
        } finally {
            setDiscovering(false);
        }
    };

    // Auto-run detection when the wizard reaches the 'detect' step. The
    // result is cached on the agent server-side so re-runs are cheap.
    //
    // We use a ref to track in-flight + completion so React re-renders
    // (which setDetecting / setDetection trigger) don't fire cleanup
    // and abort our own fetch mid-flight. State variables in the deps
    // would create a race: setDetecting(true) → effect re-runs →
    // cleanup sets cancelled=true → fetch resolves → "if (cancelled)
    // return" silently swallows the result and spinner hangs forever.
    // Tracks whether detection has fired for the current (step, agent.id,
    // domain) tuple. If any of these change (e.g. user goes Back to
    // Connect, edits URL, then comes forward again), the ref resets and
    // detection re-fires.
    const detectStartedRef = useRef<string | null>(null);

    useEffect(() => {
        if (step !== 'detect') {
            // Step left detect — reset so a future re-entry triggers a
            // fresh detection (e.g. user went back, edited URL).
            detectStartedRef.current = null;

            return;
        }

        const key = `${agent.id}|${domain}`;

        if (detectStartedRef.current === key) {
            return;
        }

        detectStartedRef.current = key;

        const controller = new AbortController();
        // 20-second hard timeout so a hung backend never freezes the UI.
        const timeoutId = window.setTimeout(() => controller.abort(), 20_000);

        const run = async () => {
            setDetecting(true);
            setDetectError(null);

            try {
                const res = await fetch(
                    `/app/agents/${agent.id}/vertical/detect`,
                    {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: {
                            'Content-Type': 'application/json',
                            Accept: 'application/json',
                            'X-CSRF-TOKEN': csrf(),
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                        body: JSON.stringify({ url: domain }),
                        signal: controller.signal,
                    },
                );

                if (!res.ok) {
                    throw new Error(`HTTP ${res.status}`);
                }

                const json = (await res.json()) as { data: DetectionResult };
                const result = json.data;
                // Sanity-check the slug — server is authoritative but
                // forward-compat for unknown slugs falls back to generic.
                const safeType: VerticalId = isVerticalId(result.type)
                    ? result.type
                    : 'generic';
                setDetection({ ...result, type: safeType });
                setChosenVertical(safeType);
            } catch (e) {
                const aborted =
                    e instanceof DOMException && e.name === 'AbortError';
                setDetectError(
                    aborted
                        ? t('Detection timed out — pick a vertical manually.')
                        : e instanceof Error
                          ? e.message
                          : t('Detection failed — pick a vertical manually.'),
                );
                // Soft-fail to a generic guess so admin can proceed.
                setDetection({
                    type: 'generic',
                    confidence: 0,
                    alternatives: [],
                    signals: [aborted ? 'timeout' : 'fetch_failed'],
                });
                setChosenVertical('generic');
            } finally {
                window.clearTimeout(timeoutId);
                setDetecting(false);
            }
        };
        void run();

        return () => {
            // Component unmount or step change — abort the in-flight
            // request. We do NOT reset detectStartedRef here so the
            // detection only fires once per visit to the detect step.
            window.clearTimeout(timeoutId);
            controller.abort();
        };
    }, [step, agent.id, domain, t]);

    const applyVertical = async (slug: VerticalId) => {
        setApplying(true);

        try {
            const res = await fetch(`/app/agents/${agent.id}/vertical/apply`, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': csrf(),
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({ site_type: slug }),
            });

            if (!res.ok) {
                throw new Error(`HTTP ${res.status}`);
            }

            setStep('pick');
        } catch (e) {
            setDetectError(
                e instanceof Error
                    ? e.message
                    : t('Could not apply preset — try again.'),
            );
        } finally {
            setApplying(false);
        }
    };

    const addPicked = async () => {
        const urls = Object.entries(picked)
            .filter(([, v]) => v)
            .map(([u]) => u);

        if (urls.length === 0) {
            return;
        }

        setAdding(true);

        try {
            const fd = new FormData();

            for (const u of urls) {
                fd.append('urls[]', u);
            }

            fd.append('_token', csrf());
            const res = await fetch(`/app/agents/${agent.id}/sources/bulk`, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'X-CSRF-TOKEN': csrf(),
                    'X-Requested-With': 'XMLHttpRequest',
                    Accept: 'application/json',
                },
                body: fd,
            });

            if (!res.ok) {
                throw new Error(`HTTP ${res.status}`);
            }

            setStep('install');
            setAgent((a) => ({
                ...a,
                sources_count: a.sources_count + urls.length,
            }));
        } catch (e) {
            setDiscoverError(
                e instanceof Error ? e.message : t('Bulk add failed.'),
            );
        } finally {
            setAdding(false);
        }
    };

    // Poll the agent for indexed-source progress while on the install step.
    // Stops once everything is indexed (no point hammering the server) and
    // also bails after MAX_TICKS so a dead-end source can't keep us polling
    // forever.
    useEffect(() => {
        if (step !== 'install') {
            return;
        }

        const allDone =
            agent.sources_count > 0 &&
            agent.sources_indexed >= agent.sources_count;

        if (allDone) {
            return;
        }

        const MAX_TICKS = 60; // 60 × 3s = 3 minutes of patience
        let ticks = 0;
        let stopped = false;

        const tick = async () => {
            ticks++;

            if (stopped) {
                return;
            }

            try {
                const r = await fetch(
                    `/api/v1/agents/${agent.id}/onboarding-status`,
                    {
                        credentials: 'same-origin',
                        headers: { Accept: 'application/json' },
                    },
                );

                if (r.ok) {
                    const j = await r.json();
                    const data = j.data ?? {};
                    setAgent((a) => ({ ...a, ...data }));

                    // Stop when caught up or we've waited long enough.
                    const done =
                        data.sources_count > 0 &&
                        data.sources_indexed >= data.sources_count;

                    if (done || ticks >= MAX_TICKS) {
                        if (pollRef.current !== null) {
                            window.clearInterval(pollRef.current);
                            pollRef.current = null;
                        }
                    }
                }
            } catch {
                // ignore — tick failures are not fatal.
            }
        };
        pollRef.current = window.setInterval(tick, 3000) as unknown as number;

        return () => {
            stopped = true;

            if (pollRef.current !== null) {
                window.clearInterval(pollRef.current);
                pollRef.current = null;
            }
        };
    }, [step, agent.id, agent.sources_count, agent.sources_indexed]);

    return (
        <AppLayout breadcrumbs={[{ title: t('Setup'), href: '/onboarding' }]}>
            <Head title={t('Set up your AI agent')} />
            <div className="mx-auto flex w-full max-w-3xl flex-col gap-6 p-6">
                <div className="flex items-center gap-2 text-sm text-muted-foreground">
                    <Sparkles className="size-4" />{' '}
                    {t('First-time setup · 4 quick steps')}
                </div>

                {availablePlans.length > 1 && (
                    <Card className="border-border/80 p-4">
                        <div className="mb-2 flex items-center justify-between gap-3">
                            <div>
                                <p className="text-sm font-medium">
                                    {t('Your starting plan')}
                                </p>
                                <p className="text-xs text-muted-foreground">
                                    {currentPlan
                                        ? t(
                                              "You're on :name. Switch any time from billing.",
                                              { name: currentPlan.name },
                                          )
                                        : t(
                                              'No plan attached yet. Pick one to lift limits.',
                                          )}
                                </p>
                            </div>
                            <a
                                href="/app/billing"
                                className="shrink-0 rounded-full border border-foreground bg-foreground px-3 py-1.5 text-xs font-medium text-background hover:bg-foreground/90"
                            >
                                {t('View plans')}
                            </a>
                        </div>
                        <div className="grid grid-cols-1 gap-2 sm:grid-cols-2 md:grid-cols-3">
                            {availablePlans.slice(0, 3).map((p) => {
                                const isCurrent = currentPlan?.id === p.id;

                                return (
                                    <div
                                        key={p.id}
                                        className={`flex items-center justify-between rounded-md border p-2 text-xs ${
                                            isCurrent
                                                ? 'border-foreground bg-foreground/5'
                                                : 'border-border bg-background'
                                        }`}
                                    >
                                        <div className="min-w-0">
                                            <p className="truncate font-medium">
                                                {p.name}
                                            </p>
                                            <p className="text-[10px] text-muted-foreground">
                                                {p.price_cents === 0
                                                    ? t('Free')
                                                    : t(':amount /:period', {
                                                          amount: `$${(p.price_cents / 100).toFixed(0)}`,
                                                          period:
                                                              p.interval ===
                                                              'year'
                                                                  ? t('yr')
                                                                  : t('mo'),
                                                      })}
                                            </p>
                                        </div>
                                        {isCurrent && (
                                            <span className="rounded-full bg-foreground px-1.5 py-0.5 text-[9px] font-semibold text-background">
                                                {t('Current')}
                                            </span>
                                        )}
                                    </div>
                                );
                            })}
                        </div>
                    </Card>
                )}

                {/* Subject card — makes it explicit which agent is being
                    configured + what onboarding actually accomplishes. */}
                <Card className="border-border/80 bg-muted/20 p-5">
                    <div className="flex items-start justify-between gap-4">
                        <div className="min-w-0 flex-1">
                            <p className="text-[11px] font-semibold tracking-wide text-muted-foreground uppercase">
                                {isNewAgent
                                    ? t('Creating your first agent in')
                                    : t('Configuring agent in')}{' '}
                                {workspace.name}
                            </p>
                            <div className="mt-1 flex items-center gap-2">
                                {editingName ? (
                                    <>
                                        <Input
                                            autoFocus
                                            value={draftName}
                                            disabled={savingName}
                                            onChange={(e) =>
                                                setDraftName(e.target.value)
                                            }
                                            onKeyDown={(e) => {
                                                if (e.key === 'Enter') {
                                                    void saveName();
                                                } else if (e.key === 'Escape') {
                                                    setEditingName(false);
                                                    setDraftName(agent.name);
                                                    setNameError(null);
                                                }
                                            }}
                                            className="max-w-sm text-lg font-semibold"
                                        />
                                        <Button
                                            size="sm"
                                            onClick={() => void saveName()}
                                            disabled={savingName}
                                        >
                                            {savingName
                                                ? t('Saving…')
                                                : t('Save')}
                                        </Button>
                                        <Button
                                            size="sm"
                                            variant="ghost"
                                            onClick={() => {
                                                setEditingName(false);
                                                setDraftName(agent.name);
                                                setNameError(null);
                                            }}
                                            disabled={savingName}
                                        >
                                            {t('Cancel')}
                                        </Button>
                                    </>
                                ) : (
                                    <>
                                        <h2 className="text-lg font-semibold tracking-tight">
                                            {agent.name}
                                        </h2>
                                        <button
                                            type="button"
                                            onClick={() => setEditingName(true)}
                                            className="rounded-md border border-border bg-background px-2 py-0.5 text-xs text-muted-foreground transition hover:text-foreground"
                                            title={t('Rename this agent')}
                                        >
                                            {t('Rename')}
                                        </button>
                                    </>
                                )}
                            </div>
                            {nameError && (
                                <p className="mt-2 text-xs text-destructive">
                                    {nameError}
                                </p>
                            )}
                            <p className="mt-3 text-sm text-muted-foreground">
                                {isNewAgent
                                    ? t(
                                          "We've created an agent for you in this workspace. The 4 steps below feed it the knowledge it needs and give you a snippet to embed on your site.",
                                      )
                                    : t(
                                          'These 4 steps configure this agent: pick which pages it learns from, then grab a snippet to embed it on your site.',
                                      )}{' '}
                                {t(
                                    'You can always add more agents, edit personality, theme, or knowledge sources from the agent dashboard later.',
                                )}
                            </p>
                        </div>
                    </div>
                </Card>

                <div className="flex items-center gap-2 text-xs">
                    <StepDot
                        active={step === 'connect'}
                        done={step !== 'connect'}
                        label={t('1. Connect')}
                    />
                    <DirArrow
                        direction="forward"
                        style="chevron"
                        className="size-3 text-muted-foreground"
                    />
                    <StepDot
                        active={step === 'detect'}
                        done={step === 'pick' || step === 'install'}
                        label={t('2. Detect')}
                    />
                    <DirArrow
                        direction="forward"
                        style="chevron"
                        className="size-3 text-muted-foreground"
                    />
                    <StepDot
                        active={step === 'pick'}
                        done={step === 'install'}
                        label={t('3. Pick pages')}
                    />
                    <DirArrow
                        direction="forward"
                        style="chevron"
                        className="size-3 text-muted-foreground"
                    />
                    <StepDot
                        active={step === 'install'}
                        done={false}
                        label={t('4. Install')}
                    />
                </div>

                {step === 'connect' && (
                    <Card className="border-border/80 p-6">
                        <h1 className="text-xl font-semibold tracking-tight">
                            {t('Where does your agent live?')}
                        </h1>
                        <p className="mt-1 text-sm text-muted-foreground">
                            {t(
                                "Paste your website URL. We'll auto-discover the pages worth indexing.",
                            )}
                        </p>
                        <div className="mt-4 grid gap-1.5">
                            <Label htmlFor="domain">{t('Website URL')}</Label>
                            <Input
                                id="domain"
                                type="url"
                                placeholder="https://your-site.com"
                                value={domain}
                                onChange={(e) => setDomain(e.target.value)}
                            />
                            {discoverError && (
                                <p className="mt-2 text-xs text-destructive">
                                    {discoverError}
                                </p>
                            )}
                        </div>
                        <div className="mt-4 flex gap-2">
                            <Button
                                onClick={discover}
                                disabled={!domain || discovering}
                            >
                                {discovering ? (
                                    <>
                                        <Loader2 className="size-4 animate-spin" />{' '}
                                        {t('Scanning…')}
                                    </>
                                ) : (
                                    t('Scan my site')
                                )}
                            </Button>
                            <Button
                                variant="ghost"
                                onClick={() => router.visit(dashboard().url)}
                            >
                                {t('Skip for now')}
                            </Button>
                        </div>
                    </Card>
                )}

                {step === 'detect' && (
                    <Card className="border-border/80 p-6">
                        <h1 className="text-xl font-semibold tracking-tight">
                            {t('What kind of site is this?')}
                        </h1>
                        <p className="mt-1 text-sm text-muted-foreground">
                            {t(
                                "We'll tune starter prompts, response style, and capabilities to match. You can change this any time later.",
                            )}
                        </p>

                        {detecting && !detection && (
                            <div className="mt-6 flex flex-col items-center gap-3 rounded-lg border border-dashed border-border bg-muted/20 px-6 py-10 text-center">
                                <Loader2 className="size-6 animate-spin text-muted-foreground" />
                                <p className="text-sm text-muted-foreground">
                                    {t('Looking at your homepage…')}
                                </p>
                                <p className="text-xs text-muted-foreground/80">
                                    {t(
                                        "We're scanning a few signals (page metadata, schema, URL shape) to suggest the best preset.",
                                    )}
                                </p>
                            </div>
                        )}

                        {detection && (
                            <>
                                {detectError && (
                                    <div className="mt-4 flex items-start gap-2 rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-900">
                                        <TriangleAlert className="mt-0.5 size-3.5 shrink-0" />
                                        <span>
                                            {detectError}{' '}
                                            {t(
                                                'You can pick a vertical manually below.',
                                            )}
                                        </span>
                                    </div>
                                )}

                                {detection.confidence > 0 &&
                                    detection.type !== 'generic' && (
                                        <div className="mt-5 flex flex-col gap-2 rounded-lg border border-border bg-muted/30 px-4 py-3">
                                            <div className="flex items-center justify-between gap-3">
                                                <span className="flex items-center gap-3">
                                                    {(() => {
                                                        const meta =
                                                            VERTICAL_BY_ID[
                                                                detection.type
                                                            ];
                                                        const Icon = meta.icon;

                                                        return (
                                                            <span className="inline-flex size-9 items-center justify-center rounded-md border border-border bg-background">
                                                                <Icon className="size-4" />
                                                            </span>
                                                        );
                                                    })()}
                                                    <span>
                                                        <span className="block text-sm font-semibold">
                                                            {t('Detected:')}{' '}
                                                            {
                                                                VERTICAL_BY_ID[
                                                                    detection
                                                                        .type
                                                                ].name
                                                            }
                                                        </span>
                                                        <span className="block text-xs text-muted-foreground">
                                                            {
                                                                VERTICAL_BY_ID[
                                                                    detection
                                                                        .type
                                                                ].shortDesc
                                                            }
                                                        </span>
                                                    </span>
                                                </span>
                                                <VerticalConfidencePill
                                                    score={detection.confidence}
                                                />
                                            </div>
                                            {detection.alternatives &&
                                                detection.alternatives.length >
                                                    0 && (
                                                    <p className="text-xs text-muted-foreground">
                                                        {t(
                                                            'Other possibilities:',
                                                        )}{' '}
                                                        {detection.alternatives
                                                            .slice(0, 3)
                                                            .map((alt, i) => (
                                                                <span
                                                                    key={
                                                                        alt.type
                                                                    }
                                                                >
                                                                    {i > 0 &&
                                                                        ', '}
                                                                    <button
                                                                        type="button"
                                                                        className="font-medium text-foreground underline-offset-2 hover:underline"
                                                                        onClick={() =>
                                                                            setChosenVertical(
                                                                                alt.type as VerticalId,
                                                                            )
                                                                        }
                                                                    >
                                                                        {VERTICAL_BY_ID[
                                                                            alt.type as VerticalId
                                                                        ]
                                                                            ?.name ??
                                                                            alt.type}
                                                                    </button>
                                                                </span>
                                                            ))}
                                                    </p>
                                                )}
                                        </div>
                                    )}

                                <div className="mt-5">
                                    <p className="mb-2 text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                                        {t('Confirm or pick a different match')}
                                    </p>
                                    <VerticalRadioGrid
                                        value={chosenVertical}
                                        onChange={setChosenVertical}
                                        detectedId={detection.type}
                                    />
                                </div>

                                <div className="mt-6 flex items-center justify-between">
                                    <Button
                                        variant="ghost"
                                        onClick={() =>
                                            void applyVertical('generic')
                                        }
                                        disabled={applying}
                                    >
                                        {t("I'll configure later")}
                                    </Button>
                                    <Button
                                        onClick={() =>
                                            void applyVertical(
                                                chosenVertical ??
                                                    detection.type,
                                            )
                                        }
                                        disabled={applying || !chosenVertical}
                                    >
                                        {applying
                                            ? t('Applying…')
                                            : t('Use this preset')}
                                    </Button>
                                </div>
                            </>
                        )}
                    </Card>
                )}

                {step === 'pick' && discovered && (
                    <Card className="border-border/80 p-6">
                        <h1 className="text-xl font-semibold tracking-tight">
                            {discovered.total === 1
                                ? t('Found :count page', {
                                      count: discovered.total,
                                  })
                                : t('Found :count pages', {
                                      count: discovered.total,
                                  })}
                        </h1>
                        <p className="mt-1 text-sm text-muted-foreground">
                            {t(
                                'Pick the ones you want the agent to learn from. You can add more later.',
                            )}
                        </p>

                        <div className="mt-4 max-h-96 overflow-y-auto rounded-md border bg-muted/20">
                            {allUrls.length === 0 ? (
                                <p className="p-4 text-sm text-muted-foreground">
                                    {t(
                                        'No pages found from sitemap or common paths. You can paste URLs manually on the next page.',
                                    )}
                                </p>
                            ) : (
                                <ul className="divide-y text-sm">
                                    {allUrls.map((url) => (
                                        <li
                                            key={url}
                                            className="flex items-center gap-3 px-3 py-2"
                                        >
                                            <input
                                                type="checkbox"
                                                checked={picked[url] ?? false}
                                                className="size-3.5 rounded border-input accent-foreground"
                                                onChange={(e) =>
                                                    setPicked((p) => ({
                                                        ...p,
                                                        [url]: e.target.checked,
                                                    }))
                                                }
                                            />
                                            <span className="truncate">
                                                {url}
                                            </span>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </div>
                        <div className="mt-4 flex items-center justify-between">
                            <p className="text-xs text-muted-foreground">
                                {t(':count selected', { count: pickedCount })}
                            </p>
                            <div className="flex gap-2">
                                <Button
                                    variant="outline"
                                    onClick={() => setStep('connect')}
                                >
                                    {t('Back')}
                                </Button>
                                <Button
                                    onClick={addPicked}
                                    disabled={pickedCount === 0 || adding}
                                >
                                    {adding
                                        ? t('Adding…')
                                        : t('Add :count & continue', {
                                              count: pickedCount,
                                          })}
                                </Button>
                            </div>
                        </div>
                    </Card>
                )}

                {step === 'install' && (
                    <Card className="border-border/80 p-6">
                        <h1 className="text-xl font-semibold tracking-tight">
                            {t('Install on your site')}
                        </h1>
                        <p className="mt-1 text-sm text-muted-foreground">
                            {t('Paste this snippet just before')}{' '}
                            <code>&lt;/body&gt;</code>.{' '}
                            {t('The widget loads in under 50\u00a0KB.')}
                        </p>
                        <div className="mt-4 rounded-md border bg-muted/50 p-3 font-mono text-xs">
                            {snippet}
                        </div>
                        <div className="mt-3 flex gap-2">
                            <Button
                                variant="outline"
                                size="sm"
                                onClick={() => {
                                    void navigator.clipboard.writeText(snippet);
                                }}
                            >
                                <Copy className="size-4" /> {t('Copy snippet')}
                            </Button>
                        </div>

                        <div className="mt-6 rounded-md border bg-muted/20 p-4">
                            <p className="text-sm">
                                {agent.sources_count === 1
                                    ? t(':indexed of :total page indexed.', {
                                          indexed: agent.sources_indexed,
                                          total: agent.sources_count,
                                      })
                                    : t(':indexed of :total pages indexed.', {
                                          indexed: agent.sources_indexed,
                                          total: agent.sources_count,
                                      })}
                            </p>
                            {agent.sources_indexed < agent.sources_count && (
                                <p className="mt-1 flex items-center gap-2 text-xs text-muted-foreground">
                                    <Loader2 className="size-3 animate-spin" />{' '}
                                    {t(
                                        'Crawling in progress — refreshes every 3\u00a0seconds.',
                                    )}
                                </p>
                            )}
                        </div>

                        <div className="mt-4 flex justify-between">
                            <Button
                                variant="ghost"
                                onClick={() =>
                                    router.visit(
                                        agentsSourcesIndex(agent.id).url,
                                    )
                                }
                            >
                                {t('Add more sources')}
                            </Button>
                            <Button
                                onClick={() => router.visit(dashboard().url)}
                            >
                                <Check className="size-4" />{' '}
                                {t('All set — go to dashboard')}
                            </Button>
                        </div>
                    </Card>
                )}
            </div>
        </AppLayout>
    );
}

function StepDot({
    active,
    done,
    label,
}: {
    active: boolean;
    done: boolean;
    label: string;
}) {
    const tone = done
        ? 'bg-emerald-500/20 text-emerald-700 dark:text-emerald-400'
        : active
          ? 'bg-foreground text-background'
          : 'bg-muted text-muted-foreground';

    return <span className={`rounded-full px-3 py-1 ${tone}`}>{label}</span>;
}
