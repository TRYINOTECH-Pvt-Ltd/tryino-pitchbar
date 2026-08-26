import type { AgentConfig } from './api';

/**
 * Always-safe accessor for the vertical id, with `'generic'` fallback.
 * Used for telemetry tagging so dashboards can segment by vertical
 * even when the agent has no explicit site_type.
 *
 * Note: previous `canRender(capability, agent)` helper removed in
 * iter 5 audit pass (2026-05-16) — it was exported but never
 * imported anywhere. Reintroduce when Phase 3 ships a renderer
 * gate that needs it.
 */
export function siteType(agent: AgentConfig | null): string {
    return agent?.site_type ?? 'generic';
}
