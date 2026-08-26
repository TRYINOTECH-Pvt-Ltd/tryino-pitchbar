/**
 * Wire-shape types shared between the canvas page, the custom nodes,
 * and the persistence translator. They mirror the backend's step
 * schema 1:1 so the round-trip is loss-free.
 */

export type StepType =
    | 'message'
    | 'question'
    | 'branch'
    | 'tag_lead'
    | 'webhook'
    | 'escalate';

export type BranchMatch =
    | 'equals'
    | 'contains'
    | 'starts_with'
    | 'is_empty'
    | 'not_empty'
    | 'default';

export type BranchCase = {
    match: BranchMatch;
    value?: string | null;
    go_to: number;
};

export type Step =
    | { type: 'message'; text: string }
    | { type: 'question'; text: string; var_name?: string }
    | {
          type: 'branch';
          var: string;
          cases: BranchCase[];
      }
    | { type: 'tag_lead'; tags: string[] }
    | {
          type: 'webhook';
          url: string;
          method?: 'POST' | 'GET';
          extra_payload?: Record<string, unknown>;
      }
    | { type: 'escalate'; text: string };

export type CanvasNode = {
    id: string;
    type: StepType;
    position: { x: number; y: number };
    /** Concrete step fields, see Step union above. */
    data: Record<string, unknown>;
};

export type CanvasEdge = {
    id: string;
    source: string;
    target: string;
    /**
     * For branch nodes, the source handle identifies which case this
     * edge satisfies. Format: `case-{index}` for ordered cases or
     * `default` for the catch-all. Other node types use `out`.
     */
    sourceHandle?: string;
};

export type CanvasState = {
    nodes: CanvasNode[];
    edges: CanvasEdge[];
};

export type WorkflowProp = {
    id: string;
    name: string;
    status: 'draft' | 'active' | 'disabled';
    agent_id: string | null;
    /** Wessels/segments installs pin workflows to a segment; absent on trunk. */
    segment_id?: string | null;
    trigger_kind: 'on_keyword';
    keywords: string[];
    match_mode: 'any' | 'all' | 'exact';
    steps: Step[];
    definition: { steps: Step[]; canvas?: CanvasState | null };
};

export type AgentOption = { id: string; name: string };

/**
 * Per-node `data` shapes used by the React-Flow custom node components.
 * Pre-2026-05-16 each node accepted `data: any` and inline-cast inside
 * the render — silent drift between canvas / engine / form was common.
 * Types are unionable on `StepType` but most node components only see
 * their own slice, so the shapes are exported individually here.
 */
export type TriggerNodeData = {
    keywords?: string[];
    match_mode?: 'any' | 'all' | 'exact';
};

export type MessageNodeData = { text?: string };

export type QuestionNodeData = { text?: string; var_name?: string };

export type BranchNodeData = { var?: string; cases?: BranchCase[] };

export type TagLeadNodeData = { tags?: string[] };

export type WebhookNodeData = {
    url?: string;
    method?: 'POST' | 'GET';
    extra_payload?: Record<string, unknown>;
};

export type EscalateNodeData = { text?: string };
