<?php

namespace App\Services\Cloudflare;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

/**
 * Auto-deploys the Pitchbar queue-tick Cron Worker to a customer's
 * Cloudflare account using REST APIs.
 *
 * One Worker per Pitchbar install — the install's CLOUDFLARE_ACCOUNT_ID
 * + CLOUDFLARE_API_TOKEN (already configured for Workers AI / Vectorize)
 * is reused. The Worker is named based on the app URL host so re-deploys
 * idempotently overwrite the same script and don't pile up.
 *
 * The Cloudflare API token must include these scopes:
 *   - Account → Workers Scripts → Edit
 *   - Account → Workers Routes → Edit (for cron triggers)
 *
 * The deployed Worker:
 *   1. Runs on a 1-minute cron schedule (`* * * * *`)
 *   2. POSTs to {APP_URL}/api/v1/internal/queue-tick with the shared
 *      INTERNAL_QUEUE_TOKEN as `X-Pitchbar-Token`.
 *   3. Logs the response so the admin can debug from the Cloudflare
 *      dashboard if the cron starts failing.
 */
class WorkerDeployer
{
    private const COMPATIBILITY_DATE = '2025-01-01';

    private const CRON_SCHEDULE = '* * * * *';

    private const API_BASE = 'https://api.cloudflare.com/client/v4';

    public function __construct(private Client $http) {}

    /**
     * Idempotent: uploads / overwrites the Worker, sets secrets, sets cron.
     *
     * @param  string  $accountId  Cloudflare account id
     * @param  string  $apiToken  Cloudflare API token (Workers Scripts:Edit)
     * @param  string  $workerName  Stable name (e.g. "pitchbar-tick-{slug}")
     * @param  string  $callbackUrl  Full URL the Worker should POST to
     * @param  string  $sharedToken  Shared secret the Worker sends as auth
     * @return array{ok: bool, worker_url: string, deployed_at: string}
     */
    public function deploy(
        string $accountId,
        string $apiToken,
        string $workerName,
        string $callbackUrl,
        string $sharedToken,
    ): array {
        $this->guardConfig($accountId, $apiToken);

        // 1) Upload the Worker module. We embed plain_text bindings for
        // the callback URL (non-secret) inline in the metadata.
        $script = $this->workerScript();
        $metadata = [
            'main_module' => 'worker.js',
            'compatibility_date' => self::COMPATIBILITY_DATE,
            'bindings' => [
                [
                    'name' => 'LARAVEL_QUEUE_TICK_URL',
                    'type' => 'plain_text',
                    'text' => $callbackUrl,
                ],
            ],
        ];

        try {
            $boundary = 'pitchbar'.bin2hex(random_bytes(8));
            $body = $this->multipart($boundary, [
                ['name' => 'metadata', 'filename' => 'metadata.json', 'content_type' => 'application/json', 'body' => json_encode($metadata, JSON_UNESCAPED_SLASHES)],
                ['name' => 'worker.js', 'filename' => 'worker.js', 'content_type' => 'application/javascript+module', 'body' => $script],
            ]);

            $resp = $this->http->put(
                self::API_BASE."/accounts/{$accountId}/workers/scripts/{$workerName}",
                [
                    'headers' => $this->headers($apiToken) + [
                        'Content-Type' => "multipart/form-data; boundary={$boundary}",
                    ],
                    'body' => $body,
                    'http_errors' => false,
                ],
            );
            $this->assertOk($resp, 'upload worker');

            // 2) Set the secret token. Secrets MUST be set via the
            // dedicated /secrets endpoint, not inline bindings.
            $resp = $this->http->put(
                self::API_BASE."/accounts/{$accountId}/workers/scripts/{$workerName}/secrets",
                [
                    'headers' => $this->headers($apiToken) + ['Content-Type' => 'application/json'],
                    'json' => [
                        'name' => 'INTERNAL_QUEUE_TOKEN',
                        'text' => $sharedToken,
                        'type' => 'secret_text',
                    ],
                    'http_errors' => false,
                ],
            );
            $this->assertOk($resp, 'set secret');

            // 3) Configure the cron schedule.
            $resp = $this->http->put(
                self::API_BASE."/accounts/{$accountId}/workers/scripts/{$workerName}/schedules",
                [
                    'headers' => $this->headers($apiToken) + ['Content-Type' => 'application/json'],
                    'json' => [['cron' => self::CRON_SCHEDULE]],
                    'http_errors' => false,
                ],
            );
            $this->assertOk($resp, 'set cron schedule');
        } catch (GuzzleException $e) {
            throw new RuntimeException('Cloudflare API call failed: '.$e->getMessage(), 0, $e);
        }

        return [
            'ok' => true,
            'worker_url' => "https://dash.cloudflare.com/{$accountId}/workers/services/view/{$workerName}",
            'deployed_at' => now()->toIso8601String(),
        ];
    }

