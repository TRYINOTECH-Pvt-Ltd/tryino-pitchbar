<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Encrypt-at-rest sweep for 4 secret columns:
 *   - webhook_subscriptions.secret
 *   - workspace_api_tokens.shopper_signing_secret
 *   - workspaces.slack_webhook_url
 *   - workspaces.teams_webhook_url
 *
 * Skips rows already encrypted (detected via base64 + JSON shape).
 * Paired with `'field' => 'encrypted'` casts on the models.
 */
return new class extends Migration
{
    /**
     * @var array<int, array{table: string, column: string, has_workspace: bool}>
     */
    private array $targets = [
        ['table' => 'webhook_subscriptions', 'column' => 'secret', 'has_workspace' => true],
        ['table' => 'workspace_api_tokens', 'column' => 'shopper_signing_secret', 'has_workspace' => true],
        ['table' => 'workspaces', 'column' => 'slack_webhook_url', 'has_workspace' => false],
        ['table' => 'workspaces', 'column' => 'teams_webhook_url', 'has_workspace' => false],
    ];

    public function up(): void
    {
        foreach ($this->targets as $target) {
            $this->encryptColumn($target['table'], $target['column']);
        }
    }

    public function down(): void
    {
        foreach ($this->targets as $target) {
            $this->decryptColumn($target['table'], $target['column']);
        }
    }

    private function encryptColumn(string $table, string $column): void
    {
        DB::table($table)
            ->whereNotNull($column)
            ->orderBy('id')
            ->chunkById(500, function ($rows) use ($table, $column): void {
                foreach ($rows as $row) {
                    $value = $row->{$column} ?? null;
                    if (! is_string($value) || $value === '') {
                        continue;
                    }
                    if ($this->looksEncrypted($value)) {
                        continue;
                    }
                    try {
                        $cipher = Crypt::encryptString($value);
                    } catch (Throwable $e) {
                        Log::error('migration.encrypt_failed', [
                            'table' => $table,
                            'column' => $column,
                            'row_id' => $row->id ?? null,
                            'error' => $e->getMessage(),
                        ]);

                        continue;
                    }
                    DB::table($table)->where('id', $row->id)->update([$column => $cipher]);
                }
            });
    }

    private function decryptColumn(string $table, string $column): void
    {
        DB::table($table)
            ->whereNotNull($column)
            ->orderBy('id')
            ->chunkById(500, function ($rows) use ($table, $column): void {
                foreach ($rows as $row) {
                    $value = $row->{$column} ?? null;
                    if (! is_string($value) || $value === '') {
                        continue;
                    }
                    if (! $this->looksEncrypted($value)) {
                        continue;
                    }
                    try {
                        $plain = Crypt::decryptString($value);
                    } catch (Throwable $e) {
                        Log::error('migration.decrypt_failed', [
                            'table' => $table,
                            'column' => $column,
                            'row_id' => $row->id ?? null,
                            'error' => $e->getMessage(),
                        ]);

                        continue;
                    }
                    DB::table($table)->where('id', $row->id)->update([$column => $plain]);
                }
            });
    }

    /**
     * Laravel's `Crypt::encryptString` produces a base64-encoded JSON
     * payload of the form `{"iv":"...","value":"...","mac":"...","tag":""}`.
     * Detect via base64-decode + JSON-decode round-trip. Returns false
     * on plaintext that happens to look base64-ish (slack URLs don't
     * — they start with `https://`).
     */
    private function looksEncrypted(string $value): bool
    {
        if (strlen($value) < 80) {
            return false;
        }
        $decoded = base64_decode($value, true);
        if ($decoded === false) {
            return false;
        }
        $json = json_decode($decoded, true);
        if (! is_array($json)) {
            return false;
        }

        return isset($json['iv'], $json['value'], $json['mac']);
    }
};
