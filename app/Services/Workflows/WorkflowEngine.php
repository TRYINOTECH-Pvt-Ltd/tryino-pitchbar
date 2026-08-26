<?php

namespace App\Services\Workflows;

use App\Jobs\Workflows\DispatchWebhookJob;
use App\Models\Conversation;
use App\Models\Lead;
use App\Models\Workflow;
use App\Models\WorkflowRun;
use App\Services\Workflows\Concerns\SharesWorkflowSemantics;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Hot-path workflow runtime. Wired into MessageStreamController BEFORE
 * the LLM call so a matching workflow short-circuits the bot's
 * normal RAG turn and runs scripted replies instead.
 *
 * Phase 1 supported a strictly linear sequence of message / question /
 * escalate steps with on_keyword triggers (any-match). Phase 2 adds:
 *
 *   - branch — evaluates `vars[var]` against ordered cases, jumps to
 *     `go_to` step index. First match wins; `match: default` fires
 *     when no other case hits. Loop-guarded with a max-jump counter.
 *
 *   - tag_lead — appends string tags to the conversation's Lead row
 *     (creating a Lead stub if one doesn't yet exist on the
 *     conversation). The only side-effect step that writes to DB
 *     synchronously; sits on the workflow path, not the LLM
 *     streaming path, so TTFT is unaffected.
 *
 *   - webhook — dispatches a queued Job that POSTs JSON to a configured
 *     URL with the run's vars. Fire-and-forget; failures land in
 *     failed_jobs.
 *
 *   - trigger match_mode (any|all|exact) — extends keyword matching
 *     beyond Phase 1's substring "any" default.
 *
 * Returns true when the engine handled the turn (caller skips the
 * LLM); false when no workflow matched (caller proceeds normally).
 */
class WorkflowEngine
{
    // Keyword matching, branch-case evaluation, clamping, tag
    // normalization and interpolation live in the shared trait so the
    // canvas test-run simulator executes the exact same semantics.
    use SharesWorkflowSemantics;

    /**
     * Maximum branch jumps per turn before the engine bails out and
     * marks the run failed. A graph with 32+ branch hops in a single
     * resume is almost certainly a misconfiguration / loop, not a
     * legit flow. Without this an admin who points two branches at
     * each other could hang the SSE stream.
     */
    private const MAX_BRANCH_JUMPS = 32;

    /**
     * @param  callable(string $text): void  $emit  Emits a single chat-bubble message text.
     */
    public function handleTurn(
        Conversation $conversation,
        string $visitorMessage,
        callable $emit,
    ): bool {
        // Conversation-scoped atomic lock — buyer-audit 2026-05-16
        // surfaced a TOCTOU race: two near-simultaneous turns BOTH read
        // "no running WorkflowRun", then BOTH inserted one. Result:
        // duplicate runs per conversation, second one ignored, scripted
        // bubbles fire twice. Cache::lock serializes the whole resolve-
        // or-create block per conversation; the second turn waits up to
        // 5 seconds for the first to commit, then sees its run via the
        // initial SELECT and resumes it instead of creating a sibling.
        $lock = Cache::lock(
            "workflow_engine:turn:{$conversation->id}",
            10,
        );

        try {
            // Wait up to 5s; if the prior turn is taking longer than
            // that something is wrong upstream and we'd rather emit
            // nothing than block the SSE stream further.
            if (! $lock->block(5)) {
                return false;
            }

            $existing = WorkflowRun::query()
                ->withoutGlobalScopes()
                ->where('conversation_id', $conversation->id)
                ->where('status', 'running')
                ->latest('started_at')
                ->first();

            if ($existing !== null) {
                // Defence-in-depth: re-verify this run's workspace matches
                // the visitor's agent workspace before resuming. The lookup
                // above strips global scopes (widget JWT path has no
                // admin session), so without this guard a cross-tenant
                // conversation row could resume a foreign workspace's
                // run. Hard-stop the turn if it doesn't match.
                if (! $this->runOwnedByConversationWorkspace($existing, $conversation)) {
                    Log::warning('workflow.cross_tenant_run_blocked', [
                        'conversation_id' => $conversation->id,
                        'run_id' => $existing->id,
                    ]);

                    return false;
                }

                return $this->resume($existing, $visitorMessage, $emit);
            }

            $workflow = $this->findMatching($conversation, $visitorMessage);
            if ($workflow === null) {
                return false;
            }

            $run = WorkflowRun::query()->withoutGlobalScopes()->create([
                'workspace_id' => $workflow->workspace_id,
                'workflow_id' => $workflow->id,
                'conversation_id' => $conversation->id,
                'status' => 'running',
                'current_step_index' => 0,
                'vars' => ['trigger_message' => $visitorMessage],
                'started_at' => now(),
            ]);

            return $this->advance($run, $workflow, $emit);
        } finally {
            optional($lock)->release();
        }
    }

