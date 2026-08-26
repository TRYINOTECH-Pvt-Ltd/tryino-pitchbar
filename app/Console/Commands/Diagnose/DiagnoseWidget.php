<?php

namespace App\Console\Commands\Diagnose;

use App\Models\Agent;
use App\Models\AppSetting;
use App\Services\Llm\Contracts\OpenAiClient;
use App\Services\Llm\Fakes\FakeOpenAi;
use App\Services\Llm\WorkersAiClient;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Production-readable diagnostic for "widget is not responding".
 * Walks the full hot-path config + reaches Cloudflare with a tiny
 * embedding call so the operator can see which step actually breaks
 * without grepping laravel.log. Read-only — no rows written, no
 * settings changed.
 *
 *   php artisan pitchbar:diagnose-widget
 */
#[Signature('pitchbar:diagnose-widget {--agent= : Agent ID to inspect (defaults to first published agent)}')]
#[Description('Walk the widget hot path and report config / credential / LLM reachability state.')]
class DiagnoseWidget extends Command
{
    public function handle(): int
    {
        $this->line('');
        $this->line('<options=bold>Pitchbar widget diagnostic</> · '.now()->toIso8601String());
        $this->line('');

        // ── Step 1: AppSetting row ───────────────────────────────────
        try {
            $s = AppSetting::singleton();
            $this->ok('AppSetting row resolves');
        } catch (\Throwable $e) {
            $this->bad("AppSetting row read failed: {$e->getMessage()}");

            return self::FAILURE;
        }

        // ── Step 2: Cloudflare credentials ───────────────────────────
        $cfAccount = (string) ($s->cloudflare_account_id ?? '');
        $cfToken = (string) ($s->cloudflare_api_token ?? '');
        $envAccount = (string) env('CLOUDFLARE_ACCOUNT_ID', '');
        $envToken = (string) env('CLOUDFLARE_API_TOKEN', '');

        $cfConfigured = $cfAccount !== '' && $cfToken !== '';
        if ($cfConfigured) {
            $this->ok('Cloudflare credentials present in app_settings');
        } elseif ($envAccount !== '' && $envToken !== '') {
            $this->meh('Cloudflare credentials in .env but NOT in app_settings — run migrations.');
            $this->line('       hint: php artisan migrate');
        } else {
            $this->bad('Cloudflare credentials missing — admin must set them at /settings/system?section=ai');
        }

        // ── Step 3: config() hydration ───────────────────────────────
        $cAccount = (string) config('services.cloudflare.account_id', '');
        $cToken = (string) config('services.cloudflare.api_token', '');
        if ($cAccount !== '' && $cToken !== '') {
            $this->ok("config('services.cloudflare.*') populated");
        } else {
            $this->bad("config('services.cloudflare.*') empty — AppSettingsOverrideServiceProvider may not be booted.");
        }

        // ── Step 4: LLM client binding ───────────────────────────────
        $client = app(OpenAiClient::class);
        $clientClass = get_class($client);

        if ($client instanceof WorkersAiClient) {
            $this->ok('OpenAiClient resolved to WorkersAiClient');
        } elseif ($client instanceof FakeOpenAi) {
            $this->bad('OpenAiClient resolved to FakeOpenAi — visitor will see canned replies, not real LLM output.');
        } else {
            $this->line("       OpenAiClient resolved to {$clientClass}");
        }

        // ── Step 5: Reach Cloudflare Workers AI ──────────────────────
        if ($cfConfigured) {
            try {
                $embeddings = $client->embed(['diagnostic']);
                if (! empty($embeddings) && is_array($embeddings[0] ?? null)) {
                    $this->ok('Cloudflare Workers AI embed call succeeded ('.count($embeddings[0]).' dims)');
                } else {
                    $this->meh('Embed call returned empty payload.');
                }
            } catch (\Throwable $e) {
                $this->bad("Cloudflare Workers AI embed call FAILED: {$e->getMessage()}");
            }
        }

        // ── Step 6: Agent + allowed_origins ──────────────────────────
        $agentId = $this->option('agent');
        $agent = $agentId !== null
            ? Agent::query()->withoutGlobalScopes()->find($agentId)
            : Agent::query()->withoutGlobalScopes()->where('is_published', true)->first();

        if ($agent === null) {
            $this->meh('No published agent found — visitors cannot start chat. Publish one at /app/agents/{id}.');
        } else {
            $this->ok("Agent OK: {$agent->name} ({$agent->id}), published");
            $origins = (array) ($agent->allowed_origins ?? []);
            if ($origins === []) {
                $this->bad('Agent has empty allowed_origins — every /widget/init request will 403.');
            } else {
                $this->ok('allowed_origins: '.implode(', ', $origins));
            }
        }

        // ── Step 7: Provider routing ─────────────────────────────────
        $llm = (string) config('services.llm.provider', '');
        $vec = (string) config('services.vector.provider', '');
        $this->line('       services.llm.provider     = '.($llm ?: '(auto)'));
        $this->line('       services.vector.provider  = '.($vec ?: '(auto)'));

        // ── Step 8: Query rewrite (card #485) ────────────────────────
        // Enabling this hands the retrieval query to a small LLM instead
        // of the deterministic stitch. It silently overrode the stitch on
        // a live install and cost a deploy cycle to find, so its state is
        // now visible here rather than only in .env.
        $rewrite = (array) config('services.rag.query_rewrite', []);
        if ((bool) ($rewrite['enabled'] ?? false)) {
            $model = trim((string) ($rewrite['model'] ?? ''));
            if ($model === '') {
                $this->meh('RAG_QUERY_REWRITE is on with no RAG_QUERY_REWRITE_MODEL — the rewrite runs on the main chat model, adding its full latency to every context-dependent turn. Set a small fast model, or turn the rewrite off.');
            } else {
                $this->ok("query rewrite: on ({$model})");
            }
        } else {
            $this->ok('query rewrite: off (deterministic stitch)');
        }

        // ── Step 9: Cron tick (card #490) ────────────────────────────
        // The tick exists for hosts with no queue daemon. Where a daemon
        // already runs it is redundant, and it used to poison the Octane
        // worker outright — so its state belongs in the diagnostic.
        $tickToken = '';
        try {
            $tickToken = (string) (AppSetting::singleton()->internal_queue_token ?? '');
        } catch (\Throwable) {
            // Fresh install without the settings table.
        }
        if ($tickToken === '') {
            $tickToken = (string) (config('services.internal.queue_token') ?: '');
        }
        if ($tickToken !== '') {
            $this->meh('Cron queue-tick is ENABLED. It runs the queue worker in a subprocess (safe since #490), but if this host already runs a queue daemon (PM2/supervisor) the tick is redundant — clear the token under System Settings → Cron worker.');
        } else {
            $this->ok('cron queue-tick: off (a queue daemon must be running)');
        }

        $this->line('');
        $this->line('Done. If any line above is red, fix that first then re-run.');

        return self::SUCCESS;
    }

    private function ok(string $line): void
    {
        $this->line("  <fg=green>✓</> {$line}");
    }

    private function meh(string $line): void
    {
        $this->line("  <fg=yellow>!</> {$line}");
    }

    private function bad(string $line): void
    {
        $this->line("  <fg=red>✗</> {$line}");
    }
}
