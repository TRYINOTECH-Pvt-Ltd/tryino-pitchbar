import type { BranchCase, CanvasEdge, CanvasNode } from './types';

/**
 * Live lint for the canvas. A Problem anchors to a node (nodeId) so
 * the editor can ring the offending card and jump to it from the
 * problems list; `nodeId: 'trigger'` anchors trigger-level issues.
 *
 * Problems block ACTIVATING a workflow, never saving — drafts must
 * stay saveable in any half-finished state.
 */
export type Problem = {
    nodeId: string;
    message: string;
};

type Translate = (
    key: string,
    replacements?: Record<string, string | number>,
) => string;

export function computeProblems(
    nodes: CanvasNode[],
    edges: CanvasEdge[],
    keywords: string[],
    t: Translate,
): Problem[] {
    const problems: Problem[] = [];
    const stepNodes = nodes.filter((n) => n.id !== 'trigger');

    if (keywords.filter((k) => k.trim() !== '').length === 0) {
        problems.push({
            nodeId: 'trigger',
            message: t(
                'Trigger has no keywords — the workflow can never fire.',
            ),
        });
    }

    if (stepNodes.length > 0 && !edges.some((e) => e.source === 'trigger')) {
        problems.push({
            nodeId: 'trigger',
            message: t('Trigger is not wired to a first step.'),
        });
    }

    if (stepNodes.length === 0) {
        problems.push({
            nodeId: 'trigger',
            message: t('The flow has no steps yet.'),
        });
    }

    // Reachability — BFS from the trigger. Anything the walk can't
    // reach will never run, whatever its own config says.
    const reachable = new Set<string>(['trigger']);
    const queue = ['trigger'];

    while (queue.length > 0) {
        const id = queue.shift() as string;

        for (const edge of edges) {
            if (edge.source === id && !reachable.has(edge.target)) {
                reachable.add(edge.target);
                queue.push(edge.target);
            }
        }
    }

    for (const node of stepNodes) {
        if (!reachable.has(node.id)) {
            problems.push({
                nodeId: node.id,
                message: t(
                    'Step is not connected to the flow — it will never run.',
                ),
            });
        }

        const data = node.data ?? {};

        switch (node.type) {
            case 'message':
            case 'question': {
                const text = String(data.text ?? '').trim();

                if (text === '') {
                    problems.push({
                        nodeId: node.id,
                        message:
                            node.type === 'message'
                                ? t('Message text is empty.')
                                : t('Question text is empty.'),
                    });
                }

                break;
            }

            case 'branch': {
                const varName = String(data.var ?? '').trim();

                if (varName === '') {
                    problems.push({
                        nodeId: node.id,
                        message: t('Branch has no variable to check.'),
                    });
                }

                const cases = (data.cases ?? []) as BranchCase[];

                if (cases.length === 0) {
                    problems.push({
                        nodeId: node.id,
                        message: t('Branch has no cases.'),
                    });
                }

                cases.forEach((c, i) => {
                    if (c.match === 'default') {
                        return;
                    }

                    const wired = edges.some(
                        (e) =>
                            e.source === node.id &&
                            e.sourceHandle === `case-${i}`,
                    );

                    if (!wired) {
                        problems.push({
                            nodeId: node.id,
                            message: t(
                                'Branch case :n is not wired to a step.',
                                {
                                    n: i + 1,
                                },
                            ),
                        });
                    }
                });
                break;
            }

            case 'webhook': {
                const url = String(data.url ?? '').trim();

                if (url === '' || !/^https?:\/\//i.test(url)) {
                    problems.push({
                        nodeId: node.id,
                        message: t(
                            'Webhook URL is missing or not an http(s) address.',
                        ),
                    });
                }

                break;
            }

            case 'tag_lead': {
                const tags = (Array.isArray(data.tags) ? data.tags : [])
                    .map((x) => String(x).trim())
                    .filter((x) => x !== '');

                if (tags.length === 0) {
                    problems.push({
                        nodeId: node.id,
                        message: t('Tag step has no tags.'),
                    });
                }

                break;
            }

            // escalate: engine supplies a default text — always valid.
            default:
                break;
        }
    }

    return problems;
}
