import { Head } from '@inertiajs/react';
import {
    CheckCheck,
    Copy,
    FlaskConical,
    Loader2,
    Maximize2,
    PanelRightClose,
    PanelRightOpen,
    RefreshCw,
    RotateCw,
    Send,
    Settings2,
    SquareSlash,
} from 'lucide-react';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { toast } from 'sonner';
import { MarkdownLite } from '@/components/markdown-lite';
import {
    DiagnosticsPanel,
    EMPTY_DIAGNOSTICS,
} from '@/components/playground/diagnostics-panel';
import type { DiagnosticsState } from '@/components/playground/diagnostics-panel';
import { InlineBlockList } from '@/components/playground/inline-blocks';
import type { InlineBlock } from '@/components/playground/inline-blocks';
import { PageContextForm } from '@/components/playground/page-context-form';
import { ScenarioPanel } from '@/components/playground/scenario-panel';
import type { VerticalOption } from '@/components/playground/scenario-panel';
import { SAMPLE_PROMPTS } from '@/components/playground/scenario-presets';
import type {
    PageContextPayload,
    ShopperSimulation,
} from '@/components/playground/scenario-presets';
import { consumePlaygroundStream } from '@/components/playground/sse';
import type { SseHandlers } from '@/components/playground/sse';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import {
    Sheet,
    SheetContent,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import AppLayout from '@/layouts/app-layout';
import { useT } from '@/lib/i18n';
import { VERTICAL_BY_ID } from '@/lib/verticals';
import type { VerticalId } from '@/lib/verticals';
import {
    index as agentsIndex,
    playground as agentPlayground,
    show as showAgent,
} from '@/routes/agents';
import type { BreadcrumbItem } from '@/types';

type Citation = { id: number; url: string | null };

type Cta = {
    label: string;
    kind: string;
    url?: string | null;
} | null;

type Message = {
    role: 'user' | 'assistant';
    content: string;
    citations?: Citation[];
    blocks?: InlineBlock[];
    pending?: boolean;
    lowConfidence?: boolean;
    error?: string;
    cta?: Cta;
    leadPrompt?: boolean;
    fromFlow?: boolean;
    fromCurated?: boolean;
};

type Props = {
    agent: {
        id: string;
        name: string;
        site_type: VerticalId | null;
        persona: { name?: string } | null;
        theme: Record<string, unknown> | null;
        language_default: string | null;
    };
    verticals: Record<string, VerticalOption>;
};

type RightPanelTab = 'scenario' | 'context' | 'diagnostics';

type Prefs = {
    siteTypeOverride: VerticalId | null;
    languageOverride: string | null;
    panelTab: RightPanelTab;
    panelOpen: boolean;
    compareEnabled: boolean;
    compareSiteType: VerticalId;
};

const DEFAULT_PREFS: Prefs = {
    siteTypeOverride: null,
    languageOverride: null,
    panelTab: 'scenario',
    panelOpen: true,
    compareEnabled: false,
    compareSiteType: 'ecommerce',
};

export default function Playground({ agent, verticals }: Props) {
    const { t } = useT();
    const prefsKey = `pg:${agent.id}:prefs`;
    const initialPrefs = useMemo(() => loadPrefs(prefsKey), [prefsKey]);

    const [input, setInput] = useState('');
    const [busy, setBusy] = useState(false);

    const [messages, setMessages] = useState<Message[]>([]);
    const [compareMessages, setCompareMessages] = useState<Message[]>([]);
    const [conversationId, setConversationId] = useState<string | null>(null);
    const [compareConversationId, setCompareConversationId] = useState<
        string | null
    >(null);

    const [siteTypeOverride, setSiteTypeOverride] = useState<VerticalId | null>(
        initialPrefs.siteTypeOverride,
    );
    const [languageOverride, setLanguageOverride] = useState<string | null>(
        initialPrefs.languageOverride,
    );
    const [pageContext, setPageContext] = useState<PageContextPayload>({});
    const [shopperSim, setShopperSim] = useState<ShopperSimulation>({
        enabled: false,
        wp_user_id: null,
        email: '',
    });

    const [compareEnabled, setCompareEnabled] = useState(
        initialPrefs.compareEnabled,
    );
    const [compareSiteType, setCompareSiteType] = useState<VerticalId>(
        initialPrefs.compareSiteType,
    );

    const [panelOpen, setPanelOpen] = useState(initialPrefs.panelOpen);
    const [panelTab, setPanelTab] = useState<RightPanelTab>(
        initialPrefs.panelTab,
    );
    const [mobilePanelOpen, setMobilePanelOpen] = useState(false);
    // Mobile compare mode: which column is visible. Both columns still
    // run streams in parallel; the UI just shows one at a time so chat
    // bubbles aren't squeezed into 50% of a 390px viewport.
    const [mobileCompareTab, setMobileCompareTab] = useState<'a' | 'b'>('a');

    const [diagnostics, setDiagnostics] =
        useState<DiagnosticsState>(EMPTY_DIAGNOSTICS);

    const [lastUserMessage, setLastUserMessage] = useState('');

    const chatScrollRef = useRef<HTMLDivElement | null>(null);
    const stickToBottomRef = useRef(true);
    const abortRef = useRef<AbortController | null>(null);

    // Persist UI prefs whenever they change. Per-agent because admins
    // often have a "site type override = ecommerce" set up for one
    // agent and a different config for another.
    useEffect(() => {
        savePrefs(prefsKey, {
            siteTypeOverride,
            languageOverride,
            panelTab,
            panelOpen,
            compareEnabled,
            compareSiteType,
        });
    }, [
        prefsKey,
        siteTypeOverride,
        languageOverride,
        panelTab,
        panelOpen,
        compareEnabled,
        compareSiteType,
    ]);

    const breadcrumbs: BreadcrumbItem[] = [
        { title: t('Agents'), href: agentsIndex.url() },
        { title: agent.name, href: showAgent({ agent: agent.id }).url },
        {
            title: t('Playground'),
            href: agentPlayground({ agent: agent.id }).url,
        },
    ];

    // Smart auto-scroll: only stick to the bottom when the admin hasn't
    // scrolled up to read. Track distance-from-bottom on scroll; if
    // >120px, treat them as "reading" and don't yank them down.
    const handleScroll = () => {
        const el = chatScrollRef.current;

        if (!el) {
            return;
        }

        const distanceFromBottom =
            el.scrollHeight - el.scrollTop - el.clientHeight;
        stickToBottomRef.current = distanceFromBottom < 120;
    };

    useEffect(() => {
        if (chatScrollRef.current && stickToBottomRef.current) {
            chatScrollRef.current.scrollTop =
                chatScrollRef.current.scrollHeight;
        }
    }, [messages, compareMessages]);

    const buildPayload = useCallback(
        (
            message: string,
            convId: string | null,
            overrideSiteType?: VerticalId,
        ): Record<string, unknown> => {
            const body: Record<string, unknown> = { message };

            if (convId) {
                body.conversation_id = convId;
            }

            const effectiveOverride =
                overrideSiteType ?? siteTypeOverride ?? undefined;

            if (effectiveOverride) {
                body.site_type_override = effectiveOverride;
            }

            if (languageOverride) {
                body.language_override = languageOverride;
            }

            const ctx = sanitizePageContext(pageContext);

            if (ctx) {
                body.page_context = ctx;
            }

            // Shopper simulation rides on the same request. Backend
            // mirrors the WP plugin's verified-token path so the
            // lookup_order tool sees `attribution.shopper.wp_user_id`
            // exactly as it would on a real WC-customer turn.
            if (
                shopperSim.enabled &&
                shopperSim.wp_user_id !== null &&
                shopperSim.email.trim() !== ''
            ) {
                body.shopper_simulation = {
                    wp_user_id: shopperSim.wp_user_id,
                    email: shopperSim.email.trim().toLowerCase(),
                };
            }

            return body;
        },
        [siteTypeOverride, languageOverride, pageContext, shopperSim],
    );

    const send = async (e?: React.FormEvent) => {
        if (e) {
            e.preventDefault();
        }

        const message = input.trim();

        if (!message || busy) {
            return;
        }

        setInput('');
        setLastUserMessage(message);
        stickToBottomRef.current = true;
        await runTurn(message);
    };

    const stop = () => {
        abortRef.current?.abort();
    };

    const regenerate = async () => {
        if (busy || !lastUserMessage) {
            return;
        }

        setMessages((prev) => popLastTurn(prev));

        if (compareEnabled) {
            setCompareMessages((prev) => popLastTurn(prev));
        }

        stickToBottomRef.current = true;
        await runTurn(lastUserMessage);
    };

    const reset = async () => {
        setMessages([]);
        setCompareMessages([]);
        setDiagnostics(EMPTY_DIAGNOSTICS);
        setLastUserMessage('');

        const csrf =
            (
                document.querySelector(
                    'meta[name="csrf-token"]',
                ) as HTMLMetaElement | null
            )?.content ?? '';

        const reqs: Promise<unknown>[] = [];

        if (conversationId) {
            reqs.push(
                fetch(`/app/agents/${agent.id}/playground/reset`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrf,
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    credentials: 'same-origin',
                    body: JSON.stringify({
                        conversation_id: conversationId,
                    }),
                }),
            );
        }

        if (compareConversationId) {
            reqs.push(
                fetch(`/app/agents/${agent.id}/playground/reset`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrf,
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    credentials: 'same-origin',
                    body: JSON.stringify({
                        conversation_id: compareConversationId,
                    }),
                }),
            );
        }

        await Promise.allSettled(reqs);
        setConversationId(null);
        setCompareConversationId(null);
    };

    const runTurn = async (message: string) => {
        setBusy(true);
        const startedAt = Date.now();
        const newDiag: DiagnosticsState = {
            ...EMPTY_DIAGNOSTICS,
            sources: [],
            toolCalls: [],
            blocks: [],
        };

        setMessages((prev) => [
            ...prev,
            { role: 'user', content: message },
            { role: 'assistant', content: '', pending: true, blocks: [] },
        ]);

        if (compareEnabled) {
            setCompareMessages((prev) => [
                ...prev,
                { role: 'user', content: message },
                { role: 'assistant', content: '', pending: true, blocks: [] },
            ]);
        }

        const controller = new AbortController();
        abortRef.current = controller;

        const mainHandlers: SseHandlers = {
            onStart: ({ conversation_id }) => {
                setConversationId(conversation_id);
            },
            onRetrieval: (payload) => {
                newDiag.sources = payload.sources;
                newDiag.confidence = payload.confidence;
                newDiag.threshold = payload.threshold;
                newDiag.pageUrlUsed = payload.page_url_used;
                setDiagnostics({ ...newDiag });
            },
            onPrompt: (payload) => {
                newDiag.systemPrompt = payload.system;
                newDiag.historyCount = payload.history_count;
                newDiag.vertical = payload.vertical;
                newDiag.language = payload.language;
                setDiagnostics({ ...newDiag });
            },
            onToken: (text) => {
                if (newDiag.firstTokenMs === null) {
                    newDiag.firstTokenMs = Date.now() - startedAt;
                    setDiagnostics({ ...newDiag });
                }

                appendTokenTo(setMessages, text);
            },
            onBlock: (block) => {
                newDiag.blocks = [...newDiag.blocks, { type: block.type }];
                setDiagnostics({ ...newDiag });
                appendBlockTo(setMessages, block as InlineBlock);
            },
            onToolCall: (event) => {
                newDiag.toolCalls = [
                    ...newDiag.toolCalls,
                    { name: event.name, args: event.args, ts: Date.now() },
                ];
                setDiagnostics({ ...newDiag });
            },
            onDone: (payload) => {
                newDiag.totalMs = Date.now() - startedAt;
                setDiagnostics({ ...newDiag });
                finalizeAssistantMessage(setMessages, payload);
            },
            onError: (err) => {
                finalizeError(setMessages, err);
            },
        };

        const compareHandlers: SseHandlers = compareEnabled
            ? {
                  onStart: ({ conversation_id }) => {
                      setCompareConversationId(conversation_id);
                  },
                  onToken: (text) => appendTokenTo(setCompareMessages, text),
                  onBlock: (block) =>
                      appendBlockTo(setCompareMessages, block as InlineBlock),
                  onDone: (payload) =>
                      finalizeAssistantMessage(setCompareMessages, payload),
                  onError: (err) => finalizeError(setCompareMessages, err),
              }
            : {};

        const requests: Promise<unknown>[] = [
            consumePlaygroundStream(
                `/app/agents/${agent.id}/playground/stream`,
                buildPayload(message, conversationId),
                mainHandlers,
                controller.signal,
            ),
        ];

        if (compareEnabled) {
            requests.push(
                consumePlaygroundStream(
                    `/app/agents/${agent.id}/playground/stream`,
                    buildPayload(
                        message,
                        compareConversationId,
                        compareSiteType,
                    ),
                    compareHandlers,
                    controller.signal,
                ),
            );
        }

        await Promise.allSettled(requests);
        abortRef.current = null;
        setBusy(false);
    };

    const onLaunchpadPrompt = (prompt: string) => {
        setInput(prompt);
    };

    const onCopyReply = (text: string) => {
        navigator.clipboard
            .writeText(text)
            .then(() => toast.success(t('Copied')))
            .catch(() => toast.error(t("Couldn't copy")));
    };

    const onEscalationClick = () => {
        toast(
            t(
                'In the live widget this opens the lead capture form so a human can pick up the conversation.',
            ),
            { duration: 5000 },
        );
    };

    const effectiveSiteType: VerticalId =
        siteTypeOverride ?? agent.site_type ?? 'generic';
    const overrideActive = siteTypeOverride !== null;

    const sidePanel = (
        <RightPanelTabs
            panelTab={panelTab}
            setPanelTab={setPanelTab}
            agent={agent}
            siteTypeOverride={siteTypeOverride}
            setSiteTypeOverride={setSiteTypeOverride}
            languageOverride={languageOverride}
            setLanguageOverride={setLanguageOverride}
            verticals={verticals}
            setInput={setInput}
            pageContext={pageContext}
            setPageContext={setPageContext}
            diagnostics={diagnostics}
            shopperSim={shopperSim}
            setShopperSim={setShopperSim}
        />
    );

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`${agent.name} · playground`} />
            <div className="flex flex-1 flex-col gap-3 p-3 md:p-4">
                <header className="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h1 className="flex items-center gap-2 text-2xl font-semibold tracking-tight">
                            <FlaskConical className="h-5 w-5" />
                            {t('Playground')}
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            {t(
                                "Test :name in any vertical, on any simulated page. Conversations here aren't counted toward your billing meter.",
                                { name: agent.name },
                            )}
                        </p>
                        <div className="mt-2 flex flex-wrap items-center gap-1.5">
                            <Badge
                                variant={
                                    overrideActive ? 'default' : 'secondary'
                                }
                                className="text-[11px]"
                            >
                                {t('Vertical:')}{' '}
                                {t(VERTICAL_BY_ID[effectiveSiteType].name)}
                                {overrideActive ? ' ' + t('(override)') : ''}
                            </Badge>
                            {languageOverride && (
                                <Badge
                                    variant="default"
                                    className="text-[11px]"
                                >
                                    {t('Language: :lang (override)', {
                                        lang: languageOverride.toUpperCase(),
                                    })}
                                </Badge>
                            )}
                            {Object.keys(sanitizePageContext(pageContext) ?? {})
                                .length > 0 && (
                                <Badge
                                    variant="default"
                                    className="text-[11px]"
                                >
                                    {t('Page context:')}{' '}
                                    {pageContext.url
                                        ? safeHost(pageContext.url)
                                        : t('set')}
                                </Badge>
                            )}
                        </div>
                    </div>
                    <div className="flex flex-wrap items-center gap-2">
                        <Button
                            type="button"
                            variant={compareEnabled ? 'default' : 'outline'}
                            size="sm"
                            onClick={() => setCompareEnabled((v) => !v)}
                            title={t('Compare two verticals side-by-side')}
                        >
                            <Maximize2 className="h-3.5 w-3.5 sm:me-1.5" />
                            <span className="hidden sm:inline">
                                {t('Compare')}
                            </span>
                        </Button>
                        {busy ? (
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                onClick={stop}
                                title={t('Stop streaming')}
                            >
                                <SquareSlash className="h-3.5 w-3.5 sm:me-1.5" />
                                <span className="hidden sm:inline">
                                    {t('Stop')}
                                </span>
                            </Button>
                        ) : (
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                onClick={regenerate}
                                disabled={!lastUserMessage}
                                title={t('Re-run the last user message')}
                            >
                                <RotateCw className="h-3.5 w-3.5 sm:me-1.5" />
                                <span className="hidden sm:inline">
                                    {t('Regenerate')}
                                </span>
                            </Button>
                        )}
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            onClick={reset}
                            disabled={busy}
                            title={t('Wipe conversation history')}
                        >
                            <RefreshCw className="h-3.5 w-3.5 sm:me-1.5" />
                            <span className="hidden sm:inline">
                                {t('Reset')}
                            </span>
                        </Button>
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            onClick={() => setPanelOpen((v) => !v)}
                            title={
                                panelOpen
                                    ? t('Hide side panel')
                                    : t('Show side panel')
                            }
                            className="hidden md:inline-flex"
                        >
                            {panelOpen ? (
                                <PanelRightClose className="h-4 w-4" />
                            ) : (
                                <PanelRightOpen className="h-4 w-4" />
                            )}
                        </Button>
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            onClick={() => setMobilePanelOpen(true)}
                            className="md:hidden"
                            title={t('Open scenario / context / diagnostics')}
                        >
                            <Settings2 className="h-3.5 w-3.5 sm:me-1.5" />
                            <span className="hidden sm:inline">
                                {t('Tools')}
                            </span>
                        </Button>
                    </div>
                </header>

                <div className="flex flex-1 gap-4">
                    <Card className="flex flex-1 flex-col overflow-hidden">
                        {compareEnabled && (
                            <>
                                {/* Desktop side-by-side header: column boundaries
                                    line up with the grid below. */}
                                <div className="hidden md:block">
                                    <CompareHeader
                                        left={effectiveSiteType}
                                        right={compareSiteType}
                                        onChangeRight={setCompareSiteType}
                                        verticals={verticals}
                                    />
                                </div>
                                {/* Mobile: A/B tabs swap which column is on
                                    screen. Both columns still stream in
                                    parallel — just one is visible. Stops the
                                    chat from being squeezed into ~180px of
                                    horizontal space. */}
                                <CompareHeaderMobile
                                    activeTab={mobileCompareTab}
                                    onChangeTab={setMobileCompareTab}
                                    left={effectiveSiteType}
                                    right={compareSiteType}
                                    onChangeRight={setCompareSiteType}
                                    verticals={verticals}
                                />
                            </>
                        )}
                        <div
                            ref={chatScrollRef}
                            onScroll={handleScroll}
                            className="flex-1 overflow-y-auto"
                            role="log"
                        >
                            {compareEnabled ? (
                                <>
                                    <div className="hidden h-full grid-cols-2 divide-x md:grid">
                                        <ChatColumn
                                            messages={messages}
                                            verticalLabel={t(':name (main)', {
                                                name: t(
                                                    VERTICAL_BY_ID[
                                                        effectiveSiteType
                                                    ].name,
                                                ),
                                            })}
                                            emptyVertical={effectiveSiteType}
                                            onPrompt={onLaunchpadPrompt}
                                            onCopyReply={onCopyReply}
                                            onEscalationClick={
                                                onEscalationClick
                                            }
                                            compareSide
                                        />
                                        <ChatColumn
                                            messages={compareMessages}
                                            verticalLabel={t(
                                                ':name (compare)',
                                                {
                                                    name: t(
                                                        VERTICAL_BY_ID[
                                                            compareSiteType
                                                        ].name,
                                                    ),
                                                },
                                            )}
                                            emptyVertical={compareSiteType}
                                            onPrompt={onLaunchpadPrompt}
                                            onCopyReply={onCopyReply}
                                            onEscalationClick={
                                                onEscalationClick
                                            }
                                            compareSide
                                        />
                                    </div>
                                    <div className="md:hidden">
                                        {mobileCompareTab === 'a' ? (
                                            <ChatColumn
                                                messages={messages}
                                                emptyVertical={
                                                    effectiveSiteType
                                                }
                                                onPrompt={onLaunchpadPrompt}
                                                onCopyReply={onCopyReply}
                                                onEscalationClick={
                                                    onEscalationClick
                                                }
                                            />
                                        ) : (
                                            <ChatColumn
                                                messages={compareMessages}
                                                emptyVertical={compareSiteType}
                                                onPrompt={onLaunchpadPrompt}
                                                onCopyReply={onCopyReply}
                                                onEscalationClick={
                                                    onEscalationClick
                                                }
                                            />
                                        )}
                                    </div>
                                </>
                            ) : (
                                <ChatColumn
                                    messages={messages}
                                    emptyVertical={effectiveSiteType}
                                    onPrompt={onLaunchpadPrompt}
                                    onCopyReply={onCopyReply}
                                    onEscalationClick={onEscalationClick}
                                />
                            )}
                        </div>
                        <form
                            onSubmit={send}
                            className="flex items-center gap-2 border-t p-3"
                        >
                            <Input
                                type="text"
                                value={input}
                                onChange={(e) => setInput(e.target.value)}
                                placeholder={t('Ask anything…')}
                                className="flex-1"
                                disabled={busy}
                                autoFocus
                            />
                            <Button type="submit" disabled={busy}>
                                {busy ? (
                                    <Loader2 className="h-4 w-4 animate-spin" />
                                ) : (
                                    <Send className="h-4 w-4" />
                                )}
                            </Button>
                        </form>
                    </Card>

                    {panelOpen && (
                        <Card className="hidden w-[380px] shrink-0 flex-col md:flex">
                            {sidePanel}
                        </Card>
                    )}
                </div>
            </div>

            <Sheet open={mobilePanelOpen} onOpenChange={setMobilePanelOpen}>
                <SheetContent
                    side="right"
                    className="w-full max-w-[420px] p-0 sm:max-w-[420px]"
                >
                    <SheetHeader className="border-b">
                        <SheetTitle>{t('Playground tools')}</SheetTitle>
                    </SheetHeader>
                    {sidePanel}
                </SheetContent>
            </Sheet>
        </AppLayout>
    );
}

