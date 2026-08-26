<?php

namespace App\Jobs\Analytics;

use App\Models\Agent;
use App\Services\Vertical\SiteTypeDetector;
use GuzzleHttp\Client as Guzzle;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

/**
 * Best-effort site-type detection for a newly created agent. Fetches
 * the first non-wildcard allowed_origin, runs SiteTypeDetector, and
 * persists the resolved vertical onto the agent so the LLM is
 * immediately instructed to emit `<product/>`, `<pricing/>`,
 * `<case-study/>` markers when relevant.
 *
 * Skips when:
 *  - agent already has a non-null site_type (admin picked one)
 *  - allowed_origins is empty or only contains '*'
 *  - homepage fetch fails (auth wall, SPA SSR, 4xx/5xx)
 *  - detector returns 'generic' (no signal)
 *
 * Never fails the agent-create flow.
 */
class DetectAgentSiteTypeJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 15;

    public function __construct(public string $agentId)
    {
        $this->onQueue('analytics');
    }

    public function handle(SiteTypeDetector $detector, Guzzle $http): void
    {
        $agent = Agent::query()->withoutGlobalScopes()->find($this->agentId);
        if ($agent === null) {
            return;
        }

        if ($agent->site_type !== null && $agent->site_type !== '') {
            return;
        }

        $url = $this->firstUsableOrigin((array) ($agent->allowed_origins ?? []));
        if ($url === null) {
            return;
        }

        try {
            $response = $http->get($url, [
                'timeout' => 5,
                'connect_timeout' => 3,
                'http_errors' => false,
                'allow_redirects' => true,
                'headers' => [
                    'User-Agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
                    'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                    'Accept-Language' => 'en-US,en;q=0.9',
                ],
            ]);
        } catch (\Throwable) {
            return;
        }

        if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
            return;
        }

        $html = (string) $response->getBody();
        $result = $detector->detect($html, $url);
        $type = (string) ($result['type'] ?? 'generic');

        // Always record the attempt so the UI can tell "detector ran +
        // returned generic" apart from "never attempted". Without this
        // marker the agents/show banner would re-prompt the operator
        // even after auto-detect already failed to resolve anything
        // useful, which is noisy.
        $signals = array_merge($result, [
            'detected_at' => now()->toIso8601String(),
            'detected_url' => $url,
            'auto' => true,
        ]);

        // Race-safe write. Pre-fix: between the initial fetch (line 44)
        // and the post-detect refresh, the admin could save a manual
        // site_type via /app/agents/{id}/vertical; the in-memory copy
        // was stale and we'd clobber the admin's choice. Wrap the
        // re-read + write in a single DB transaction with a row-level
        // lock so a concurrent admin save either commits before we
        // read (we then bail) or blocks until we commit (admin's
        // request is dispatched against fresh state).
        DB::transaction(function () use ($type, $signals) {
            $fresh = Agent::query()
                ->withoutGlobalScopes()
                ->whereKey($this->agentId)
                ->lockForUpdate()
                ->first();

            if ($fresh === null) {
                return;
            }

            if ($fresh->site_type !== null && $fresh->site_type !== '') {
                // Admin set it manually in the meantime — don't clobber.
                return;
            }

            if ($type === 'generic' || $type === '') {
                $fresh->forceFill(['vertical_signals' => $signals])->save();

                return;
            }

            $fresh->forceFill([
                'site_type' => $type,
                'vertical_signals' => $signals,
            ])->save();
        });
    }

    private function firstUsableOrigin(array $origins): ?string
    {
        foreach ($origins as $o) {
            if (! is_string($o) || $o === '' || $o === '*') {
                continue;
            }
            $u = filter_var($o, FILTER_VALIDATE_URL);
            if ($u === false) {
                continue;
            }
            $scheme = parse_url($u, PHP_URL_SCHEME);
            if ($scheme !== 'http' && $scheme !== 'https') {
                continue;
            }

            return $u;
        }

        return null;
    }
}
