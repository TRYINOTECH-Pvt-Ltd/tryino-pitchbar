<?php

namespace App\Jobs\Analytics;

use App\Models\ContentGap;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class DetectGapJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 10;

    public int $tries = 1;

    public function __construct(public string $agentId, public string $question)
    {
        $this->onQueue('analytics');
    }

    public function handle(): void
    {
        $normalized = mb_strtolower(trim(preg_replace('/[\p{P}\p{S}]+/u', ' ', $this->question) ?? $this->question));
        $hash = hash('sha256', $normalized);

        $existing = ContentGap::query()->withoutWorkspaceScope()
            ->where('agent_id', $this->agentId)
            ->where('question_hash', $hash)
            ->first();

        if ($existing !== null) {
            $existing->forceFill([
                'occurrences' => $existing->occurrences + 1,
                'last_seen_at' => now(),
            ])->save();

            return;
        }

        ContentGap::create([
            'agent_id' => $this->agentId,
            'question' => $this->question,
            'question_hash' => $hash,
            'occurrences' => 1,
            'last_seen_at' => now(),
            'status' => 'open',
        ]);
    }
}
