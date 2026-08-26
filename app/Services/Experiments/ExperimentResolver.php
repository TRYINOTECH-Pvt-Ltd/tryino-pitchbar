<?php

namespace App\Services\Experiments;

use App\Models\Agent;
use App\Models\Conversation;
use App\Models\Experiment;
use App\Models\Variant;
use Illuminate\Support\Facades\Cache;

/**
 * Resolves the active variant for a conversation.
 *
 * Two-phase API:
 *
 *   - `resolveForConversation()` runs on the visitor SSE hot path. PURE.
 *     Never writes to the DB. Returns a `Variant` instance (with its
 *     `experiment` relation hydrated) so the caller can read `kind` +
 *     `config` to alter the prompt. Re-uses an existing
 *     `conversation.variant_id` when present; otherwise computes the
 *     deterministic-hash pick in memory.
 *
 *   - `persistAssignment()` runs from `PersistTurnJob` (or any post-
 *     stream path). Idempotent. Writes `conversation.variant_id` AND
 *     the `experiment_assignments` row so the next turn finds the
 *     persisted state and skips even the in-memory pick.
 *
 * Pre-2026-05-16 hot path included both writes inline before the first
 * SSE token, violating PLAN §7. Split fixed that.
 *
 * The `conversation.variant_id` column holds a single variant, so this
 * resolver enforces "one running experiment per agent at a time" — the
 * UI lets operators draft multiple, but only ONE can be `running`
 * concurrently. The resolver picks the most-recently-started running
 * experiment when several somehow share state.
 *
 * Returns null when no experiments are running for the agent (the
 * common case). Callers should fall back to agent-level defaults.
 */
class ExperimentResolver
{
    public function __construct(private readonly Assigner $assigner) {}

    /**
     * HOT PATH safe. Returns the variant that should drive THIS turn.
     * Never writes to the DB.
     */
    public function resolveForConversation(Conversation $conversation): ?Variant
    {
        // Re-use the assignment already stamped onto this conversation —
        // first turn persisted via persistAssignment() means every later
        // turn short-circuits here without touching the experiment row.
        if ($conversation->variant_id !== null) {
            return Variant::query()
                ->with('experiment:id,agent_id,kind,status')
                ->find($conversation->variant_id);
        }

        $experiment = $this->experimentFor($conversation);
        if ($experiment === null) {
            return null;
        }

        $visitorId = (string) ($conversation->visitor_id ?? '');
        $picked = $this->assigner->pick($experiment, $visitorId);
        if ($picked === null) {
            return null;
        }

        // Hydrate the experiment relation so the caller can read
        // `kind` without an extra query.
        $picked->setRelation('experiment', $experiment);

        return $picked;
    }

    /**
     * Post-stream persistence. Called from `PersistTurnJob` once the
     * SSE response has finished. Idempotent: re-runs hit existing rows
     * and short-circuit. Same deterministic hash as `pick()` so the
     * persisted Variant matches what `resolveForConversation()` already
     * returned to the caller.
     */
    public function persistAssignment(Conversation $conversation): void
    {
        // Already persisted on a prior turn — nothing to do.
        if ($conversation->variant_id !== null) {
            return;
        }

        $experiment = $this->experimentFor($conversation);
        if ($experiment === null) {
            return;
        }

        $visitorId = (string) ($conversation->visitor_id ?? '');
        if ($visitorId === '') {
            return;
        }

        $variant = $this->assigner->assign($experiment, $visitorId);
        if ($variant === null) {
            return;
        }

        $conversation->forceFill(['variant_id' => $variant->id])->save();
    }

    /**
     * Shared lookup used by both phases. Cheap; the cache key collapses
     * to a single UUID lookup after the first call per agent.
     */
    private function experimentFor(Conversation $conversation): ?Experiment
    {
        // Agent lookup uses withoutGlobalScopes() because this runs on
        // the widget JWT path — no admin session is bound, so the
        // BelongsToWorkspace scope would filter every agent away.
        $agent = Agent::query()->withoutGlobalScopes()->find($conversation->agent_id);
        if ($agent === null) {
            return null;
        }

        return $this->runningExperimentForAgent($agent->id);
    }

    /**
     * Cache the running-experiment lookup for 60 seconds per agent.
     * Start/stop endpoints flush the key explicitly so changes show up
     * within the same admin session.
     */
    private function runningExperimentForAgent(string $agentId): ?Experiment
    {
        $key = "experiments:running:{$agentId}";

        $experimentId = Cache::remember($key, 60, function () use ($agentId): ?string {
            // withoutGlobalScopes() — widget JWT path, no workspace
            // session bound; otherwise the BelongsToAgent scope on
            // Experiment would filter against an empty context.
            $row = Experiment::query()->withoutGlobalScopes()
                ->where('agent_id', $agentId)
                ->where('status', 'running')
                ->orderByDesc('started_at')
                ->first(['id']);

            return $row?->id;
        });

        if ($experimentId === null) {
            return null;
        }

        // withoutGlobalScopes() — same JWT-path reasoning.
        return Experiment::query()->withoutGlobalScopes()->find($experimentId);
    }

    /**
     * Flush the running-experiment cache for an agent. Called from
     * ExperimentController on start / stop / destroy so the change
     * takes effect on the next conversation without waiting out the
     * 60-second TTL.
     */
    public static function forget(string $agentId): void
    {
        Cache::forget("experiments:running:{$agentId}");
    }
}
