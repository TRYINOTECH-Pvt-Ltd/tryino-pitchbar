<?php

namespace App\Http\Controllers\Admin\Platform;

use App\Http\Controllers\Internal\QueueTickController;
use App\Models\AppSetting;
use App\Services\Cloudflare\WorkerDeployer;
use App\Support\QueueHealth;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Admin "Deploy Cron Worker" button. Uses the install's existing
 * Cloudflare credentials to push a Worker that drives the queue
 * via {@see QueueTickController}.
 *
 * Per Pitchbar install (per codecanyon buyer) — the Worker name is
 * derived from the app URL host so multiple installs on the same CF
 * account don't collide.
 */
class CronWorkerController
{
    public function __construct(private WorkerDeployer $deployer) {}

    public function deploy(Request $request): JsonResponse
    {
        $settings = AppSetting::singleton();

        $accountId = (string) ($settings->cloudflare_account_id ?: config('services.cloudflare.account_id', ''));
        $apiToken = (string) ($settings->cloudflare_api_token ?: config('services.cloudflare.api_token', ''));

        if ($accountId === '' || $apiToken === '') {
            return response()->json([
                'error' => [
                    'code' => 'cloudflare_missing',
                    'message' => 'Set Cloudflare account ID and API token in System Settings → Cloudflare first.',
                ],
            ], 422);
        }

        // Derive a stable Worker name from the app URL. Hash so the
        // name is short, alphanumeric, and doesn't leak the host.
        $appUrl = rtrim((string) config('app.url'), '/');
        if ($appUrl === '') {
            return response()->json([
                'error' => [
                    'code' => 'app_url_missing',
                    'message' => 'APP_URL must be set in your environment.',
                ],
            ], 422);
        }
        $host = parse_url($appUrl, PHP_URL_HOST) ?: 'pitchbar';
        $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $host)) ?? 'pitchbar';
        $slug = trim($slug, '-');
        $workerName = 'pitchbar-tick-'.substr($slug.'-'.substr(hash('sha256', $appUrl), 0, 8), 0, 50);

        // Generate (or reuse) the shared secret. Re-use lets the user
        // re-deploy without breaking running calls; rotate via the
        // explicit "Rotate token" action.
        $token = (string) ($settings->internal_queue_token ?? '');
        if ($token === '' || $request->boolean('rotate_token')) {
            $token = Str::random(48);
            $settings->internal_queue_token = $token;
            $settings->save();
            AppSetting::flushSingleton();
        }

        $callbackUrl = $appUrl.'/api/v1/internal/queue-tick';

        try {
            $result = $this->deployer->deploy(
                accountId: $accountId,
                apiToken: $apiToken,
                workerName: $workerName,
                callbackUrl: $callbackUrl,
                sharedToken: $token,
            );
        } catch (RuntimeException $e) {
            return response()->json([
                'error' => [
                    'code' => 'deploy_failed',
                    'message' => $e->getMessage(),
                ],
            ], 502);
        }

        $settings->cron_worker_name = $workerName;
        $settings->cron_worker_deployed_at = now();
        $settings->cron_worker_last_status_at = now();
        $settings->cron_worker_last_status = ['ok' => true, 'message' => 'Deployed.'];
        $settings->save();
        AppSetting::flushSingleton();

        return response()->json([
            'data' => [
                'ok' => true,
                'worker_name' => $workerName,
                'worker_url' => $result['worker_url'],
                'deployed_at' => $result['deployed_at'],
                'callback_url' => $callbackUrl,
                'cron_schedule' => '* * * * *',
            ],
        ]);
    }

    public function status(Request $request): JsonResponse
    {
        $settings = AppSetting::singleton();
        $health = QueueHealth::summary();

        $accountId = (string) ($settings->cloudflare_account_id ?: config('services.cloudflare.account_id', ''));
        $apiToken = (string) ($settings->cloudflare_api_token ?: config('services.cloudflare.api_token', ''));
        $workerName = (string) ($settings->cron_worker_name ?? '');

        if ($workerName === '' || $accountId === '' || $apiToken === '') {
            return response()->json([
                'data' => array_merge([
                    'deployed' => false,
                    'reason' => 'never-deployed',
                ], $health),
            ]);
        }

        try {
            $info = $this->deployer->status($accountId, $apiToken, $workerName);
        } catch (RuntimeException $e) {
            return response()->json([
                'data' => array_merge([
                    'deployed' => null,
                    'error' => $e->getMessage(),
                ], $health),
            ]);
        }

        $settings->cron_worker_last_status_at = now();
        $settings->cron_worker_last_status = $info;
        $settings->save();
        AppSetting::flushSingleton();

        return response()->json([
            'data' => array_merge([
                'deployed' => $info['exists'],
                'schedules' => $info['schedules'],
                'worker_name' => $workerName,
                'deployed_at' => $settings->cron_worker_deployed_at?->toIso8601String(),
                'last_checked_at' => now()->toIso8601String(),
            ], $health),
        ]);
    }

    public function destroy(): JsonResponse
    {
        $settings = AppSetting::singleton();

        $accountId = (string) ($settings->cloudflare_account_id ?: config('services.cloudflare.account_id', ''));
        $apiToken = (string) ($settings->cloudflare_api_token ?: config('services.cloudflare.api_token', ''));
        $workerName = (string) ($settings->cron_worker_name ?? '');

        if ($workerName === '' || $accountId === '' || $apiToken === '') {
            return response()->json(['data' => ['ok' => true, 'message' => 'Nothing to remove.']]);
        }

        try {
            $this->deployer->destroy($accountId, $apiToken, $workerName);
        } catch (RuntimeException $e) {
            return response()->json([
                'error' => [
                    'code' => 'destroy_failed',
                    'message' => $e->getMessage(),
                ],
            ], 502);
        }

        $settings->cron_worker_name = null;
        $settings->cron_worker_deployed_at = null;
        $settings->cron_worker_last_status = ['ok' => true, 'message' => 'Removed.'];
        $settings->cron_worker_last_status_at = now();
        $settings->save();
        AppSetting::flushSingleton();

        return response()->json(['data' => ['ok' => true]]);
    }
}