function RightPanelTabs({
    panelTab,
    setPanelTab,
    agent,
    siteTypeOverride,
    setSiteTypeOverride,
    languageOverride,
    setLanguageOverride,
    verticals,
    setInput,
    pageContext,
    setPageContext,
    diagnostics,
    shopperSim,
    setShopperSim,
}: {
    panelTab: RightPanelTab;
    setPanelTab: (tab: RightPanelTab) => void;
    agent: Props['agent'];
    siteTypeOverride: VerticalId | null;
    setSiteTypeOverride: (v: VerticalId | null) => void;
    languageOverride: string | null;
    setLanguageOverride: (v: string | null) => void;
    verticals: Record<string, VerticalOption>;
    setInput: (v: string) => void;
    pageContext: PageContextPayload;
    setPageContext: (next: PageContextPayload) => void;
    diagnostics: DiagnosticsState;
    shopperSim: ShopperSimulation;
    setShopperSim: (next: ShopperSimulation) => void;
}) {
    const { t } = useT();

    return (
        <>
            <ToggleGroup
                type="single"
                value={panelTab}
                onValueChange={(v) => {
                    if (v) {
                        setPanelTab(v as RightPanelTab);
                    }
                }}
                className="border-b p-1"
            >
                <ToggleGroupItem value="scenario" className="flex-1">
                    {t('Scenario')}
                </ToggleGroupItem>
                <ToggleGroupItem value="context" className="flex-1">
                    {t('Page context')}
                </ToggleGroupItem>
                <ToggleGroupItem value="diagnostics" className="flex-1">
                    {t('Diagnostics')}
                </ToggleGroupItem>
            </ToggleGroup>
            <div className="flex-1 overflow-y-auto p-4">
                {panelTab === 'scenario' && (
                    <ScenarioPanel
                        agentSiteType={agent.site_type}
                        siteTypeOverride={siteTypeOverride}
                        onSiteTypeOverride={setSiteTypeOverride}
                        languageOverride={languageOverride}
                        onLanguageOverride={setLanguageOverride}
                        verticals={verticals}
                        onPickPrompt={(p) => setInput(p)}
                        shopperSim={shopperSim}
                        onShopperSimChange={setShopperSim}
                    />
                )}
                {panelTab === 'context' && (
                    <PageContextForm
                        value={pageContext}
                        onChange={setPageContext}
                    />
                )}
                {panelTab === 'diagnostics' && (
                    <DiagnosticsPanel state={diagnostics} />
                )}
            </div>
        </>
    );
}

