<?php

namespace App\Http\Controllers\Admin;

use App\Models\CronTickLog;
use App\Models\Source;
use App\Models\WorkspaceUser;
use App\Services\Llm\Contracts\OpenAiClient;
use App\Services\Llm\Fakes\FakeOpenAi;
use App\Services\Llm\OpenAiHttpClient;
use App\Services\Llm\WorkersAiClient;
use App\Support\CurrentWorkspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Self-serve diagnostic page that surfaces install-level configuration
 * gaps customers can't infer from the regular UI: missing OPENAI
 * fallback, no Cloudflare creds, queue worker absent, recently failed
 * crawl/index jobs, etc. Read-only — never mutates state.
 *
 * Scoped to the current workspace's agents + sources for tenancy.
 * Pings are non-blocking (no outbound calls) so the page renders in
 * <50ms even on a misconfigured install where the real provider would
 * have timed out.
 */
class SystemHealthController
{
    public function __construct(private CurrentWorkspace $current) {}

    public function __invoke(Request $request): Response
    {
        $user = $request->user();
        abort_if($user === null, 401);

        $workspace = $this->current->get();
        abort_if($workspace === null, 404);

        $membership = WorkspaceUser::query()
            ->where('workspace_id', $workspace->id)
            ->where('user_id', $user->id)
            ->whereNotNull('accepted_at')
            ->first();
        abort_if($membership === null, 403);
        abort_unless(in_array($membership->role, ['owner', 'admin'], true), 403);

        return Inertia::render('app/system-health/index', [
            'llm' => $this->llmStatus(),
            'embedding_fallback' => $this->embeddingFallbackStatus(),
            'cloudflare' => $this->cloudflareStatus(),
            'queue' => $this->queueStatus(),
            'sources' => $this->problematicSources($workspace->id),
            'env' => $this->envSummary(),
        ]);
    }

    /**
     * Live LLM ping. Sends a short non-streaming chat completion to
     * the currently-bound OpenAiClient and surfaces the verbatim
     * error / first content tokens. Lets the admin verify provider
     * + model selection without SSH'ing into the queue worker. Buyer
     * report 2026-05-19: "only Llama models work, others fail" — this
     * endpoint exposes the exact error message so the admin can pin
     * the bad model name without guessing.
     */
    public function probe(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_if($user === null, 401);

        $workspace = $this->current->get();
        abort_if($workspace === null, 404);

        $membership = WorkspaceUser::query()
            ->where('workspace_id', $workspace->id)
            ->where('user_id', $user->id)
            ->whereNotNull('accepted_at')
            ->first();
        abort_if($membership === null, 403);
        abort_unless(in_array($membership->role, ['owner', 'admin'], true), 403);

        $client = app(OpenAiClient::class);
        $started = microtime(true);
        try {
            $result = $client->chatWithTools(
                messages: [
                    ['role' => 'user', 'content' => 'Reply with the single word "ok".'],
                ],
                tools: [],
                opts: ['max_tokens' => 16],
            );
            $elapsedMs = (int) ((microtime(true) - $started) * 1000);

            return response()->json([
                'ok' => true,
                'provider' => $this->llmStatus()['provider'],
                'model' => $this->configuredChatModel(),
                'content' => (string) ($result['content'] ?? ''),
                'elapsed_ms' => $elapsedMs,
            ]);
        } catch (\Throwable $e) {
            $elapsedMs = (int) ((microtime(true) - $started) * 1000);

            return response()->json([
                'ok' => false,
                'provider' => $this->llmStatus()['provider'],
                'model' => $this->configuredChatModel(),
                'exception' => class_basename(get_class($e)),
                'message' => mb_substr($e->getMessage(), 0, 800),
                'elapsed_ms' => $elapsedMs,
            ]);
        }
    }

    private function configuredChatModel(): string
    {
        $provider = $this->llmStatus()['provider'];
        if ($provider === 'cloudflare') {
            return (string) config('services.cloudflare.chat_model', '(default)');
        }
        if ($provider === 'openai_or_openrouter') {
            return (string) (config('services.openai.chat_model') ?: config('services.openrouter.chat_model') ?: '(default)');
        }

        return '(none)';
    }

    /**
     * @return array{provider: string, model: ?string, ok: bool, hint: ?string}
     */
    private function llmStatus(): array
    {
        $client = app(OpenAiClient::class);

        return match (true) {
            $client instanceof WorkersAiClient => [
                'provider' => 'cloudflare',
                'model' => null,
                'ok' => true,
                'hint' => null,
            ],
            $client instanceof OpenAiHttpClient => [
                'provider' => 'openai_or_openrouter',
                'model' => null,
                'ok' => true,
                'hint' => null,
            ],
            $client instanceof FakeOpenAi => [
                'provider' => 'fake',
                'model' => null,
                'ok' => false,
                'hint' => 'No real LLM provider is configured. Replies will be canned. Set CLOUDFLARE_API_TOKEN + CLOUDFLARE_ACCOUNT_ID OR OPENAI_API_KEY in your env.',
            ],
            default => [
                'provider' => 'unknown',
                'model' => null,
                'ok' => false,
                'hint' => 'LLM client resolved to an unrecognised implementation.',
            ],
        };
    }