    /**
     * Cross-tenant guard for resumed WorkflowRun rows. The run's
     * workspace_id must match the conversation's agent's workspace_id.
     * Returns false on mismatch so handleTurn() refuses to resume.
     */
    private function runOwnedByConversationWorkspace(WorkflowRun $run, Conversation $conversation): bool
    {
        $agent = $conversation->agent()->withoutGlobalScopes()->first();
        if ($agent === null) {
            return false;
        }

        return (string) $run->workspace_id === (string) $agent->workspace_id;
    }

    /**
     * Public for tests / future synchronous invocations.
     */
    public function findMatching(Conversation $conversation, string $visitorMessage): ?Workflow
    {
        $needle = mb_strtolower(trim($visitorMessage));
        if ($needle === '') {
            return null;
        }

        $agent = $conversation->agent()->withoutGlobalScopes()->first();
        if ($agent === null) {
            return null;
        }

        // Active workflows scoped to this conversation's agent OR the
        // workspace-wide ones (agent_id IS NULL). Loaded once per turn —
        // this is the only DB read on the workflow path.
        //
        // Ordering: explicit priority desc, then most-recently-updated
        // first, then by id for determinism. Pre-2026-05-16 the SELECT
        // had no ORDER BY, so overlapping keyword triggers picked a
        // winner based on DB scan order — could flip between requests
        // on InnoDB. Buyer-audit finding.
        $workflows = Workflow::query()
            ->withoutGlobalScopes()
            ->where('status', 'active')
            ->where('workspace_id', $agent->workspace_id)
            ->where(function ($w) use ($agent) {
                $w->whereNull('agent_id')
                    ->orWhere('agent_id', $agent->id);
            })
            ->orderByDesc('priority')
            ->orderByDesc('updated_at')
            ->orderBy('id')
            ->get();

        foreach ($workflows as $workflow) {
            if ($workflow->trigger_kind !== 'on_keyword') {
                continue;
            }
            if ($this->keywordMatches($workflow, $needle)) {
                return $workflow;
            }
        }

        return null;
    }

    /**
     * Match `$needle` against the workflow's keywords using its
     * configured match_mode (any | all | exact). Defaults to `any`
     * for backwards compatibility with Phase 1 cards that don't
     * carry a match_mode field.
     */
    private function keywordMatches(Workflow $workflow, string $needle): bool
    {
        return $this->keywordSetMatches(
            $workflow->keywords(),
            (string) ($workflow->trigger_config['match_mode'] ?? 'any'),
            $needle,
        );
    }

    /**
     * @param  callable(string): void  $emit
     */
    private function resume(WorkflowRun $run, string $visitorMessage, callable $emit): bool
    {
        $workflow = Workflow::query()->withoutGlobalScopes()->find($run->workflow_id);
        if ($workflow === null) {
            $run->forceFill(['status' => 'failed', 'finished_at' => now()])->save();

            return false;
        }

        $steps = $workflow->steps();
        $currentStep = $steps[$run->current_step_index] ?? null;

        if (is_array($currentStep) && ($currentStep['type'] ?? '') === 'question') {
            // Default must stay 'visitor_answer' — canvas builder
            // (translator.ts:99, workflow-form.tsx:85) inserts that
            // exact key; any other fallback silently breaks branches
            // reading `{{ visitor_answer }}`.
            $varName = (string) ($currentStep['var_name'] ?? 'visitor_answer');
            $vars = (array) ($run->vars ?? []);
            $captured = mb_substr($visitorMessage, 0, 2000);
            $vars[$varName] = $captured;
            $vars = $this->boundVarsPayload($vars);
            $run->forceFill([
                'vars' => $vars,
                'current_step_index' => $run->current_step_index + 1,
            ])->save();
        }

        return $this->advance($run, $workflow, $emit);
    }

