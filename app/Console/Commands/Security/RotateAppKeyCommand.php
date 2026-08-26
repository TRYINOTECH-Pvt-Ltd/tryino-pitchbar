<?php

namespace App\Console\Commands\Security;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * Re-encrypts every encrypted-at-rest column with the current APP_KEY,
 * so the operator can safely remove the old key from
 * APP_PREVIOUS_KEYS once this command finishes. Idempotent — running
 * twice does no harm.
 *
 * Operates on raw DB tables (not Eloquent) so that:
 *   - global scopes never hide rows from the sweep,
 *   - Eloquent events / observers don't fire while rotating,
 *   - the implementation mirrors the encrypt-at-rest migration that
 *     produced these ciphertexts in the first place.
 */
class RotateAppKeyCommand extends Command
{
    protected $signature = 'security:rotate-app-key {--confirm-production} {--dry-run}';

    protected $description = 'Re-encrypt all encrypted-at-rest columns with the current APP_KEY.';

    /**
     * @var array<int, array{table: string, columns: list<string>}>
     */
    private const TARGETS = [
        ['table' => 'workspaces', 'columns' => ['cta_context_secret', 'byok_keys', 'slack_webhook_url', 'teams_webhook_url']],
        ['table' => 'webhook_subscriptions', 'columns' => ['secret']],
        ['table' => 'workspace_api_tokens', 'columns' => ['shopper_signing_secret']],
        ['table' => 'dsr_requests', 'columns' => ['result_payload']],
        ['table' => 'integration_connections', 'columns' => ['credentials_encrypted']],
        ['table' => 'sources', 'columns' => ['credentials_encrypted']],
        ['table' => 'app_settings', 'columns' => [
            'stripe_secret', 'stripe_webhook_secret',
            'paypal_client_secret', 'razorpay_key_secret', 'razorpay_webhook_secret',
            'cloudflare_api_token', 'openai_api_key', 'openrouter_api_key',
            'mail_password', 'internal_queue_token',
        ]],
    ];

    public function handle(): int
    {
        if (app()->environment('production') && ! $this->option('confirm-production')) {
            $this->error('Refusing to rotate in production without --confirm-production.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $totalRows = 0;
        $totalCols = 0;
        $totalSkipped = 0;

        foreach (self::TARGETS as $target) {
            $table = $target['table'];
            $columns = $target['columns'];

            if (! \Schema::hasTable($table)) {
                continue;
            }

            $this->info("Sweeping {$table}…");

            $rowsTouched = 0;
            $colsTouched = 0;
            $skipped = 0;

            DB::table($table)
                ->orderBy('id')
                ->chunkById(200, function ($rows) use ($table, $columns, $dryRun, &$rowsTouched, &$colsTouched, &$skipped): void {
                    foreach ($rows as $row) {
                        $updates = [];
                        foreach ($columns as $col) {
                            $cipher = $row->{$col} ?? null;
                            if (! is_string($cipher) || $cipher === '') {
                                continue;
                            }

                            try {
                                $plain = Crypt::decryptString($cipher);
                            } catch (\Throwable) {
                                $skipped++;

                                continue;
                            }

                            $updates[$col] = Crypt::encryptString($plain);
                            $colsTouched++;
                        }

                        if ($updates !== []) {
                            $rowsTouched++;
                            if (! $dryRun) {
                                DB::table($table)
                                    ->where('id', $row->id)
                                    ->update($updates);
                            }
                        }
                    }
                });

            $this->line("  {$rowsTouched} rows · {$colsTouched} columns".($dryRun ? ' (dry-run)' : ''));
            if ($skipped > 0) {
                $this->warn("  {$skipped} columns failed to decrypt and were left as-is.");
            }
            $totalRows += $rowsTouched;
            $totalCols += $colsTouched;
            $totalSkipped += $skipped;
        }

        $this->info("Done. {$totalRows} rows touched, {$totalCols} columns re-encrypted.".($totalSkipped > 0 ? " {$totalSkipped} skipped." : ''));

        return self::SUCCESS;
    }
}