function ChatColumn({
    messages,
    emptyVertical,
    onPrompt,
    onCopyReply,
    onEscalationClick,
    verticalLabel,
    compareSide = false,
}: {
    messages: Message[];
    emptyVertical: VerticalId;
    onPrompt: (prompt: string) => void;
    onCopyReply: (text: string) => void;
    onEscalationClick: () => void;
    verticalLabel?: string;
    compareSide?: boolean;
}) {
    if (messages.length === 0) {
        return (
            <EmptyLaunchpad
                vertical={emptyVertical}
                onPrompt={onPrompt}
                verticalLabel={verticalLabel}
            />
        );
    }

    return (
        <div className={`flex flex-col gap-3 ${compareSide ? 'p-3' : 'p-4'}`}>
            {messages.map((m, i) => (
                <MessageBubble
                    key={i}
                    message={m}
                    onCopyReply={onCopyReply}
                    onEscalationClick={onEscalationClick}
                />
            ))}
        </div>
    );
}

function EmptyLaunchpad({
    vertical,
    onPrompt,
    verticalLabel,
}: {
    vertical: VerticalId;
    onPrompt: (prompt: string) => void;
    verticalLabel?: string;
}) {
    const { t } = useT();
    const prompts = SAMPLE_PROMPTS[vertical] ?? [];

    return (
        <div className="flex h-full flex-col items-center justify-center gap-4 p-6">
            <div className="flex flex-col items-center gap-1.5 text-center">
                <FlaskConical className="h-7 w-7 text-muted-foreground" />
                <p className="text-sm font-medium">
                    {verticalLabel
                        ? t('Try :label', { label: verticalLabel })
                        : t('Try :name', {
                              name: t(VERTICAL_BY_ID[vertical].name),
                          })}
                </p>
                <p className="text-xs text-muted-foreground">
                    {t(
                        'Pick a sample prompt to get started, or type anything below.',
                    )}
                </p>
            </div>
            <div className="grid w-full max-w-md grid-cols-1 gap-2 sm:grid-cols-2">
                {prompts.slice(0, 4).map((prompt) => (
                    <button
                        key={prompt}
                        type="button"
                        onClick={() => onPrompt(prompt)}
                        className="rounded-lg border bg-card px-3 py-2 text-start text-xs leading-snug shadow-sm transition hover:border-primary/50 hover:bg-muted"
                    >
                        {prompt}
                    </button>
                ))}
            </div>
        </div>
    );
}