    /**
     * @return array{exists: bool, schedules: array<int, string>, last_invocations: int|null}
     */
    public function status(string $accountId, string $apiToken, string $workerName): array
    {
        $this->guardConfig($accountId, $apiToken);

        // GET /workers/scripts/{name} returns 404 when the worker
        // doesn't exist — that's how we know it's not deployed.
        try {
            $resp = $this->http->get(
                self::API_BASE."/accounts/{$accountId}/workers/scripts/{$workerName}",
                [
                    'headers' => $this->headers($apiToken),
                    'http_errors' => false,
                ],
            );
        } catch (GuzzleException $e) {
            return ['exists' => false, 'schedules' => [], 'last_invocations' => null];
        }

        if ($resp->getStatusCode() === 404) {
            return ['exists' => false, 'schedules' => [], 'last_invocations' => null];
        }

        if ($resp->getStatusCode() >= 400) {
            // Token may lack permission — surface as "unknown" rather
            // than silently saying "not deployed".
            throw new RuntimeException('Cloudflare status check failed: HTTP '.$resp->getStatusCode().' '.((string) $resp->getBody()));
        }

        $schedules = [];
        try {
            $schedResp = $this->http->get(
                self::API_BASE."/accounts/{$accountId}/workers/scripts/{$workerName}/schedules",
                ['headers' => $this->headers($apiToken), 'http_errors' => false],
            );
            if ($schedResp->getStatusCode() < 400) {
                $body = json_decode((string) $schedResp->getBody(), true);
                $list = $body['result']['schedules'] ?? $body['result'] ?? [];
                foreach ((array) $list as $s) {
                    if (is_array($s) && isset($s['cron'])) {
                        $schedules[] = (string) $s['cron'];
                    }
                }
            }
        } catch (GuzzleException) {
            // schedule lookup is best-effort
        }

        return [
            'exists' => true,
            'schedules' => $schedules,
            'last_invocations' => null, // CF analytics requires a separate API; out of scope here.
        ];
    }

    public function destroy(string $accountId, string $apiToken, string $workerName): bool
    {
        $this->guardConfig($accountId, $apiToken);

        try {
            $resp = $this->http->delete(
                self::API_BASE."/accounts/{$accountId}/workers/scripts/{$workerName}",
                ['headers' => $this->headers($apiToken), 'http_errors' => false],
            );
        } catch (GuzzleException $e) {
            throw new RuntimeException('Cloudflare delete failed: '.$e->getMessage(), 0, $e);
        }

        // 200 on delete, 404 if it was already gone.
        return in_array($resp->getStatusCode(), [200, 204, 404], true);
    }

    /**
     * The Worker source. Kept inline so we can ship it as one PHP file
     * with no separate JS asset to misplace at deploy time. Variables
     * come in as `env.*` (bindings) and `env.*` for secrets.
     */
    private function workerScript(): string
    {
        return <<<'JS'
        export default {
          async scheduled(event, env, ctx) {
            const url = env.LARAVEL_QUEUE_TICK_URL;
            const token = env.INTERNAL_QUEUE_TOKEN;
            if (!url || !token) {
              console.error('pitchbar-tick: missing LARAVEL_QUEUE_TICK_URL or INTERNAL_QUEUE_TOKEN');
              return;
            }
            try {
              const res = await fetch(url, {
                method: 'POST',
                headers: {
                  'X-Pitchbar-Token': token,
                  'Content-Type': 'application/json',
                  'User-Agent': 'pitchbar-cron-worker/1.0',
                },
                body: JSON.stringify({ queues: 'crawl,index,default', max_jobs: 20, max_time: 55 }),
                // No signal/timeout — the Laravel endpoint hard-caps itself.
              });
              const text = await res.text();
              console.log('pitchbar-tick:', res.status, text.slice(0, 200));
            } catch (e) {
              console.error('pitchbar-tick failed:', e && e.message ? e.message : String(e));
            }
          },
          // Expose a tiny GET handler for manual ping testing — visiting
          // workers.dev URL hits this and triggers one tick immediately.
          async fetch(request, env, ctx) {
            const url = new URL(request.url);
            if (url.pathname === '/run') {
              await this.scheduled({}, env, ctx);
              return new Response('ok', { status: 200 });
            }
            return new Response('pitchbar queue-tick worker', { status: 200 });
          },
        };
        JS;
    }

    /**
     * @param  array<int, array{name: string, filename: string, content_type: string, body: string}>  $parts
     */
    private function multipart(string $boundary, array $parts): string
    {
        $body = '';
        foreach ($parts as $p) {
            $body .= "--{$boundary}\r\n";
            $body .= "Content-Disposition: form-data; name=\"{$p['name']}\"; filename=\"{$p['filename']}\"\r\n";
            $body .= "Content-Type: {$p['content_type']}\r\n\r\n";
            $body .= $p['body']."\r\n";
        }
        $body .= "--{$boundary}--\r\n";

        return $body;
    }

    /**
     * @return array{Authorization: string, Accept: string}
     */
    private function headers(string $apiToken): array
    {
        return [
            'Authorization' => "Bearer {$apiToken}",
            'Accept' => 'application/json',
        ];
    }

    private function guardConfig(string $accountId, string $apiToken): void
    {
        if ($accountId === '' || $apiToken === '') {
            throw new RuntimeException('Cloudflare credentials are not configured.');
        }
    }

    private function assertOk(ResponseInterface $resp, string $what): void
    {
        $code = $resp->getStatusCode();
        if ($code >= 400) {
            $body = (string) $resp->getBody();
            // Truncate so we don't dump multi-megabyte error pages.
            $snippet = mb_strlen($body) > 600 ? mb_substr($body, 0, 600).'…' : $body;
            throw new RuntimeException("Cloudflare {$what} failed: HTTP {$code} — {$snippet}");
        }
    }
}
