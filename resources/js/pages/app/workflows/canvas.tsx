import { Head, Link, router } from '@inertiajs/react';
import {
    addEdge,
    Background,
    Controls,
    MiniMap,
    ReactFlow,
    ReactFlowProvider,
    useEdgesState,
    useNodesState,
    useReactFlow,
} from '@xyflow/react';
import type { Connection, Edge, Node } from '@xyflow/react';
import '@xyflow/react/dist/style.css';
import {
    AlertTriangle,
    Copy,
    FlaskConical,
    Plus,
    Redo2,
    Save,
    Undo2,
    Wand2,
} from 'lucide-react';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { useConfirm } from '@/components/confirm-dialog-provider';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { useT } from '@/lib/i18n';
import {
    edit as workflowsEdit,
    store as workflowsStore,
    update as workflowsUpdate,
} from '@/routes/workflows';
import type { BreadcrumbItem } from '@/types';
import { NodeInspector } from './canvas-internals/inspector';
import { tidyLayout } from './canvas-internals/layout';
import { NODE_TYPE_ICONS, NODE_TYPES } from './canvas-internals/nodes';
import { TestRunPanel } from './canvas-internals/test-run';
import {
    canvasStepOrder,
    canvasToSteps,
    stepsToCanvas,
} from './canvas-internals/translator';
import type {
    AgentOption,
    CanvasEdge,
    CanvasNode,
    StepType,
    WorkflowProp,
} from './canvas-internals/types';
import { computeProblems } from './canvas-internals/validation';

type Props = {
    /** null = canvas-first creation (?canvas=1) — nothing saved yet. */
    workflow: WorkflowProp | null;
    agents: AgentOption[];
};

const HISTORY_LIMIT = 50;
const DRAG_MIME = 'application/pitchbar-step';

type Snapshot = { nodes: Node[]; edges: Edge[] };

/**
 * Module-scope id mint — unique across rapid add/duplicate in the same
 * millisecond, and outside the component so the compiler purity lint
 * stays satisfied.
 */
let stepSeq = 0;

function freshStepId(): string {
    stepSeq += 1;

    return `step-${Date.now().toString(36)}-${stepSeq}`;
}

export default function WorkflowCanvas({ workflow }: Props) {
    return (
        <ReactFlowProvider>
            <CanvasInner workflow={workflow} />
        </ReactFlowProvider>
    );
}

