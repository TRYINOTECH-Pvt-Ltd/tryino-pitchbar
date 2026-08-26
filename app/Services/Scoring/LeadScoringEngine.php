<?php

namespace App\Services\Scoring;

use App\Models\Conversation;
use App\Models\Lead;
use App\Models\VisitorPageView;
use Illuminate\Support\Collection;

/**
 * Computes a 0..100 lead score from observable signals on the
 * conversation + the visitor's browsing trajectory. Pure read-only
 * service — does NOT write the score back; callers (job) persist.
 *
 * Score components (all capped, then summed):
 *   - unique_pages   : min(5 × distinct URLs, 25)
 *   - intent_pages   : min(15 × URL substring matches, 30)
 *   - engagement     : min(2 × message_count, 30)
 *   - lead_captured  : 15 when a Lead row with email exists
 *
 * Total clamps to [0, 100]. Bucket: <40 low, 40-69 medium, 70+ high.
 *
 * Adding a new signal:
 *   1. Add a private compute*() returning ['score' => int, 'reasons' => string[]]
 *   2. Add it to compute()'s parts array
 *   3. Update WEIGHT_CAP_TOTAL if the cap exceeds the current 100
 *   4. Add a Pest unit test asserting its contribution
 */
class LeadScoringEngine
{
    public const INTENT_KEYWORDS = [
        'pricing', 'price', 'demo', 'contact', 'buy', 'signup',
        'sign-up', 'checkout', 'cart', 'purchase', 'order',
    ];

    public const BUCKET_LOW = 'low';

    public const BUCKET_MEDIUM = 'medium';

    public const BUCKET_HIGH = 'high';

    /**
     * Cap on how many page views the engine pulls into memory. Both
     * `unique pages` and `intent pages` weights saturate well before
     * 200 rows, so the most recent 200 views are a sufficient sample
     * for scoring. Without this cap a long-lived visitor with
     * thousands of page views would load the full history on every
     * recompute.
     */
    public const MAX_PAGE_VIEWS = 200;

    /**
     * @return array{score: int, bucket: string, reasons: array<int, string>}
     */
    public function compute(Conversation $conversation): array
    {
        $views = VisitorPageView::query()->withoutWorkspaceScope()
            ->where('visitor_id', $conversation->visitor_id)
            ->orderByDesc('viewed_at')
            ->limit(self::MAX_PAGE_VIEWS)
            ->get(['url', 'viewed_at']);

        $lead = Lead::query()->withoutWorkspaceScope()
            ->where('conversation_id', $conversation->id)
            ->first();

        $parts = [
            $this->scoreUniquePages($views),
            $this->scoreIntentPages($views),
            $this->scoreEngagement($conversation),
            $this->scoreLeadCaptured($lead),
        ];

        $total = 0;
        $reasons = [];
        foreach ($parts as $part) {
            $total += $part['score'];
            if ($part['score'] > 0) {
                $reasons = array_merge($reasons, $part['reasons']);
            }
        }

        $clamped = max(0, min(100, $total));

        return [
            'score' => $clamped,
            'bucket' => $this->bucketFor($clamped),
            'reasons' => $reasons,
        ];
    }

    public function bucketFor(int $score): string
    {
        if ($score >= 70) {
            return self::BUCKET_HIGH;
        }
        if ($score >= 40) {
            return self::BUCKET_MEDIUM;
        }

        return self::BUCKET_LOW;
    }

    /**
     * @param  Collection<int, VisitorPageView>  $views
     * @return array{score: int, reasons: array<int, string>}
     */
    private function scoreUniquePages(Collection $views): array
    {
        $unique = $views->pluck('url')->unique()->count();
        $score = min(5 * $unique, 25);
        if ($score === 0) {
            return ['score' => 0, 'reasons' => []];
        }

        return [
            'score' => $score,
            'reasons' => [sprintf('Visited %d unique page%s (+%d)', $unique, $unique === 1 ? '' : 's', $score)],
        ];
    }

    /**
     * @param  Collection<int, VisitorPageView>  $views
     * @return array{score: int, reasons: array<int, string>}
     */
    private function scoreIntentPages(Collection $views): array
    {
        $matches = 0;
        $hit = [];
        foreach ($views as $view) {
            $url = strtolower((string) $view->url);
            foreach (self::INTENT_KEYWORDS as $kw) {
                if (str_contains($url, $kw)) {
                    $matches++;
                    $hit[$kw] = true;
                    break;
                }
            }
        }
        $score = min(15 * $matches, 30);
        if ($score === 0) {
            return ['score' => 0, 'reasons' => []];
        }

        return [
            'score' => $score,
            'reasons' => [sprintf(
                'Visited %d intent page%s (%s) (+%d)',
                $matches,
                $matches === 1 ? '' : 's',
                implode(', ', array_keys($hit)),
                $score,
            )],
        ];
    }

    /**
     * @return array{score: int, reasons: array<int, string>}
     */
    private function scoreEngagement(Conversation $conversation): array
    {
        $messages = (int) ($conversation->message_count ?? 0);
        $score = min(2 * $messages, 30);
        if ($score === 0) {
            return ['score' => 0, 'reasons' => []];
        }

        return [
            'score' => $score,
            'reasons' => [sprintf('Sent / received %d message%s (+%d)', $messages, $messages === 1 ? '' : 's', $score)],
        ];
    }

    /**
     * @return array{score: int, reasons: array<int, string>}
     */
    private function scoreLeadCaptured(?Lead $lead): array
    {
        if ($lead === null || empty($lead->email)) {
            return ['score' => 0, 'reasons' => []];
        }

        return [
            'score' => 15,
            'reasons' => ['Contact info captured (+15)'],
        ];
    }
}
