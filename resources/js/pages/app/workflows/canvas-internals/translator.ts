import type { CanvasEdge, CanvasNode, Step, StepType } from './types';

/**
 * Translate a React-Flow canvas (nodes + edges) into the backend's
 * flat `steps[]` array. The runtime always consumes steps[]; the
 * canvas state is a UI affordance the editor persists alongside.
 *
 * Algorithm:
 *   1. Find the trigger node (synthetic, not a step).
 *   2. Walk outgoing edges from the trigger to build a deterministic
 *      step order. Branch nodes have multiple outgoing edges keyed by
 *      `sourceHandle = case-N` or `default`.
 *   3. Assign each non-trigger node an index in the order discovered.
 *   4. For branches, populate `cases[*].go_to` from the destination
 *      indices.
 *
 * Cycles in the graph (a branch case pointing back to a parent)
 * are allowed — the runtime's loop-guard (32 jumps) handles them.
 * The translator just records the indices.
 */
export function canvasToSteps(
    nodes: CanvasNode[],
    edges: CanvasEdge[],
): Step[] {
    const order = canvasStepOrder(nodes, edges);

    // Index every step node by its 0-based step index.
    const indexById = new Map<string, number>();
    order.forEach((id, i) => indexById.set(id, i));

    return order.map((id) => {
        const node = nodes.find((n) => n.id === id);

        if (!node) {
            return { type: 'message', text: '' } as Step;
        }

        return nodeToStep(node, edges, indexById);
    });
}

/**
 * Deterministic node-id order matching the step indices canvasToSteps
 * assigns — BFS from the trigger, cycles short-circuited by the
 * visited set. Exported so the test-run panel can map a trace's step
 * indices back to canvas node ids for path highlighting.
 */
export function canvasStepOrder(
    nodes: CanvasNode[],
    edges: CanvasEdge[],
): string[] {
    const trigger = nodes.find((n) => n.id === 'trigger');

    if (!trigger) {
        // No trigger node → nothing to translate. Caller should warn.
        return [];
    }

    const order: string[] = [];
    const visited = new Set<string>([trigger.id]);
    const queue: string[] = [];

    const triggerOuts = edges.filter((e) => e.source === trigger.id);

    for (const edge of triggerOuts) {
        if (!visited.has(edge.target)) {
            visited.add(edge.target);
            queue.push(edge.target);
        }
    }

    while (queue.length > 0) {
        const id = queue.shift() as string;
        order.push(id);
        const outs = edges
            .filter((e) => e.source === id)
            // Stable ordering for branch handles so case-0 lands before case-1
            // before default in the BFS — keeps test snapshots reproducible.
            .sort((a, b) =>
                (a.sourceHandle ?? 'out').localeCompare(
                    b.sourceHandle ?? 'out',
                ),
            );

        for (const edge of outs) {
            if (!visited.has(edge.target)) {
                visited.add(edge.target);
                queue.push(edge.target);
            }
        }
    }

    return order;
}

function nodeToStep(
    node: CanvasNode,
    edges: CanvasEdge[],
    indexById: Map<string, number>,
): Step {
    const type = node.type as StepType;
    const data = node.data ?? {};

    switch (type) {
        case 'message':
            return { type: 'message', text: String(data.text ?? '') };

        case 'question':
            return {
                type: 'question',
                text: String(data.text ?? ''),
                var_name: String(data.var_name ?? 'visitor_answer'),
            };

        case 'branch': {
            // Each outgoing edge whose sourceHandle is `case-N` becomes
            // cases[N]. The `default` handle, if present, gets pushed
            // last as match=default. Cases array on the node carries
            // the operator + value; the edge supplies the target.
            const declared = (data.cases ?? []) as Array<{
                match: string;
                value?: string | null;
            }>;

            const casesOut = declared.map((c, i) => {
                const edge = edges.find(
                    (e) =>
                        e.source === node.id && e.sourceHandle === `case-${i}`,
                );
                const target = edge ? indexById.get(edge.target) : undefined;

                return {
                    match: c.match as
                        | 'equals'
                        | 'contains'
                        | 'starts_with'
                        | 'is_empty'
                        | 'not_empty'
                        | 'default',
                    value: c.value ?? null,
                    go_to: target ?? indexById.size,
                };
            });

            // If the user wired a `default` handle, append a default case.
            const defaultEdge = edges.find(
                (e) => e.source === node.id && e.sourceHandle === 'default',
            );

            if (defaultEdge) {
                casesOut.push({
                    match: 'default',
                    value: null,
                    go_to: indexById.get(defaultEdge.target) ?? indexById.size,
                });
            }

            return {
                type: 'branch',
                var: String(data.var ?? ''),
                cases: casesOut,
            };
        }

        case 'tag_lead':
            return {
                type: 'tag_lead',
                tags: Array.isArray(data.tags)
                    ? (data.tags as string[]).map(String)
                    : [],
            };

        case 'webhook':
            return {
                type: 'webhook',
                url: String(data.url ?? ''),
                method: (data.method as 'POST' | 'GET') ?? 'POST',
                extra_payload:
                    data.extra_payload && typeof data.extra_payload === 'object'
                        ? (data.extra_payload as Record<string, unknown>)
                        : undefined,
            };

        case 'escalate':
            return {
                type: 'escalate',
                text: String(data.text ?? 'Connecting you with a human now.'),
            };
    }
}

/**
 * Reverse direction — take a flat steps[] (e.g. the form-edited
 * shape from Phase 1 with no `definition.canvas`) and lay it out
 * vertically with linear edges so the canvas can render it without
 * the admin manually arranging.
 */
export function stepsToCanvas(steps: Step[]): {
    nodes: CanvasNode[];
    edges: CanvasEdge[];
} {
    const nodes: CanvasNode[] = [
        {
            id: 'trigger',
            type: 'message' as StepType, // visual only — the trigger is rendered separately
            position: { x: 50, y: 0 },
            data: { __trigger: true },
        },
    ];
    const edges: CanvasEdge[] = [];

    let lastNodeId = 'trigger';
    steps.forEach((step, i) => {
        const id = `step-${i}`;
        nodes.push({
            id,
            type: step.type,
            position: { x: 50, y: 140 + i * 160 },
            data: { ...step } as Record<string, unknown>,
        });

        // Linear edge from the previous node — for branch steps we keep
        // the linear "fall-through" edge AND read explicit case go_to
        // targets when laying out.
        edges.push({
            id: `e-${lastNodeId}-${id}`,
            source: lastNodeId,
            target: id,
            sourceHandle: 'out',
        });

        if (step.type === 'branch') {
            step.cases.forEach((c, ci) => {
                const targetId = `step-${c.go_to}`;

                if (steps[c.go_to] !== undefined) {
                    edges.push({
                        id: `e-${id}-${ci}-${targetId}`,
                        source: id,
                        target: targetId,
                        sourceHandle:
                            c.match === 'default' ? 'default' : `case-${ci}`,
                    });
                }
            });
        }

        lastNodeId = id;
    });

    return { nodes, edges };
}
