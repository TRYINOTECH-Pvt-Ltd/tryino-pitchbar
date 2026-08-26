<?php

namespace App\Console\Commands;

use App\Models\Agent;
use App\Models\Conversation;
use App\Models\Visitor;
use App\Services\Llm\Contracts\OpenAiClient;
use App\Services\Llm\Fakes\FakeOpenAi;
use App\Services\Widget\WidgetJwt;
use App\Support\HotPathStats;
use App\Support\HotPathTimer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Repeatable real-performance test for the visitor hot path.
 *
 *   php artisan perf:hotpath                  # 10 turns, first published agent
 *   php artisan perf:hotpath --turns=25       # bigger sample
 *   php artisan perf:hotpath --agent=<id>     # specific agent
 *   php artisan perf:hotpath --url=https://example.com  # test a remote install*
 *
 * Drives the REAL `/api/v1/widget/messages/stream` endpoint exactly the
 * way the embedded widget does (JWT bearer + Origin header + SSE), then
 * reads the same ring buffer the latency dashboard uses and prints a
 * per-stage table, p50/p95 aggregates, and the plain-English verdict.
 *
 * *Remote URLs only measure wall-clock TTFB/total — the stage breakdown
 *  comes from the LOCAL ring buffer, so it stays empty when you target
 *  another install. Run the command ON that server for full detail.
 *
 * Run anytime: after a deploy, after switching chat model, after moving
 * the vector store. Numbers in one run are comparable to the next.
 */
class HotPathPerfCommand extends Command
{
    protected $signature = 'perf:hotpath
        {--agent= : Agent ID to test (default: first published agent)}
        {--turns=10 : Number of message turns to send}
        {--url= : Base URL to test (default: config(app.url))}
        {--message=What services do you offer? : Base message; a variant suffix is appended per turn to dodge the retrieve cache}';

    protected $description = 'Send N real widget turns through the hot path and report per-stage latency + verdict';

    public function handle(WidgetJwt $jwt, HotPathStats $stats): int
    {
        $turnsWanted = max(1, (int) $this->option('turns'));
        $baseUrl = rtrim((string) ($this->option('url') ?: config('app.url')), '/');

        // Platform-operator tool: needs an arbitrary agent regardless of
        // tenant scope, same as the super-admin dashboard.
        $agentQuery = Agent::query()->withoutGlobalScopes();
        $agent = $this->option('agent')
            ? $agentQuery->find((string) $this->option('agent'))
            : $agentQuery->where('is_published', true)->first();

        if ($agent === null) {
            $this->error('No agent found. Pass --agent=<id> or publish one first.');

            return self::FAILURE;
        }

        if (app(OpenAiClient::class) instanceof FakeOpenAi) {
            $this->warn('FakeOpenAi is bound (no provider keys configured) — numbers below measure PIPELINE overhead only, not provider latency.');
        }

        $origin = is_array($agent->allowed_origins) && $agent->allowed_origins !== []
            ? (string) $agent->allowed_origins[0]
            : 'https://example.com';

        // Mint a JWT through the same service InitController uses — the
        // stream endpoint sees a turn indistinguishable from a real one.
        $visitor = Visitor::create([
            'agent_id' => $agent->id,
            'anonymous_id' => 'perf_'.Str::random(16),
            'ip_hash' => hash('sha256', 'perf:hotpath'),
            'ua' => 'perf:hotpath',
            'first_seen_at' => now(),
            'last_seen_at' => now(),
            'visit_count' => 1,
        ]);
        $conversation = Conversation::create([
            'agent_id' => $agent->id,
            'visitor_id' => $visitor->id,
            'started_at' => now(),
            'lang' => $agent->language_default,
        ]);
        $issued = $jwt->issue($agent->id, $visitor->id, $conversation->id);

        $this->info("Agent: {$agent->name} ({$agent->id})");
        $this->info("Target: {$baseUrl} · {$turnsWanted} turns");
        $this->newLine();

        $bufferBefore = $this->bufferIds();
        $wall = [];
        $nonce = Str::random(6);

        for ($i = 1; $i <= $turnsWanted; $i++) {
            // Unique suffix per turn so the 30-min retrieve cache doesn't
            // turn the sample into 1 miss + N-1 hits.
            $message = $this->option('message')." (perf {$nonce} variant {$i})";
            $measured = $this->sendTurn($baseUrl, $issued['token'], $origin, $message);

            if (isset($measured['error'])) {
                $this->error("turn {$i}: request failed — {$measured['error']}");
                $this->line('Common causes:');
                $this->line('  · HTTP 403: the agent has no allowed_origins covering "'.$origin.'" — pass --agent=<id> of the agent actually embedded on a site.');
                $this->line('  · HTTP 404: the /api/v1/widget routes are not reachable at '.$baseUrl.' — check APP_URL or pass --url.');
                $this->line('  · HTTP 429: widget rate limit — wait a minute.');
                $this->line('  · SSL/connect errors: pass --url=http://127.0.0.1 to bypass the proxy and test PHP directly.');

                return self::FAILURE;
            }

            $wall[] = $measured;
            $this->line(sprintf(
                'turn %2d: TTFB %4dms · total %4dms',
                $i,
                $measured['ttfb_ms'],
                $measured['total_ms'],
            ));
        }

        $this->newLine();
        $turns = $this->freshBufferTurns($bufferBefore);

        if ($turns === []) {
            $this->warn('No stage breakdown available — the ring buffer did not record these turns (remote --url target, or streaming failed before the timing emit). Wall-clock numbers above still stand.');
        } else {
            $this->table(
                ['stage', 'p50', 'p95', 'max'],
                collect($stats->aggregates($turns))
                    ->map(fn ($a, $stage) => [$stage, $a['p50'].'ms', $a['p95'].'ms', $a['max'].'ms'])
                    ->values()
                    ->all(),
            );
            $this->newLine();
            $this->components->info($stats->verdict($turns));

            // Route split — proves the fast router's knowledge/tool mix.
            $routes = [];
            foreach ($turns as $t) {
                $key = ($t['route'] ?? '') !== '' ? $t['route'].':'.$t['route_reason'] : 'untagged';
                $routes[$key] = ($routes[$key] ?? 0) + 1;
            }
            $this->line('Routes: '.collect($routes)->map(fn ($n, $k) => "{$k} {$n}")->implode(' · '));

            $skipped = count(array_filter($turns, fn ($t) => (bool) ($t['rerank_skipped'] ?? false)));
            if ($skipped > 0) {
                $this->line("Rerank skipped (ANN decisive): {$skipped}/".count($turns).' turns.');
            }
        }

        $ttfbs = array_column($wall, 'ttfb_ms');
        sort($ttfbs);
        $this->line(sprintf(
            'Wall-clock TTFB: p50 %dms · max %dms across %d turns.',
            $ttfbs[(int) floor(count($ttfbs) * 0.5)],
            max($ttfbs),
            count($ttfbs),
        ));

        return self::SUCCESS;
    }

