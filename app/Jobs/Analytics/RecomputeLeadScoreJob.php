<?php

namespace App\Jobs\Analytics;

use App\Models\Conversation;
use App\Services\Scoring\LeadScoringEngine;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Queued, idempotent recompute of `conversations.lead_score` /
 * `lead_score_bucket` for a single conversation. Fires from:
 *  - InitController (new VisitorPageView row)
 *  - PersistTurnJob (new message)
 *  - LeadController::store (lead just captured)
 *
 * Off the hot path by design — first-token latency must not include
 * scoring overhead.
 */
class RecomputeLeadScoreJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public string $conversationId)
    {
        $this->onQueue('analytics');
    }

    public function handle(LeadScoringEngine $engine): void
    {
        $conversation = Conversation::query()->withoutWorkspaceScope()->find($this->conversationId);
        if ($conversation === null) {
            return;
        }

        $result = $engine->compute($conversation);

        $conversation->forceFill([
            'lead_score' => $result['score'],
            'lead_score_bucket' => $result['bucket'],
            'lead_score_reasons' => $result['reasons'],
            'lead_score_updated_at' => now(),
        ])->save();
    }
}