function MessageBubble({
    message,
    onCopyReply,
    onEscalationClick,
}: {
    message: Message;
    onCopyReply: (text: string) => void;
    onEscalationClick: () => void;
}) {
    const { t } = useT();
    const [copied, setCopied] = useState(false);

    if (message.role === 'user') {
        return (
            <div className="max-w-[85%] self-end rounded-2xl bg-foreground px-4 py-2 text-sm text-background">
                {message.content}
            </div>
        );
    }

    const handleCopy = () => {
        onCopyReply(message.content);
        setCopied(true);
        window.setTimeout(() => setCopied(false), 1500);
    };

    return (
        <div className="group/msg max-w-[85%] self-start">
            <div
                className={`relative rounded-2xl px-4 py-2 text-sm leading-relaxed ${message.error ? 'bg-destructive/10 text-destructive' : 'bg-muted'}`}
            >
                {message.pending && message.content === '' ? (
                    <span className="inline-flex items-center gap-1.5 italic opacity-70">
                        <Loader2 className="h-3 w-3 animate-spin" />
                        {t('thinking…')}
                    </span>
                ) : message.error ? (
                    <p className="text-xs">{message.error}</p>
                ) : (
                    <MarkdownLite markdown={message.content} variant="chat" />
                )}
                {!message.pending && message.content && !message.error && (
                    <button
                        type="button"
                        onClick={handleCopy}
                        title={t('Copy reply')}
                        className="absolute -end-2 -top-2 rounded-full border bg-background p-1 opacity-0 shadow-sm transition group-hover/msg:opacity-100 hover:bg-muted"
                    >
                        {copied ? (
                            <CheckCheck className="h-3.5 w-3.5 text-emerald-600" />
                        ) : (
                            <Copy className="h-3.5 w-3.5" />
                        )}
                    </button>
                )}
            </div>
            {message.blocks && message.blocks.length > 0 && (
                <InlineBlockList
                    blocks={message.blocks}
                    onEscalationClick={onEscalationClick}
                />
            )}
            {message.cta && (
                <div className="mt-2">
                    <Button
                        type="button"
                        size="sm"
                        variant="default"
                        asChild={!!message.cta.url}
                    >
                        {message.cta.url ? (
                            <a
                                href={message.cta.url}
                                target="_blank"
                                rel="noopener noreferrer"
                            >
                                {message.cta.label}
                            </a>
                        ) : (
                            <span>{message.cta.label}</span>
                        )}
                    </Button>
                </div>
            )}
            {message.leadPrompt && (
                <p className="mt-2 rounded-md border border-dashed border-amber-300 bg-amber-50 px-2.5 py-1.5 text-xs text-amber-800">
                    {t('Visitor would now see the lead-capture form.')}
                </p>
            )}
            {message.citations && message.citations.length > 0 && (
                <p className="mt-2 text-[11px] text-muted-foreground">
                    {t('Sources:')}{' '}
                    {message.citations.map((c) => (
                        <a
                            key={c.id}
                            href={c.url ?? '#'}
                            target="_blank"
                            rel="noopener noreferrer"
                            className="underline-offset-2 hover:underline"
                        >
                            [{c.id}]{' '}
                        </a>
                    ))}
                </p>
            )}
            <div className="mt-1 flex flex-wrap items-center gap-1">
                {message.lowConfidence && !message.pending && (
                    <Badge
                        variant="outline"
                        className="text-[10px] text-amber-600"
                    >
                        {t('Low confidence')}
                    </Badge>
                )}
                {message.fromFlow && (
                    <Badge
                        variant="outline"
                        className="text-[10px] text-indigo-600"
                    >
                        {t('Workflow')}
                    </Badge>
                )}
                {message.fromCurated && (
                    <Badge
                        variant="outline"
                        className="text-[10px] text-emerald-600"
                    >
                        {t('Curated answer')}
                    </Badge>
                )}
            </div>
        </div>
    );
}

