<?php

namespace App\Services\Billing;

use App\Models\Plan;
use App\Models\PlanSubscription;
use App\Models\UsageEvent;
use App\Models\Workspace;

class MeteredBilling
{
    /**
     * The marketing demo workspace backs the public landing-page widget.
     * It must NEVER hit a plan limit — a 429 on /widget/init there means
     * marketing visitors can't even start a conversation with the demo
     * bot. We exempt it by slug instead of by a boolean column so the
     * exemption survives `php artisan migrate:fresh` + re-seed cycles
     * without schema changes.
     */
    private const EXEMPT_WORKSPACE_SLUGS = ['pitchbar-demo'];

    private function isExempt(Workspace $workspace): bool
    {
        return in_array((string) $workspace->slug, self::EXEMPT_WORKSPACE_SLUGS, true);
    }

    /**
     * Returns true if the workspace can start a new conversation.
     */
    public function canStartConversation(Workspace $workspace): bool
    {
        if ($this->isExempt($workspace)) {
            return true;
        }

        $plan = $workspace->plan ?? Plan::query()->where('slug', 'free')->first();
        if ($plan === null) {
            return true;
        }
        $limit = (int) $plan->monthly_conversations;
        if ($limit === 0) {
            return true; // unlimited (Custom)
        }

        $count = $this->currentMonthUsage($workspace->id);

        return $count < $limit;
    }

    /**
     * Returns true if the workspace can send another visitor message
     * this billing cycle. Falls back to "always allowed" when the plan
     * doesn't carry an explicit `monthly_messages` cap, so existing
     * deployments without the new column keep working.
     */
    public function canSendMessage(Workspace $workspace): bool
    {
        if ($this->isExempt($workspace)) {
            return true;
        }

        $plan = $workspace->plan ?? Plan::query()->where('slug', 'free')->first();
        if ($plan === null) {
            return true;
        }
        $limit = (int) ($plan->monthly_messages ?? 0);
        if ($limit === 0) {
            return true; // null / 0 = no per-message cap, gate stays on monthly_conversations.
        }

        $count = $this->currentMonthMessages($workspace->id);

        return $count < $limit;
    }

    public function currentMonthUsage(string $workspaceId): int
    {
        return (int) UsageEvent::query()
            ->where('workspace_id', $workspaceId)
            ->where('kind', 'conversation')
            ->where('occurred_at', '>=', now()->startOfMonth())
            ->sum('quantity');
    }

    public function currentMonthMessages(string $workspaceId): int
    {
        return (int) UsageEvent::query()
            ->where('workspace_id', $workspaceId)
            ->where('kind', 'message')
            ->where('occurred_at', '>=', now()->startOfMonth())
            ->sum('quantity');
    }

    /**
     * The per-response token ceiling the LLM should use for this
     * workspace. Returns null when the plan doesn't pin a value, so
     * MessageStreamController falls back to the existing 800-token
     * default. We never return below 100 — the model has to have room
     * for at least a one-paragraph reply or the visitor sees garbled
     * mid-sentence cuts.
     */
    public function maxTokensFor(Workspace $workspace): ?int
    {
        $plan = $workspace->plan ?? Plan::query()->where('slug', 'free')->first();
        $cap = (int) ($plan?->max_tokens_per_response ?? 0);

        if ($cap < 100) {
            return null;
        }

        return $cap;
    }

    public function summaryFor(Workspace $workspace): array
    {
        $plan = $workspace->plan ?? Plan::query()->where('slug', 'free')->first();
        $used = $this->currentMonthUsage($workspace->id);
        $messagesUsed = $this->currentMonthMessages($workspace->id);
        $limit = (int) ($plan->monthly_conversations ?? 0);
        $messageLimit = (int) ($plan->monthly_messages ?? 0);

        return [
            'plan' => $plan?->only(
                'id',
                'name',
                'slug',
                'monthly_conversations',
                'monthly_messages',
                'max_tokens_per_response',
                'price_cents',
            ),
            'used' => $used,
            'limit' => $limit,
            'percent' => $limit === 0 ? 0 : (int) min(100, round(($used / $limit) * 100)),
            'messages_used' => $messagesUsed,
            'messages_limit' => $messageLimit,
            'messages_percent' => $messageLimit === 0 ? 0 : (int) min(100, round(($messagesUsed / $messageLimit) * 100)),
            'subscription_status' => PlanSubscription::query()
                ->where('workspace_id', $workspace->id)
                ->value('status'),
        ];
    }
}
