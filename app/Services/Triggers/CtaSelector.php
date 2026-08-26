<?php

namespace App\Services\Triggers;

use App\Models\Conversation;
use App\Models\CtaRule;
use App\Models\Workspace;
use App\Support\CtaContextSigner;
use Illuminate\Support\Facades\Cache;

/**
 * Picks up to MAX_CTAS matching CTAs to surface alongside an assistant
 * turn. Pre-fix this returned at most one — customer feedback (Dovydas)
 * said "I added 3 CTAs but only one shows up" because we picked the
 * first match and never surfaced the rest. Now we return every match in
 * priority order so all configured CTAs that satisfy their conditions
 * render together as a stacked card list.
 *
 * `select()` (singular) is kept for backwards compatibility — it
 * returns the first match, same as before — so any caller / test that
 * still expects a single CTA keeps working.
 */
class CtaSelector
{
    public const MAX_CTAS = 3;

    /**
     * @return array{label: string, kind: string, url: ?string}|null
     */
    public function select(Conversation $conversation, string $assistantText): ?array
    {
        $all = $this->selectAll($conversation, $assistantText);

        return $all[0] ?? null;
    }

    /**
     * @return array<int, array{label: string, kind: string, url: ?string}>
     */
    public function selectAll(Conversation $conversation, string $assistantText): array
    {
        $rules = $this->loadRules($conversation->agent_id);
        if ($rules === []) {
            return [];
        }

        $pageUrl = (string) ($conversation->page_url ?? '');
        $lang = (string) ($conversation->lang ?? '');

        $picks = [];
        foreach ($rules as $rule) {
            if (! $this->matches($rule, $pageUrl, $lang, $assistantText)) {
                continue;
            }

            $target = (array) ($rule['target'] ?? []);
            $url = isset($target['url']) ? (string) $target['url'] : null;

            // D2: `forward_context` on the target opts the URL into the
            // signed-payload flow. Operators pick the subset of fields
            // to forward via `context_fields`; nothing leaks without
            // explicit opt-in.
            $forwardFields = (array) ($target['context_fields'] ?? []);
            if (
                $url !== null
                && ! empty($target['forward_context'])
                && $forwardFields !== []
            ) {
                $workspace = $this->workspaceFor($conversation);
                if ($workspace !== null) {
                    $url = app(CtaContextSigner::class)
                        ->buildSignedUrl($url, $forwardFields, $conversation, $workspace);
                }
            }

            $picks[] = [
                'label' => (string) $rule['label'],
                'kind' => (string) $rule['kind'],
                'url' => $url,
            ];

            if (count($picks) >= self::MAX_CTAS) {
                break;
            }
        }

        return $picks;
    }

    private function workspaceFor(Conversation $conversation): ?Workspace
    {
        $agent = $conversation->agent()->withoutGlobalScopes()->first();
        if ($agent === null) {
            return null;
        }

        return Workspace::query()->withoutGlobalScopes()->find($agent->workspace_id);
    }

    /**
     * @return array<int, array{kind: string, label: string, conditions: array, target: array, priority: int}>
     */
    private function loadRules(string $agentId): array
    {
        return Cache::remember("cta_rules:{$agentId}", 60, function () use ($agentId) {
            return CtaRule::query()->withoutWorkspaceScope()
                ->where('agent_id', $agentId)
                ->where('enabled', true)
                ->orderByDesc('priority')
                ->get(['kind', 'label', 'conditions', 'target', 'priority'])
                ->map(fn ($r) => [
                    'kind' => $r->kind,
                    'label' => $r->label,
                    'conditions' => (array) ($r->conditions ?? []),
                    'target' => (array) ($r->target ?? []),
                    'priority' => (int) $r->priority,
                ])
                ->all();
        });
    }

    /**
     * @param  array{kind: string, label: string, conditions: array, target: array, priority: int}  $rule
     */
    private function matches(array $rule, string $pageUrl, string $lang, string $assistantText): bool
    {
        $conditions = $rule['conditions'];

        if (! empty($conditions['url_contains']) && is_string($conditions['url_contains'])) {
            if (! str_contains($pageUrl, $conditions['url_contains'])) {
                return false;
            }
        }

        if (! empty($conditions['lang']) && is_string($conditions['lang'])) {
            if ($lang !== '' && $lang !== $conditions['lang']) {
                return false;
            }
        }

        if (! empty($conditions['intent_keywords']) && is_array($conditions['intent_keywords'])) {
            $assistantLower = mb_strtolower($assistantText);
            $hit = false;
            foreach ($conditions['intent_keywords'] as $kw) {
                if (! is_string($kw)) {
                    continue;
                }
                if (str_contains($assistantLower, mb_strtolower($kw))) {
                    $hit = true;
                    break;
                }
            }
            if (! $hit) {
                return false;
            }
        }

        return true;
    }
}