function CompareHeaderMobile({
    activeTab,
    onChangeTab,
    left,
    right,
    onChangeRight,
    verticals,
}: {
    activeTab: 'a' | 'b';
    onChangeTab: (tab: 'a' | 'b') => void;
    left: VerticalId;
    right: VerticalId;
    onChangeRight: (v: VerticalId) => void;
    verticals: Record<string, VerticalOption>;
}) {
    const { t } = useT();

    return (
        <div className="border-b bg-muted/40 md:hidden">
            <ToggleGroup
                type="single"
                value={activeTab}
                onValueChange={(v) => v && onChangeTab(v as 'a' | 'b')}
                className="p-1"
            >
                <ToggleGroupItem value="a" className="flex-1 text-xs">
                    {t('A · :name', { name: t(VERTICAL_BY_ID[left].name) })}
                </ToggleGroupItem>
                <ToggleGroupItem value="b" className="flex-1 text-xs">
                    {t('B · :name', { name: t(VERTICAL_BY_ID[right].name) })}
                </ToggleGroupItem>
            </ToggleGroup>
            {activeTab === 'b' && (
                <div className="flex items-center gap-2 border-t px-2 py-1.5 text-xs">
                    <span className="text-muted-foreground">
                        {t('Compare with:')}
                    </span>
                    <select
                        value={right}
                        onChange={(e) =>
                            onChangeRight(e.target.value as VerticalId)
                        }
                        className="flex-1 rounded-md border bg-background px-1.5 py-0.5 text-xs font-semibold"
                    >
                        {Object.values(verticals)
                            .filter((v) => v.slug !== 'generic')
                            .map((v) => (
                                <option key={v.slug} value={v.slug}>
                                    {t(VERTICAL_BY_ID[v.slug].name)}
                                </option>
                            ))}
                    </select>
                </div>
            )}
        </div>
    );
}