    /**
     * Walk steps forward from current_step_index until we hit a
     * question (pause) or the end (complete). Branch steps can
     * change current_step_index to any other index, so the loop
     * runs with a guard counter to prevent malformed flows from
     * hanging the engine.
     *
     * @param  callable(string): void  $emit
     */
    private function advance(WorkflowRun $run, Workflow $workflow, callable $emit): bool
    {
        $steps = $workflow->steps();
        $emitted = false;
        $branchJumps = 0;
        $stepCount = count($steps);

        while ($run->current_step_index < $stepCount && $run->current_step_index >= 0) {
            $step = $steps[$run->current_step_index];
            $type = (string) ($step['type'] ?? '');

            switch ($type) {
                case 'message':
                    $text = (string) ($step['text'] ?? '');
                    if ($text !== '') {
                        $emit($this->interpolate($text, (array) ($run->vars ?? [])));
                        $emitted = true;
                    }
                    $run->forceFill(['current_step_index' => $run->current_step_index + 1])->save();
                    break;

                case 'question':
                    $text = (string) ($step['text'] ?? '');
                    if ($text !== '') {
                        $emit($this->interpolate($text, (array) ($run->vars ?? [])));
                        $emitted = true;
                    }

                    // Pause: leave current_step_index pointing AT this question.
                    return $emitted;

                case 'escalate':
                    $message = (string) ($step['text'] ?? "Connecting you with a human now — they'll be with you in a moment.");
                    $emit($message);
                    $emitted = true;

                    // Mark the run as completed-with-handoff. Operators
                    // see this in the inbox via the run row + can claim
                    // the conversation from the existing takeover UI.
                    $vars = (array) ($run->vars ?? []);
                    $vars['escalated'] = true;
                    $run->forceFill([
                        'current_step_index' => $run->current_step_index + 1,
                        'status' => 'completed',
                        'vars' => $vars,
                        'finished_at' => now(),
                    ])->save();

                    return $emitted;

                case 'branch':
                    if (++$branchJumps > self::MAX_BRANCH_JUMPS) {
                        // Loop-guard: a malformed graph (two branches
                        // pointing at each other) shouldn't hang the
                        // turn. Mark failed + return whatever we've
                        // emitted so far.
                        $run->forceFill([
                            'status' => 'failed',
                            'finished_at' => now(),
                        ])->save();

                        return $emitted;
                    }

                    $target = $this->resolveBranchTarget($step, (array) ($run->vars ?? []), $stepCount);
                    if ($target === null) {
                        // No case matched and no default — fall through
                        // to the next step linearly (safe default).
                        $target = $run->current_step_index + 1;
                    }

                    $run->forceFill(['current_step_index' => $target])->save();
                    break;

                case 'tag_lead':
                    $tags = $this->normalizeTags((array) ($step['tags'] ?? []));
                    if ($tags !== []) {
                        $this->applyTagsToLead($run->conversation_id, $tags);
                    }
                    $run->forceFill(['current_step_index' => $run->current_step_index + 1])->save();
                    break;

                case 'webhook':
                    $url = (string) ($step['url'] ?? '');
                    if ($url !== '') {
                        $payload = array_merge(
                            (array) ($step['extra_payload'] ?? []),
                            [
                                'vars' => (array) ($run->vars ?? []),
                                'conversation_id' => $run->conversation_id,
                                'workflow_id' => $run->workflow_id,
                                'workflow_run_id' => $run->id,
                            ],
                        );
                        DispatchWebhookJob::dispatch(
                            url: $url,
                            method: (string) ($step['method'] ?? 'POST'),
                            payload: $payload,
                        );
                    }
                    $run->forceFill(['current_step_index' => $run->current_step_index + 1])->save();
                    break;

                default:
                    // Unknown step type — skip rather than crash. Older
                    // runtimes reading a Phase-3 definition will end up
                    // here on a step they don't recognise; advancing
                    // keeps the rest of the flow useful.
                    $run->forceFill(['current_step_index' => $run->current_step_index + 1])->save();
                    break;
            }
        }

        // Walked off the end → mark complete.
        $run->forceFill([
            'status' => 'completed',
            'finished_at' => now(),
        ])->save();

        return $emitted;
    }

    /**
     * Evaluate a branch step's cases against the run's vars and
     * return the target step index, or null if nothing matched and
     * no default case was provided.
     *
     * @param  array<string, mixed>  $step
     * @param  array<string, mixed>  $vars
     */
    private function resolveBranchTarget(array $step, array $vars, int $stepCount): ?int
    {
        [$target] = $this->resolveBranchTargetWithCase($step, $vars, $stepCount);

        return $target;
    }

    /**
     * Cap vars payload at 16KB; drop oldest keys first.
     *
     * @param  array<string, mixed>  $vars
     * @return array<string, mixed>
     */
    private function boundVarsPayload(array $vars): array
    {
        $maxBytes = 16384;
        while (strlen((string) json_encode($vars)) > $maxBytes && count($vars) > 1) {
            array_shift($vars);
        }

        return $vars;
    }

    /**
     * @param  array<int, string>  $tags
     */
    private function applyTagsToLead(string $conversationId, array $tags): void
    {
        $lead = Lead::query()
            ->withoutGlobalScopes()
            ->where('conversation_id', $conversationId)
            ->first();

        $conversation = Conversation::query()
            ->withoutGlobalScopes()
            ->find($conversationId);

        if ($lead === null) {
            // No lead form was submitted yet on this conversation.
            // Stub one so the tags survive — the inbox still surfaces
            // an unfilled email row, which is fine for tag-only flows.
            if ($conversation === null) {
                return;
            }
            $lead = Lead::query()->withoutGlobalScopes()->create([
                'conversation_id' => $conversationId,
                'agent_id' => $conversation->agent_id,
                'email' => '',
                'name' => null,
                'phone' => null,
                'fields' => ['tags' => $tags],
                'status' => 'new',
            ]);

            return;
        }

        $existingFields = (array) ($lead->fields ?? []);
        $existingTags = (array) ($existingFields['tags'] ?? []);
        $merged = $this->normalizeTags(array_merge($existingTags, $tags));
        $existingFields['tags'] = $merged;
        $lead->forceFill(['fields' => $existingFields])->save();
    }
}