function CanvasInner({ workflow }: { workflow: WorkflowProp | null }) {
    const { t } = useT();
    const { screenToFlowPosition } = useReactFlow();
    const confirmDialog = useConfirm();

    const palette: Array<{ type: StepType; label: string }> = [
        { type: 'message', label: t('Message') },
        { type: 'question', label: t('Question') },
        { type: 'branch', label: t('Branch') },
        { type: 'tag_lead', label: t('Tag lead') },
        { type: 'webhook', label: t('Webhook') },
        { type: 'escalate', label: t('Escalate') },
    ];

    const breadcrumbs: BreadcrumbItem[] = [
        { title: t('Workflows'), href: '/app/workflows' },
        workflow
            ? {
                  title: workflow.name,
                  href: `/app/workflows/${workflow.id}/edit`,
              }
            : {
                  title: t('New workflow'),
                  href: '/app/workflows/create?canvas=1',
              },
        { title: t('Canvas'), href: '#' },
    ];

    // Initial state — prefer the persisted canvas if present; otherwise
    // re-derive from the linear `steps[]` array so a flow created in
    // the form view opens cleanly here. Create mode starts empty.
    const initial = useMemo(() => {
        if (!workflow) {
            return { nodes: [] as CanvasNode[], edges: [] as CanvasEdge[] };
        }

        const persisted = workflow.definition?.canvas;

        if (persisted && persisted.nodes && persisted.nodes.length > 0) {
            return persisted;
        }

        return stepsToCanvas(workflow.steps);
    }, [workflow]);

    // The trigger is rendered as a synthetic top node carrying the
    // trigger config; React Flow needs all nodes including it. It can
    // never be deleted — the flow always starts somewhere.
    const triggerNode = {
        id: 'trigger',
        type: 'trigger' as StepType,
        position: { x: 60, y: 0 },
        deletable: false,
        data: {
            keywords: workflow?.keywords ?? [],
            match_mode: workflow?.match_mode ?? 'any',
        },
    } as unknown as Node;

    const [nodes, setNodes, onNodesChange] = useNodesState<Node>([
        triggerNode,
        ...(initial.nodes.filter((n) => n.id !== 'trigger') as Node[]),
    ]);
    const [edges, setEdges, onEdgesChange] = useEdgesState<Edge>(
        initial.edges as Edge[],
    );

    const [selectedNodeId, setSelectedNodeId] = useState<string | null>(null);
    const [keywords, setKeywords] = useState<string[]>(
        workflow?.keywords ?? [],
    );
    const [matchMode, setMatchMode] = useState<'any' | 'all' | 'exact'>(
        workflow?.match_mode ?? 'any',
    );
    const [name, setName] = useState(workflow?.name ?? t('Untitled workflow'));
    const [status, setStatus] = useState<'draft' | 'active' | 'disabled'>(
        workflow?.status ?? 'draft',
    );
    const [saving, setSaving] = useState(false);
    const [saveError, setSaveError] = useState<string | null>(null);
    const [rightPane, setRightPane] = useState<'inspector' | 'test'>(
        'inspector',
    );
    const [problemsOpen, setProblemsOpen] = useState(false);
    const [traceIds, setTraceIds] = useState<Set<string>>(new Set());

    // ── History (graph-level undo/redo) ─────────────────────────────
    const pastRef = useRef<Snapshot[]>([]);
    const futureRef = useRef<Snapshot[]>([]);
    // Stack depths mirrored into state — refs must not be read during
    // render, and the Undo/Redo buttons need to re-render on change.
    const [historyDepth, setHistoryDepth] = useState({ past: 0, future: 0 });
    const dirtyRef = useRef(false);
    const savingRef = useRef(false);
    const leaveConfirmedRef = useRef(false);
    const lastInspectorEditRef = useRef<string | null>(null);
    const dragSnapshotRef = useRef<Snapshot | null>(null);

    const markDirty = () => {
        dirtyRef.current = true;
    };

    const takeSnapshot = useCallback(
        (): Snapshot => ({
            nodes: JSON.parse(JSON.stringify(nodes)) as Node[],
            edges: JSON.parse(JSON.stringify(edges)) as Edge[],
        }),
        [nodes, edges],
    );

    const syncHistoryDepth = () => {
        setHistoryDepth({
            past: pastRef.current.length,
            future: futureRef.current.length,
        });
    };

    const pushHistory = useCallback(
        (snapshot?: Snapshot) => {
            pastRef.current = [
                ...pastRef.current.slice(-(HISTORY_LIMIT - 1)),
                snapshot ?? takeSnapshot(),
            ];
            futureRef.current = [];
            syncHistoryDepth();
        },
        [takeSnapshot],
    );

    const undo = useCallback(() => {
        const prev = pastRef.current.pop();

        if (!prev) {
            return;
        }

        futureRef.current.push({
            nodes: JSON.parse(JSON.stringify(nodes)) as Node[],
            edges: JSON.parse(JSON.stringify(edges)) as Edge[],
        });
        setNodes(prev.nodes);
        setEdges(prev.edges);
        syncHistoryDepth();
        markDirty();
    }, [nodes, edges, setNodes, setEdges]);

    const redo = useCallback(() => {
        const next = futureRef.current.pop();

        if (!next) {
            return;
        }

        pastRef.current.push({
            nodes: JSON.parse(JSON.stringify(nodes)) as Node[],
            edges: JSON.parse(JSON.stringify(edges)) as Edge[],
        });
        setNodes(next.nodes);
        setEdges(next.edges);
        syncHistoryDepth();
        markDirty();
    }, [nodes, edges, setNodes, setEdges]);

    // Ctrl/Cmd+Z, Ctrl/Cmd+Shift+Z — skipped while typing in a field.
    useEffect(() => {
        const handler = (e: KeyboardEvent) => {
            const target = e.target as HTMLElement | null;

            if (
                target &&
                (target.tagName === 'INPUT' ||
                    target.tagName === 'TEXTAREA' ||
                    target.isContentEditable)
            ) {
                return;
            }

            if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === 'z') {
                e.preventDefault();

                if (e.shiftKey) {
                    redo();
                } else {
                    undo();
                }
            }
        };
        window.addEventListener('keydown', handler);

        return () => window.removeEventListener('keydown', handler);
    }, [undo, redo]);

    // ── Unsaved-changes guard ───────────────────────────────────────
    // Refs (not state) feed both listeners so the effect mounts once.
    // Inertia's `before` event is synchronous while the shadcn confirm
    // dialog is Promise-based, so the pattern is: block the visit,
    // ask, then replay the (GET) navigation with a bypass flag.
    useEffect(() => {
        const beforeUnload = (e: BeforeUnloadEvent) => {
            if (dirtyRef.current && !savingRef.current) {
                e.preventDefault();
            }
        };
        window.addEventListener('beforeunload', beforeUnload);
        const off = router.on('before', (event) => {
            const visit = event.detail.visit;

            if (
                !dirtyRef.current ||
                savingRef.current ||
                leaveConfirmedRef.current ||
                visit.method !== 'get'
            ) {
                return;
            }

            event.preventDefault();
            void (async () => {
                const ok = await confirmDialog({
                    title: t('Leave the canvas?'),
                    message: t(
                        'You have unsaved canvas changes. Leave anyway?',
                    ),
                    confirmLabel: t('Leave'),
                    danger: true,
                });

                if (ok) {
                    leaveConfirmedRef.current = true;
                    router.visit(visit.url.href);
                }
            })();
        });

        return () => {
            window.removeEventListener('beforeunload', beforeUnload);
            off();
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    const selectedNode =
        selectedNodeId !== null
            ? (nodes.find((n) => n.id === selectedNodeId) as
                  | CanvasNode
                  | undefined)
            : null;

    // ── Live validation ─────────────────────────────────────────────
    const problems = useMemo(
        () =>
            computeProblems(
                nodes as unknown as CanvasNode[],
                edges as unknown as CanvasEdge[],
                keywords,
                t,
            ),
        [nodes, edges, keywords, t],
    );
    const problemNodeIds = useMemo(
        () => new Set(problems.map((p) => p.nodeId)),
        [problems],
    );

    // Decorated copies for rendering only — problem ring + trace glow
    // ride className so the node components stay untouched.
    const renderNodes = useMemo(
        () =>
            nodes.map((n) => ({
                ...n,
                deletable: n.id !== 'trigger',
                className: [
                    problemNodeIds.has(n.id) ? 'pb-problem' : '',
                    traceIds.has(n.id) ? 'pb-trace' : '',
                ]
                    .filter(Boolean)
                    .join(' '),
            })),
        [nodes, problemNodeIds, traceIds],
    );

    const edgesForExport = () =>
        edges.map((e) => ({
            id: e.id,
            source: e.source,
            target: e.target,
            sourceHandle: e.sourceHandle ?? undefined,
        })) as CanvasEdge[];

    const onConnect = useCallback(
        (params: Connection) => {
            pushHistory();
            markDirty();
            setEdges((eds) => addEdge(params, eds));
        },
        [setEdges, pushHistory],
    );

    const addNodeAt = (type: StepType, position: { x: number; y: number }) => {
        pushHistory();
        markDirty();
        const id = freshStepId();
        // `selected` keeps React Flow's internal selection in lockstep
        // with the inspector, so the Delete key always removes the node
        // the admin is actually looking at.
        setNodes((nds) => [
            ...nds.map((n) => ({ ...n, selected: false })),
            {
                id,
                type,
                position,
                data: defaultDataFor(type),
                selected: true,
            } as Node,
        ]);
        setSelectedNodeId(id);
        setRightPane('inspector');
    };

    const handleAddNode = (type: StepType) => {
        const last = nodes[nodes.length - 1];
        addNodeAt(type, {
            x: (last?.position.x ?? 60) + 40,
            y: (last?.position.y ?? 100) + 170,
        });
    };

    const handleDrop = (e: React.DragEvent) => {
        e.preventDefault();
        const type = e.dataTransfer.getData(DRAG_MIME) as StepType | '';

        if (type === '') {
            return;
        }

        addNodeAt(type, screenToFlowPosition({ x: e.clientX, y: e.clientY }));
    };

    const handleNodeChange = (data: Record<string, unknown>) => {
        if (!selectedNodeId) {
            return;
        }

        // One undo entry per edit-session on a node, not per keystroke.
        if (lastInspectorEditRef.current !== selectedNodeId) {
            pushHistory();
            lastInspectorEditRef.current = selectedNodeId;
        }

        markDirty();

        setNodes((nds) =>
            nds.map((n) =>
                n.id === selectedNodeId ? { ...n, data: { ...data } } : n,
            ),
        );
    };

    const handleNodeDelete = () => {
        if (!selectedNodeId || selectedNodeId === 'trigger') {
            return;
        }

        pushHistory();
        markDirty();
        setNodes((nds) => nds.filter((n) => n.id !== selectedNodeId));
        setEdges((eds) =>
            eds.filter(
                (e) =>
                    e.source !== selectedNodeId && e.target !== selectedNodeId,
            ),
        );
        setSelectedNodeId(null);
    };

    const handleDuplicate = () => {
        if (!selectedNode || selectedNode.id === 'trigger') {
            return;
        }

        pushHistory();
        markDirty();
        const id = freshStepId();
        setNodes((nds) => [
            ...nds.map((n) => ({ ...n, selected: false })),
            {
                id,
                type: selectedNode.type,
                position: {
                    x: selectedNode.position.x + 32,
                    y: selectedNode.position.y + 32,
                },
                data: JSON.parse(
                    JSON.stringify(selectedNode.data ?? {}),
                ) as Record<string, unknown>,
                selected: true,
            } as Node,
        ]);
        setSelectedNodeId(id);
    };

    const handleTidy = () => {
        pushHistory();
        markDirty();
        setNodes(
            (nds) =>
                tidyLayout(
                    nds as unknown as CanvasNode[],
                    edges as unknown as CanvasEdge[],
                ) as unknown as Node[],
        );
    };

    const handleTriggerChange = (
        patch: Partial<{
            keywords: string[];
            match_mode: 'any' | 'all' | 'exact';
        }>,
    ) => {
        markDirty();

        if (patch.keywords !== undefined) {
            setKeywords(patch.keywords);
        }

        if (patch.match_mode !== undefined) {
            setMatchMode(patch.match_mode);
        }

        // Mirror onto the trigger node's data so the visual updates live.
        setNodes((nds) =>
            nds.map((n) =>
                n.id === 'trigger'
                    ? {
                          ...n,
                          data: {
                              ...n.data,
                              keywords: patch.keywords ?? keywords,
                              match_mode: patch.match_mode ?? matchMode,
                          },
                      }
                    : n,
            ),
        );
    };

    const handleSave = () => {
        setSaveError(null);

        if (status === 'active' && problems.length > 0) {
            setSaveError(
                t(
                    'Fix the :count problem(s) before activating — drafts can be saved anytime.',
                    { count: problems.length },
                ),
            );
            setProblemsOpen(true);

            return;
        }

        setSaving(true);
        savingRef.current = true;

        const canvasNodes = nodes
            .filter((n) => n.id !== 'trigger')
            .map((n) => ({
                id: n.id,
                type: n.type as StepType,
                position: { x: n.position.x, y: n.position.y },
                data: (n.data as Record<string, unknown>) ?? {},
            }));
        const canvasEdges = edgesForExport();
        const steps = canvasToSteps(
            nodes as unknown as CanvasNode[],
            canvasEdges,
        );

        // Inertia's typed helpers want FormDataConvertible values, but
        // the workflow endpoints take nested JSON arrays — Inertia
        // serialises plain objects transparently, so the cast is
        // correct at runtime.
        const payload = {
            name,
            status,
            // Pinning passthrough — the canvas has no agent/segment
            // picker, so it must echo the stored values back or a save
            // from here would silently null them (the update endpoint
            // treats absent as "clear").
            agent_id: workflow?.agent_id ?? null,
            segment_id: workflow?.segment_id ?? null,
            trigger_kind: 'on_keyword',
            match_mode: matchMode,
            keywords,
            steps,
            definition_canvas: { nodes: canvasNodes, edges: canvasEdges },
            _editor: 'canvas',
        } as unknown as Record<string, never>;

        const opts = {
            preserveScroll: true,
            onSuccess: () => {
                dirtyRef.current = false;
            },
            onError: (errors: Record<string, string>) => {
                const first = Object.values(errors)[0];
                setSaveError(
                    typeof first === 'string'
                        ? first
                        : t('Could not save the workflow.'),
                );
            },
            onFinish: () => {
                setSaving(false);
                savingRef.current = false;
            },
        };

        if (workflow) {
            router.patch(workflowsUpdate(workflow.id).url, payload, opts);
        } else {
            router.post(workflowsStore().url, payload, opts);
        }
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head
                title={
                    workflow
                        ? t(':name · Canvas', { name: workflow.name })
                        : t('New workflow · Canvas')
                }
            />
            <style>{`
                .react-flow__node.pb-problem > div { outline: 2px solid rgba(220, 38, 38, 0.75); outline-offset: 2px; border-radius: 10px; }
                .react-flow__node.pb-trace > div { outline: 2px solid rgba(22, 163, 74, 0.85); outline-offset: 2px; border-radius: 10px; }
            `}</style>

            <div className="flex flex-1 flex-col">
                <div className="flex flex-wrap items-center gap-2 border-b bg-card px-4 py-2">
                    <input
                        className="min-w-0 flex-1 rounded-md border bg-background px-3 py-1 text-sm font-medium"
                        value={name}
                        onChange={(e) => {
                            markDirty();
                            setName(e.target.value);
                        }}
                        aria-label={t('Workflow name')}
                    />
                    <select
                        className="rounded-md border bg-background px-3 py-1 text-sm"
                        value={status}
                        onChange={(e) => {
                            markDirty();
                            setStatus(
                                e.target.value as
                                    | 'draft'
                                    | 'active'
                                    | 'disabled',
                            );
                        }}
                        aria-label={t('Status')}
                    >
                        <option value="draft">{t('Draft')}</option>
                        <option value="active">{t('Active')}</option>
                        <option value="disabled">{t('Disabled')}</option>
                    </select>

                    <span
                        className="mx-1 h-5 w-px bg-border"
                        aria-hidden="true"
                    />

                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        onClick={undo}
                        disabled={historyDepth.past === 0}
                        title={t('Undo')}
                        aria-label={t('Undo')}
                    >
                        <Undo2 className="size-4" />
                    </Button>
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        onClick={redo}
                        disabled={historyDepth.future === 0}
                        title={t('Redo')}
                        aria-label={t('Redo')}
                    >
                        <Redo2 className="size-4" />
                    </Button>
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        onClick={handleTidy}
                        title={t('Tidy layout')}
                        aria-label={t('Tidy layout')}
                    >
                        <Wand2 className="size-4" />
                    </Button>
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        onClick={handleDuplicate}
                        disabled={!selectedNode || selectedNodeId === 'trigger'}
                        title={t('Duplicate step')}
                        aria-label={t('Duplicate step')}
                    >
                        <Copy className="size-4" />
                    </Button>

                    <div className="relative">
                        <Button
                            type="button"
                            variant={problems.length > 0 ? 'outline' : 'ghost'}
                            size="sm"
                            onClick={() => setProblemsOpen((o) => !o)}
                            className={
                                problems.length > 0
                                    ? 'border-red-300 text-red-700 dark:border-red-700 dark:text-red-300'
                                    : ''
                            }
                        >
                            <AlertTriangle className="me-1 size-4" />
                            {problems.length > 0
                                ? t(':count problems', {
                                      count: problems.length,
                                  })
                                : t('No problems')}
                        </Button>
                        {problemsOpen && problems.length > 0 && (
                            <div className="absolute end-0 top-full z-50 mt-1 w-80 rounded-md border bg-popover p-2 text-popover-foreground shadow-md">
                                <ul className="space-y-1">
                                    {problems.map((p, i) => (
                                        <li key={i}>
                                            <button
                                                type="button"
                                                className="w-full rounded px-2 py-1 text-start text-xs hover:bg-accent"
                                                onClick={() => {
                                                    setSelectedNodeId(p.nodeId);
                                                    setRightPane('inspector');
                                                    setProblemsOpen(false);
                                                }}
                                            >
                                                {p.message}
                                            </button>
                                        </li>
                                    ))}
                                </ul>
                            </div>
                        )}
                    </div>

                    <Button
                        type="button"
                        variant={rightPane === 'test' ? 'secondary' : 'ghost'}
                        size="sm"
                        onClick={() =>
                            setRightPane((p) =>
                                p === 'test' ? 'inspector' : 'test',
                            )
                        }
                    >
                        <FlaskConical className="me-1 size-4" />
                        {t('Test run')}
                    </Button>

                    {workflow && (
                        <Button asChild variant="outline" size="sm">
                            <Link href={workflowsEdit(workflow.id).url}>
                                {t('Linear edit')}
                            </Link>
                        </Button>
                    )}
                    <Button
                        type="button"
                        size="sm"
                        onClick={handleSave}
                        disabled={saving}
                    >
                        <Save className="me-1 size-4" />
                        {saving
                            ? t('Saving…')
                            : workflow
                              ? t('Save')
                              : t('Create workflow')}
                    </Button>
                </div>

                {saveError !== null && (
                    <div className="border-b border-red-200 bg-red-50 px-4 py-2 text-xs text-red-800 dark:border-red-900 dark:bg-red-950 dark:text-red-200">
                        {saveError}
                    </div>
                )}

                <div className="grid flex-1 grid-cols-[180px_1fr_320px] overflow-hidden">
                    {/* Left: palette */}
                    <aside className="flex flex-col gap-1 overflow-y-auto border-e bg-muted/30 p-2">
                        <p className="px-2 py-1 text-[11px] font-bold tracking-wide text-muted-foreground uppercase">
                            {t('Add step')}
                        </p>
                        {palette.map((p) => (
                            <button
                                key={p.type}
                                type="button"
                                draggable
                                onDragStart={(e) => {
                                    e.dataTransfer.setData(DRAG_MIME, p.type);
                                    e.dataTransfer.effectAllowed = 'move';
                                }}
                                onClick={() => handleAddNode(p.type)}
                                className="flex cursor-grab items-center gap-2 rounded-md border bg-card px-2 py-1.5 text-start text-xs font-medium transition hover:bg-accent active:cursor-grabbing"
                                title={t(
                                    'Click to add, or drag onto the canvas',
                                )}
                            >
                                {NODE_TYPE_ICONS[p.type]}
                                <span>{p.label}</span>
                                <Plus className="ms-auto size-3 text-muted-foreground" />
                            </button>
                        ))}
                        <p className="mt-2 px-2 text-[10px] leading-snug text-muted-foreground">
                            {t(
                                'Tip: drag a step onto the canvas to place it exactly. Delete removes the selection.',
                            )}
                        </p>
                    </aside>

                    {/* Center: canvas */}
                    <div
                        className="relative bg-[#fafafa] dark:bg-background"
                        onDrop={handleDrop}
                        onDragOver={(e) => {
                            e.preventDefault();
                            e.dataTransfer.dropEffect = 'move';
                        }}
                    >
                        <ReactFlow
                            nodes={renderNodes}
                            edges={edges}
                            onNodesChange={onNodesChange}
                            onEdgesChange={onEdgesChange}
                            onConnect={onConnect}
                            nodeTypes={NODE_TYPES as never}
                            onNodeClick={(_, node) =>
                                setSelectedNodeId(node.id)
                            }
                            onPaneClick={() => {
                                setSelectedNodeId(null);
                                setProblemsOpen(false);
                                lastInspectorEditRef.current = null;
                            }}
                            onNodeDragStart={() => {
                                dragSnapshotRef.current = takeSnapshot();
                            }}
                            onNodeDragStop={() => {
                                if (dragSnapshotRef.current) {
                                    pushHistory(dragSnapshotRef.current);
                                    dragSnapshotRef.current = null;
                                    markDirty();
                                }
                            }}
                            onBeforeDelete={async ({ nodes: toDelete }) => {
                                // Trigger carries deletable:false already;
                                // the snapshot makes Delete-key removals
                                // undoable.
                                if (toDelete.some((n) => n.id === 'trigger')) {
                                    return false;
                                }

                                pushHistory();
                                markDirty();

                                return true;
                            }}
                            deleteKeyCode={['Backspace', 'Delete']}
                            fitView
                        >
                            <Background />
                            <Controls />
                            <MiniMap pannable zoomable />
                        </ReactFlow>
                    </div>

                    {/* Right: inspector / test run */}
                    <aside className="flex flex-col overflow-y-auto border-s bg-card p-3">
                        {rightPane === 'test' ? (
                            <TestRunPanel
                                buildPayload={() => {
                                    const edgeList = edgesForExport();

                                    return {
                                        keywords,
                                        match_mode: matchMode,
                                        steps: canvasToSteps(
                                            nodes as unknown as CanvasNode[],
                                            edgeList,
                                        ),
                                        stepOrder: canvasStepOrder(
                                            nodes as unknown as CanvasNode[],
                                            edgeList,
                                        ),
                                    };
                                }}
                                onTraceNodes={(ids) =>
                                    setTraceIds(new Set(ids))
                                }
                            />
                        ) : selectedNode ? (
                            <NodeInspector
                                node={selectedNode as CanvasNode}
                                onChange={handleNodeChange}
                                onDelete={handleNodeDelete}
                                triggerExtras={
                                    selectedNode.id === 'trigger'
                                        ? {
                                              keywords,
                                              match_mode: matchMode,
                                              onTriggerChange:
                                                  handleTriggerChange,
                                          }
                                        : undefined
                                }
                            />
                        ) : (
                            <p className="text-xs text-muted-foreground">
                                {t(
                                    "Click a node to edit. Drag from a node's bottom or side handles to wire it to the next step. Branch nodes have one handle per case.",
                                )}
                            </p>
                        )}
                    </aside>
                </div>
            </div>
        </AppLayout>
    );
}

function defaultDataFor(type: StepType): Record<string, unknown> {
    switch (type) {
        case 'message':
            return { text: 'New message' };
        case 'question':
            return { text: 'New question?', var_name: 'visitor_answer' };
        case 'branch':
            return {
                var: 'visitor_answer',
                cases: [
                    { match: 'equals', value: '', go_to: 0 },
                    { match: 'default', value: null, go_to: 0 },
                ],
            };
        case 'tag_lead':
            return { tags: [] };
        case 'webhook':
            return { url: '', method: 'POST' };
        case 'escalate':
            return { text: 'Connecting you with a human now.' };
    }
}