function CompareHeader({
    left,
    right,
    onChangeRight,
    verticals,
}: {
    left: VerticalId;
    right: VerticalId;
    onChangeRight: (v: VerticalId) => void;
    verticals: Record<string, VerticalOption>;
}) {
    const { t } = useT();

    return (
        <div className="grid grid-cols-2 divide-x border-b bg-muted/40">
            <div className="flex items-center gap-2 p-2 text-xs">
                <span className="rounded bg-foreground/10 px-1.5 py-0.5 font-mono text-[10px] tracking-wide uppercase">
                    A
                </span>
                <span className="font-semibold">
                    {t(VERTICAL_BY_ID[left].name)}
                </span>
                <span className="text-muted-foreground">{t('main')}</span>
            </div>
            <div className="flex items-center gap-2 p-2 text-xs">
                <span className="rounded bg-foreground/10 px-1.5 py-0.5 font-mono text-[10px] tracking-wide uppercase">
                    B
                </span>
                <select
                    value={right}
                    onChange={(e) =>
                        onChangeRight(e.target.value as VerticalId)
                    }
                    className="rounded-md border bg-background px-1.5 py-0.5 text-xs font-semibold"
                >
                    {Object.values(verticals)
                        .filter((v) => v.slug !== 'generic')
                        .map((v) => (
                            <option key={v.slug} value={v.slug}>
                                {t(VERTICAL_BY_ID[v.slug].name)}
                            </option>
                        ))}
                </select>
                <span className="text-muted-foreground">{t('compare')}</span>
            </div>
        </div>
    );
}