    /**
     * POST one SSE turn, measuring time-to-first-byte and total wall
     * time. Uses raw cURL so we can observe the streaming response the
     * same way a browser does (Laravel's HTTP client buffers).
     *
     * On failure returns ['error' => <human summary>] with the HTTP
     * status and the first bytes of the response body so the operator
     * sees WHY instead of a bare "request failed".
     *
     * @return array{ttfb_ms: int, total_ms: int}|array{error: string}
     */
    private function sendTurn(string $baseUrl, string $token, string $origin, string $message): array
    {
        $ch = curl_init($baseUrl.'/api/v1/widget/messages/stream');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode(['message' => $message], JSON_UNESCAPED_SLASHES),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: text/event-stream',
                'Authorization: Bearer '.$token,
                'Origin: '.$origin,
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_TIMEOUT => 120,
        ]);

        $body = curl_exec($ch);
        $curlError = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $ttfb = (float) curl_getinfo($ch, CURLINFO_STARTTRANSFER_TIME);
        $total = (float) curl_getinfo($ch, CURLINFO_TOTAL_TIME);
        curl_close($ch);

        if ($body === false) {
            return ['error' => 'cURL: '.($curlError !== '' ? $curlError : 'connection failed')];
        }

        if ($status !== 200) {
            $snippet = trim(mb_substr(strip_tags((string) $body), 0, 200));

            return ['error' => "HTTP {$status}".($snippet !== '' ? " — {$snippet}" : '')];
        }

        // SSE stream that ends in `event: error` is an application
        // failure (provider down, bad key) even though HTTP said 200.
        if (str_contains((string) $body, 'event: error')) {
            $errLine = '';
            if (preg_match('/event: error\ndata: (\{.*\})/', (string) $body, $m)) {
                $errLine = ' — '.mb_substr($m[1], 0, 200);
            }

            return ['error' => 'stream returned an error event'.$errLine.' (check storage/logs/laravel.log for widget.stream_unhandled)'];
        }

        return [
            'ttfb_ms' => (int) round($ttfb * 1000),
            'total_ms' => (int) round($total * 1000),
        ];
    }

    /** @return array<int, string> */
    private function bufferIds(): array
    {
        $recent = Cache::get(HotPathTimer::RECENT_CACHE_KEY, []);

        return is_array($recent)
            ? array_map(fn ($t) => ($t['at'] ?? '').'|'.($t['conversation_id'] ?? ''), $recent)
            : [];
    }

    /**
     * Turns added to the ring buffer since the run started, flattened
     * into the row shape HotPathStats expects.
     *
     * @param  array<int, string>  $before
     * @return array<int, array<string, int>>
     */
    private function freshBufferTurns(array $before): array
    {
        $recent = Cache::get(HotPathTimer::RECENT_CACHE_KEY, []);
        if (! is_array($recent)) {
            return [];
        }

        $beforeSet = array_flip($before);
        $fresh = [];
        foreach ($recent as $turn) {
            $key = ($turn['at'] ?? '').'|'.($turn['conversation_id'] ?? '');
            if (isset($beforeSet[$key])) {
                continue;
            }
            $fresh[] = [
                'retrieve_ms' => (int) ($turn['stages']['retrieve_ms'] ?? 0),
                'tool_loop_ms' => (int) ($turn['stages']['tool_loop_ms'] ?? 0),
                'first_token_ms' => (int) ($turn['stages']['first_token_ms'] ?? 0),
                'llm_ms' => (int) ($turn['stages']['llm_ms'] ?? 0),
                'total_ms' => (int) ($turn['stages']['total_ms'] ?? 0),
                'embed_ms' => (int) ($turn['extra']['retrieve_timings']['embed_ms'] ?? 0),
                'ann_ms' => (int) ($turn['extra']['retrieve_timings']['ann_ms'] ?? 0),
                'rerank_ms' => (int) ($turn['extra']['retrieve_timings']['rerank_ms'] ?? 0),
                'rerank_skipped' => (bool) ($turn['extra']['retrieve_timings']['rerank_skipped'] ?? false),
                'route' => (string) ($turn['extra']['route'] ?? ''),
                'route_reason' => (string) ($turn['extra']['route_reason'] ?? ''),
            ];
        }

        return $fresh;
    }
}
