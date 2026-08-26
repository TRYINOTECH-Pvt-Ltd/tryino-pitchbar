<?php

namespace App\Jobs\Workflows;

use App\Support\UrlSafetyGuard;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Outbound webhook from a `webhook` workflow step. Fire-and-forget;
 * this is queued so the SSE turn that triggered the workflow never
 * waits on the customer's slow webhook URL.
 *
 * `$tries = 1` (audit 2026-05-16): retrying an HTTP POST to a customer
 * URL without an idempotency key duplicates side effects on the
 * receiver. Buyer's Zapier / Make / n8n endpoints typically don't
 * dedupe — so 3 retries = 3 records, 3 emails, 3 leads. Single-shot
 * is the safer default for webhook fan-out. Failures still land in
 * `failed_jobs` for inspection at /admin/jobs/failed; the failed()
 * handler logs the destination and HTTP outcome so the operator can
 * see WHICH webhook failed.
 */
class DispatchWebhookJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 15;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public string $url,
        public string $method,
        public array $payload,
    ) {}

    public function handle(UrlSafetyGuard $urlGuard): void
    {
        // SSRF guard — admin-configured URL must not target internal hosts.
        if (! $urlGuard->isSafe($this->url, resolveHostnames: true)) {
            Log::warning('workflow.webhook_blocked_unsafe_url', [
                'url_host' => parse_url($this->url, PHP_URL_HOST),
                'method' => $this->method,
            ]);

            return;
        }

        $method = strtoupper($this->method) === 'GET' ? 'GET' : 'POST';

        $request = Http::timeout(10)
            ->acceptJson()
            ->withUserAgent('PitchbarWorkflow/1.0')
            ->withHeaders(['Content-Type' => 'application/json']);

        try {
            $response = $method === 'GET'
                ? $request->get($this->url, $this->payload)
                : $request->post($this->url, $this->payload);

            if ($response->failed()) {
                // Don't throw — we're $tries=1 and we want failed_jobs
                // to record context, not crash on every 4xx. Log the
                // HTTP outcome and exit clean so the row hits
                // failed_jobs with a meaningful message via failed().
                throw new \RuntimeException(sprintf(
                    'Webhook %s %s returned HTTP %d',
                    $method,
                    $this->redactUrl(),
                    $response->status(),
                ));
            }
        } catch (Throwable $e) {
            Log::warning('workflow.webhook_dispatch_failed', [
                'url_host' => parse_url($this->url, PHP_URL_HOST),
                'method' => $method,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function failed(Throwable $e): void
    {
        Log::error('workflow.webhook_failed_final', [
            'url_host' => parse_url($this->url, PHP_URL_HOST),
            'method' => $this->method,
            'error' => $e->getMessage(),
        ]);
    }

    /**
     * Strip query string + path from the URL for log lines so a token
     * in the URL (e.g. signed Zapier webhook trigger keys) doesn't
     * leak into operator-facing logs / failed_jobs ledger.
     */
    private function redactUrl(): string
    {
        $host = parse_url($this->url, PHP_URL_HOST) ?: 'unknown';
        $scheme = parse_url($this->url, PHP_URL_SCHEME) ?: 'https';

        return "{$scheme}://{$host}/…";
    }
}
