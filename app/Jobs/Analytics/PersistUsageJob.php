<?php

namespace App\Jobs\Analytics;

use App\Models\Agent;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\UsageLog;
use App\Support\TokenPricing;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Persist per-call LLM usage AFTER the SSE stream completed. Never run
 * inline — the hot path latency contract forbids any DB write
 * between visitor send → first token. PersistTurnJob already runs in
 * this after-stream slot; we slot alongside it.
 *
 * @property array<int, array<string, mixed>> $calls
 */
class PersistUsageJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * @param  array<int, array{provider: string, model: string, purpose: string, tokens_in?: int, tokens_out?: int, latency_ms?: int}>  $calls
     */
    public function __construct(
        public readonly string $conversationId,
        public readonly string $agentId,
        public readonly ?string $messageId,
        public readonly array $calls,
    ) {
        $this->onQueue('default');
    }

    public function handle(): void
    {
        if ($this->calls === []) {
            return;
        }

        // Look up the workspace via the agent (avoids passing
        // workspace_id through every caller; agent → workspace is a
        // single indexed read).
        $workspaceId = Agent::query()->withoutGlobalScopes()
            ->where('id', $this->agentId)
            ->value('workspace_id');

        if ($workspaceId === null) {
            Log::warning('usage.persist.agent_missing', [
                'agent_id' => $this->agentId,
                'conversation_id' => $this->conversationId,
            ]);

            return;
        }

        // Guard against FK violation when PersistTurnJob failed or the
        // parent row was hard-deleted between dispatch and worker pickup
        // (e.g. agent.forceDelete() cascades through conversations +
        // messages). usage_logs FKs are ON DELETE SET NULL, but INSERT
        // still requires every referenced parent to exist — null them
        // out here when they no longer resolve so the row still persists
        // with the rest of the metric data.
        $messageId = $this->messageId;
        if ($messageId !== null
            && ! Message::query()->whereKey($messageId)->exists()
        ) {
            $messageId = null;
        }

        $conversationId = $this->conversationId;
        if (! Conversation::query()->withoutGlobalScopes()->whereKey($conversationId)->exists()) {
            $conversationId = null;
        }

        foreach ($this->calls as $call) {
            $tokensIn = (int) ($call['tokens_in'] ?? 0);
            $tokensOut = (int) ($call['tokens_out'] ?? 0);
            $provider = (string) ($call['provider'] ?? 'unknown');
            $model = (string) ($call['model'] ?? 'unknown');

            UsageLog::query()->withoutGlobalScopes()->create([
                'workspace_id' => $workspaceId,
                'agent_id' => $this->agentId,
                'conversation_id' => $conversationId,
                'message_id' => $messageId,
                'provider' => $provider,
                'model' => $model,
                'purpose' => (string) ($call['purpose'] ?? 'chat'),
                'tokens_in' => $tokensIn,
                'tokens_out' => $tokensOut,
                'cost_usd_micro' => TokenPricing::costMicros($provider, $model, $tokensIn, $tokensOut),
                'latency_ms' => (int) ($call['latency_ms'] ?? 0),
            ]);
        }
    }
}
