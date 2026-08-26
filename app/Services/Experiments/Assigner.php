<?php

namespace App\Services\Experiments;

use App\Models\Experiment;
use App\Models\ExperimentAssignment;
use App\Models\Variant;

class Assigner
{
    /**
     * Sticky assignment WITH persistence. Looks up existing visitor row;
     * otherwise picks a variant by weighted hash and INSERTs the
     * ExperimentAssignment row. Use this from post-stream / background
     * paths only — it issues 1–2 DB writes.
     *
     * Hot-path callers should use `pick()` instead.
     */
    public function assign(Experiment $experiment, string $visitorId): ?Variant
    {
        if ($experiment->status !== 'running') {
            return null;
        }

        $existing = ExperimentAssignment::query()
            ->where('visitor_id', $visitorId)
            ->where('experiment_id', $experiment->id)
            ->first();

        if ($existing !== null) {
            return Variant::query()->find($existing->variant_id);
        }

        $chosen = $this->pick($experiment, $visitorId);
        if ($chosen === null) {
            return null;
        }

        // firstOrCreate handles the race where two turns persist in
        // parallel — composite primary key is (visitor_id, experiment_id)
        // so the second INSERT would otherwise integrity-violate.
        ExperimentAssignment::query()->firstOrCreate(
            [
                'visitor_id' => $visitorId,
                'experiment_id' => $experiment->id,
            ],
            [
                'variant_id' => $chosen->id,
                'assigned_at' => now(),
            ],
        );

        return $chosen;
    }

    /**
     * Pure variant selection — no DB writes. Hot-path safe. Deterministic
     * for a given `(visitor_id, experiment_id)` pair, so calling pick()
     * before the assignment row is persisted and assign() afterwards
     * yields the SAME variant.
     *
     * Returns null when the experiment is not running, has no variants,
     * or the visitor ID is empty.
     */
    public function pick(Experiment $experiment, string $visitorId): ?Variant
    {
        if ($experiment->status !== 'running') {
            return null;
        }

        if ($visitorId === '') {
            return null;
        }

        $variants = $experiment->variants()->get();
        if ($variants->isEmpty()) {
            return null;
        }

        $totalWeight = (int) $variants->sum('weight') ?: 1;

        // Hash to a number in [0, totalWeight)
        $bucket = hexdec(substr(hash('sha256', $visitorId.':'.$experiment->id), 0, 8)) % $totalWeight;

        $cum = 0;
        foreach ($variants as $v) {
            $cum += (int) $v->weight;
            if ($bucket < $cum) {
                return $v;
            }
        }

        return null;
    }
}
