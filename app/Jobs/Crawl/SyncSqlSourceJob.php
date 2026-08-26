<?php

namespace App\Jobs\Crawl;

use App\Models\Document;
use App\Models\Source;
use App\Services\Crawl\SqlConnector;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Pulls rows from an owner-supplied SQL database, bundles them into a
 * single Document body (one "Row N: …" line per row), and dispatches
 * the standard IndexDocumentJob. Same shape as SyncGoogleSheetJob.
 *
 * Source.config shape:
 *   {
 *     "driver": "mysql|pgsql",
 *     "query": "SELECT id, title, body FROM articles WHERE published=1",
 *     "title_column": "title",       // optional row label, falls back to "Row N"
 *     "body_column": "body",         // optional; when null, every column joined
 *     "title": "Production CRM tickets"  // operator-friendly source label
 *   }
 *
 * Source.credentials_encrypted (AES-256-GCM via APP_KEY):
 *   { driver, host, port, database, username, password }
 *
 * Multi-tenant safe: agent → workspace lookup uses `withoutWorkspaceScope`
 * because workers have no auth context, but the data fans into Documents
 * tagged with the same agent_id / workspace_id.
 */
class SyncSqlSourceJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /**
     * Spaced backoff so transient upstream errors (429, 5xx, network)
     * get a real second chance instead of three attempts in one second.
     *
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [30, 120];
    }

    public int $backoff = 60;

    public function __construct(public string $sourceId) {}

    public function handle(SqlConnector $connector): void
    {
        $source = Source::query()->withoutWorkspaceScope()->find($this->sourceId);
        if ($source === null) {
            Log::info('jobs.target_missing', ['job' => static::class, 'id' => $this->sourceId]);

            return;
        }
        $source->forceFill(['status' => 'crawling'])->save();

        $agent = $source->agent()->withoutWorkspaceScope()->first();
        if ($agent === null) {
            $this->markFailed($source, 'Agent missing.');

            return;
        }

        $credentials = $source->credentials_encrypted;
        if (! is_array($credentials)
            || ! isset($credentials['driver'], $credentials['host'], $credentials['port'], $credentials['database'], $credentials['username'])) {
            $this->markFailed($source, 'SQL source has incomplete connection details.');

            return;
        }
        $credentials['password'] = (string) ($credentials['password'] ?? '');

        $config = (array) ($source->config ?? []);
        $query = (string) ($config['query'] ?? '');
        if ($query === '') {
            $this->markFailed($source, 'SQL source has no query configured.');

            return;
        }

        try {
            $rows = $connector->run($credentials, $query);
        } catch (RuntimeException $e) {
            $this->markFailed($source, $e->getMessage());

            return;
        }

        if ($rows === []) {
            $this->markFailed($source, 'Query returned no rows.');

            return;
        }

        $body = $this->buildBody($rows, $config);
        $hash = hash('sha256', $body);

        $existing = Document::query()->withoutWorkspaceScope()
            ->where('agent_id', $agent->id)
            ->where('content_hash', $hash)
            ->first();

        if ($existing !== null) {
            // No change since last sync — short-circuit.
            $source->forceFill([
                'status' => 'indexed',
                'last_synced_at' => now(),
                'error' => null,
            ])->save();

            return;
        }

        // Drop prior documents from this source before re-indexing.
        Document::query()->withoutWorkspaceScope()
            ->where('source_id', $source->id)
            ->delete();

        $title = (string) ($config['title'] ?? 'SQL source');
        $document = Document::query()->withoutWorkspaceScope()->create([
            'agent_id' => $agent->id,
            'source_id' => $source->id,
            'url' => sprintf('sql://%s/%s', $credentials['driver'], $credentials['database']),
            'title' => Str::limit($title, 250),
            'content_hash' => $hash,
            'lang' => null,
            'fetched_at' => now(),
        ]);

        IndexDocumentJob::dispatch($document->id, $body)->onQueue('index');

        $source->forceFill([
            'status' => 'indexed',
            'last_synced_at' => now(),
            'error' => null,
        ])->save();
    }

    public function failed(\Throwable $e): void
    {
        $source = Source::query()->withoutWorkspaceScope()->find($this->sourceId);
        if ($source === null) {
            return;
        }
        $this->markFailed($source, 'Sync failed: '.Str::limit($e->getMessage(), 480));
    }

    /**
     * @param  array<int, array<string, string|null>>  $rows
     * @param  array<string, mixed>  $config
     */
    private function buildBody(array $rows, array $config): string
    {
        $titleColumn = (string) ($config['title_column'] ?? '');
        $bodyColumn = (string) ($config['body_column'] ?? '');

        $lines = [];
        $i = 1;
        foreach ($rows as $row) {
            $label = $titleColumn !== '' && isset($row[$titleColumn])
                ? trim((string) $row[$titleColumn])
                : "Row {$i}";

            if ($bodyColumn !== '' && isset($row[$bodyColumn])) {
                $lines[] = $label.': '.trim((string) $row[$bodyColumn]);
            } else {
                // No body column specified — concatenate every non-null
                // column as "col: value" pairs.
                $parts = [];
                foreach ($row as $col => $value) {
                    if ($value === null || $value === '') {
                        continue;
                    }
                    $parts[] = "{$col}: {$value}";
                }
                if ($parts !== []) {
                    $lines[] = $label.' — '.implode(', ', $parts);
                }
            }
            $i++;
        }

        return implode("\n", $lines);
    }

    private function markFailed(Source $source, string $reason): void
    {
        $source->forceFill([
            'status' => 'failed',
            'error' => $reason,
        ])->save();
    }
}