function appendTokenTo(
    setter: React.Dispatch<React.SetStateAction<Message[]>>,
    text: string,
) {
    setter((prev) => {
        const next = [...prev];
        const last = next[next.length - 1];

        if (last && last.role === 'assistant') {
            next[next.length - 1] = {
                ...last,
                content: last.content + text,
                pending: true,
            };
        }

        return next;
    });
}

function appendBlockTo(
    setter: React.Dispatch<React.SetStateAction<Message[]>>,
    block: InlineBlock,
) {
    setter((prev) => {
        const next = [...prev];
        const last = next[next.length - 1];

        if (last && last.role === 'assistant') {
            next[next.length - 1] = {
                ...last,
                blocks: [...(last.blocks ?? []), block],
            };
        }

        return next;
    });
}

function finalizeAssistantMessage(
    setter: React.Dispatch<React.SetStateAction<Message[]>>,
    payload: {
        text: string;
        citations: { id: number; url: string | null }[];
        low_confidence: boolean;
        cta?: Cta;
        lead_prompt?: boolean;
        flow?: boolean;
        curated?: boolean;
    },
) {
    setter((prev) => {
        const next = [...prev];
        const last = next[next.length - 1];

        if (last && last.role === 'assistant') {
            next[next.length - 1] = {
                ...last,
                content: payload.text || last.content,
                citations: payload.citations,
                lowConfidence: payload.low_confidence,
                pending: false,
                cta: payload.cta ?? null,
                leadPrompt: !!payload.lead_prompt,
                fromFlow: !!payload.flow,
                fromCurated: !!payload.curated,
            };
        }

        return next;
    });
}