    /**
     * @return array{configured: bool, hint: ?string}
     */
    private function embeddingFallbackStatus(): array
    {
        $fallback = app('embedding.fallback');

        if ($fallback === null) {
            return [
                'configured' => false,
                'hint' => 'No embedding fallback configured. If Cloudflare Workers AI hits a rate limit, the crawl/index pipeline will fail. Set OPENAI_API_KEY to enable a safety net.',
            ];
        }

        return ['configured' => true, 'hint' => null];
    }

    /**
     * @return array{configured: bool, account_id_present: bool, token_present: bool, hint: ?string}
     */
    private function cloudflareStatus(): array
    {
        $account = (string) config('services.cloudflare.account_id', '');
        $token = (string) config('services.cloudflare.api_token', '');
        $configured = $account !== '' && $token !== '';

        return [
            'configured' => $configured,
            'account_id_present' => $account !== '',
            'token_present' => $token !== '',
            'hint' => $configured
                ? null
                : 'Cloudflare creds missing. Workers AI is the default LLM + embedding provider; without these the install falls back to OpenAI (paid) or the deterministic Fake provider.',
        ];
    }

    /**
     * @return array{
     *   driver: string,
     *   pending: int,
     *   failed: int,
     *   liveness: 'live'|'stale'|'dead'|'unknown'|'inline',
     *   seconds_since_tick: ?int,
     *   last_tick_at: ?string,
     *   hint: ?string
     * }
     */
    private function queueStatus(): array
    {
        $driver = (string) config('queue.default', 'sync');
        $pending = 0;
        $failed = 0;

        try {
            $pending = (int) DB::table('jobs')->count();
        } catch (\Throwable) {
            // jobs table only exists with database driver; sync/redis = 0
        }

        try {
            $failed = (int) DB::table('failed_jobs')->count();
        } catch (\Throwable) {
            // failed_jobs table may not exist
        }

        // Worker liveness via cron_tick_logs. Pitchbar's production
        // model is a Cloudflare Worker that pings /internal/queue-tick
        // every minute; each ping writes a row. If no ping arrived
        // recently, the worker is either crashed or unconfigured.
        // `sync` driver runs inline (no worker needed) — report as
        // "inline" rather than dead.
        $liveness = 'unknown';
        $secondsSinceTick = null;
        $lastTickAt = null;

        if ($driver === 'sync') {
            $liveness = 'inline';
        } else {
            try {
                $latest = CronTickLog::query()->orderByDesc('id')->first();
                if ($latest !== null && $latest->received_at !== null) {
                    $secondsSinceTick = (int) $latest->received_at->diffInSeconds(now());
                    $lastTickAt = $latest->received_at->toIso8601String();
                    $liveness = match (true) {
                        $secondsSinceTick <= 90 => 'live',
                        $secondsSinceTick <= 300 => 'stale',
                        default => 'dead',
                    };
                }
            } catch (\Throwable) {
                // cron_tick_logs may not exist on installs that pre-date it
            }
        }

        $hint = match (true) {
            $driver === 'sync' => 'Queue driver is sync — jobs run inline. Fine for dev; production should use database/redis with a queue:work / queue:listen process or the Cloudflare Worker cron tick.',
            $liveness === 'dead' => sprintf('No queue tick for %d seconds. Worker is down. Check `php artisan queue:work` or the Cloudflare cron worker.', (int) $secondsSinceTick),
            $liveness === 'stale' => sprintf('Last queue tick %d seconds ago — worker may be sleeping or rate-limited.', (int) $secondsSinceTick),
            $liveness === 'unknown' => 'No queue ticks recorded yet. Either the worker has never run or `cron_tick_logs` is empty on a fresh install.',
            $failed > 0 => "There are $failed failed jobs. Check `failed_jobs` table or run `php artisan queue:failed` to inspect.",
            $pending > 200 => "$pending jobs queued — worker may be saturated.",
            default => null,
        };

        return [
            'driver' => $driver,
            'pending' => $pending,
            'failed' => $failed,
            'liveness' => $liveness,
            'seconds_since_tick' => $secondsSinceTick,
            'last_tick_at' => $lastTickAt,
            'hint' => $hint,
        ];
    }

    /**
     * @return array<int, array{id: string, agent_id: string, type: ?string, status: ?string, error: ?string, created_at: ?string}>
     */
    private function problematicSources(string $workspaceId): array
    {
        return Source::query()
            ->withoutWorkspaceScope()
            ->whereHas('agent', fn ($q) => $q->where('workspace_id', $workspaceId)->withoutGlobalScopes())
            ->whereIn('status', ['failed', 'crawling'])
            ->orderByDesc('updated_at')
            ->limit(20)
            ->get(['id', 'agent_id', 'type', 'status', 'error', 'updated_at'])
            ->map(fn (Source $s) => [
                'id' => (string) $s->id,
                'agent_id' => (string) $s->agent_id,
                'type' => $s->type,
                'status' => $s->status,
                'error' => $s->error ? mb_substr((string) $s->error, 0, 400) : null,
                'created_at' => $s->updated_at?->toIso8601String(),
            ])
            ->all();
    }

    /**
     * @return array{app_env: string, app_url: string, app_debug: bool, version: string}
     */
    private function envSummary(): array
    {
        return [
            'app_env' => (string) config('app.env'),
            'app_url' => (string) config('app.url'),
            'app_debug' => (bool) config('app.debug'),
            'version' => (string) (env('VERSION') ?: config('app.version') ?: 'dev'),
        ];
    }
}
