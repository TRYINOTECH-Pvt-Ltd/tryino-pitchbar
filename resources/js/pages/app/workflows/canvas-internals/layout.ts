import type { CanvasEdge, CanvasNode } from './types';

const COL_WIDTH = 280;
const ROW_HEIGHT = 180;
const ORIGIN_X = 60;
const ORIGIN_Y = 0;

/**
 * "Tidy layout" — deterministic layered layout, no external library.
 *
 * Ranks are BFS depth from the trigger (rank 0). Within a rank, nodes
 * keep their current left-to-right order (stable sort on x) so tidying
 * never flips siblings the admin deliberately arranged. Unreachable
 * nodes get parked on a bottom row so they stay visible — the
 * validation layer flags them separately.
 */
export function tidyLayout(
    nodes: CanvasNode[],
    edges: CanvasEdge[],
): CanvasNode[] {
    const rankById = new Map<string, number>();
    rankById.set('trigger', 0);
    const queue = ['trigger'];

    while (queue.length > 0) {
        const id = queue.shift() as string;
        const rank = rankById.get(id) ?? 0;

        for (const edge of edges) {
            if (edge.source === id && !rankById.has(edge.target)) {
                rankById.set(edge.target, rank + 1);
                queue.push(edge.target);
            }
        }
    }

    const maxRank = Math.max(0, ...rankById.values());

    // Group by rank, preserving current visual order.
    const byRank = new Map<number, CanvasNode[]>();
    const parked: CanvasNode[] = [];

    for (const node of nodes) {
        const rank = rankById.get(node.id);

        if (rank === undefined) {
            parked.push(node);
            continue;
        }

        const bucket = byRank.get(rank) ?? [];
        bucket.push(node);
        byRank.set(rank, bucket);
    }

    const positioned = new Map<string, { x: number; y: number }>();

    for (const [rank, bucket] of byRank) {
        bucket.sort((a, b) => a.position.x - b.position.x);
        bucket.forEach((node, i) => {
            positioned.set(node.id, {
                x: ORIGIN_X + i * COL_WIDTH,
                y: ORIGIN_Y + rank * ROW_HEIGHT,
            });
        });
    }

    parked
        .sort((a, b) => a.position.x - b.position.x)
        .forEach((node, i) => {
            positioned.set(node.id, {
                x: ORIGIN_X + i * COL_WIDTH,
                y: ORIGIN_Y + (maxRank + 2) * ROW_HEIGHT,
            });
        });

    return nodes.map((node) => ({
        ...node,
        position: positioned.get(node.id) ?? node.position,
    }));
}