function finalizeError(
    setter: React.Dispatch<React.SetStateAction<Message[]>>,
    err: { code: string; message?: string },
) {
    setter((prev) => {
        const next = [...prev];
        const last = next[next.length - 1];

        if (last && last.role === 'assistant') {
            next[next.length - 1] = {
                ...last,
                content: '',
                pending: false,
                error: err.message ?? `Error: ${err.code}`,
            };
        }

        return next;
    });
}

function popLastTurn(prev: Message[]): Message[] {
    const next = [...prev];

    while (next.length > 0 && next[next.length - 1]!.role !== 'user') {
        next.pop();
    }

    if (next.length > 0 && next[next.length - 1]!.role === 'user') {
        next.pop();
    }

    return next;
}

function sanitizePageContext(
    ctx: PageContextPayload,
): PageContextPayload | null {
    const out: Record<string, unknown> = {};

    for (const [key, value] of Object.entries(ctx)) {
        if (value === undefined || value === null) {
            continue;
        }

        if (typeof value === 'string' && value.trim() === '') {
            continue;
        }

        if (
            Array.isArray(value) &&
            value.every((v) => typeof v === 'string' && v.trim() === '')
        ) {
            continue;
        }

        if (
            typeof value === 'object' &&
            !Array.isArray(value) &&
            Object.keys(value as Record<string, unknown>).length === 0
        ) {
            continue;
        }

        out[key] = value;
    }

    return Object.keys(out).length === 0 ? null : (out as PageContextPayload);
}

function safeHost(url: string): string {
    try {
        return new URL(url).host;
    } catch {
        return 'set'; // dev-only fallback; UI displays t('set') when this returns
    }
}

function loadPrefs(key: string): Prefs {
    if (typeof window === 'undefined') {
        return DEFAULT_PREFS;
    }

    try {
        const raw = window.localStorage.getItem(key);

        if (!raw) {
            return DEFAULT_PREFS;
        }

        const parsed = JSON.parse(raw);

        return { ...DEFAULT_PREFS, ...parsed } as Prefs;
    } catch {
        return DEFAULT_PREFS;
    }
}

function savePrefs(key: string, prefs: Prefs): void {
    if (typeof window === 'undefined') {
        return;
    }

    try {
        window.localStorage.setItem(key, JSON.stringify(prefs));
    } catch {
        // Storage full / disabled — silently ignore. Prefs come back to
        // defaults on reload, no functional impact.
    }
}
