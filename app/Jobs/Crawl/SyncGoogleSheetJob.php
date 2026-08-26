<?php

namespace App\Jobs\Crawl;

use App\Models\Document;
use App\Models\IntegrationConnection;
use App\Models\Source;
use App\Services\Integrations\Google\GoogleException;
use App\Services\Integrations\Google\GoogleTokenStore;
use App\Services\Integrations\Google\SheetsClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * C8b: Google Sheets → knowledge ingest. Each non-empty row of the
 * selected sheet becomes a Document; chunking + embedding flow through
 * the same IndexDocumentJob pipeline the website crawler uses.
 *
 * Multi-tenant safe: the OAuth token is loaded via the source's parent
 * workspace, never from another workspace's IntegrationConnection.
 *
 * Source config shape:
 *   {
 *     "spreadsheet_id": "1xyz…",
 *     "sheet_title": "Plans",
 *     "title": "Pricing sheet (Q3)"   // operator-friendly label
 *   }
 */
class SyncGoogleSheetJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

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

    public function __construct(public string $sourceId) {}

    public function handle(SheetsClient $sheets): void
    {
        $source = Source::query()->withoutWorkspaceScope()->find($this->sourceId);
        if ($source === null) {
            Log::info('jobs.target_missing', ['job' => static::class, 'id' => $this->sourceId]);

            return;
        }
        $source->forceFill(['status' => 'crawling'])->save();

        $spreadsheetId = (string) ($source->config['spreadsheet_id'] ?? '');
        $sheetTitle = (string) ($source->config['sheet_title'] ?? '');
        if ($spreadsheetId === '' || $sheetTitle === '') {
            $this->markFailed($source, 'Source has no spreadsheet_id / sheet_title.');

            return;
        }

        $agent = $source->agent()->withoutWorkspaceScope()->first();
        if ($agent === null) {
            $this->markFailed($source, 'Agent missing.');

            return;
        }

        $integration = IntegrationConnection::query()->withoutWorkspaceScope()
            ->where('workspace_id', $agent->workspace_id)
            ->where('kind', 'google')
            ->where('status', 'active')
            ->first();

        if ($integration === null) {
            $this->markFailed($source, 'Google is not connected for this workspace.');

            return;
        }

        $accessToken = $this->resolveAccessToken($integration);
        if ($accessToken === null) {
            $this->markFailed($source, 'Google access token unavailable.');

            return;
        }

        try {
            $rows = $sheets->pullRows($spreadsheetId, $sheetTitle, $accessToken);
        } catch (GoogleException $e) {
            $this->markFailed($source, 'Google Sheets API error: '.$e->getMessage());

            return;
        }

        if ($rows === []) {
            $this->markFailed($source, 'Sheet is empty or has no readable rows.');

            return;
        }

        // Bundle every row into one Document body, with a `Row N: …`
        // marker so the LLM can cite "Row 14: …" back to the visitor.
        // This keeps the chunker happy + keeps the row → citation
        // relationship intact for retrieval scoring.
        $body = $this->buildBody($rows);
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

        // Replace prior documents from the same sheet.
        Document::query()->withoutWorkspaceScope()
            ->where('source_id', $source->id)
            ->delete();

        $document = Document::query()->withoutWorkspaceScope()->create([
            'workspace_id' => $agent->workspace_id,
            'agent_id' => $agent->id,
            'source_id' => $source->id,
            'url' => "google-sheet://{$spreadsheetId}/{$sheetTitle}",
            'title' => (string) ($source->config['title'] ?? "Sheet: {$sheetTitle}"),
            'content_hash' => $hash,
        ]);

        IndexDocumentJob::dispatch($document->id, $body)->onQueue('index');

        $source->forceFill([
            'status' => 'indexed',
            'last_synced_at' => now(),
            'error' => null,
        ])->save();
    }

    /**
     * @param  array<int, array<string, string>>  $rows
     */
    private function buildBody(array $rows): string
    {
        $lines = [];
        $i = 1;
        foreach ($rows as $row) {
            $cells = [];
            foreach ($row as $col => $value) {
                if ($value === '') {
                    continue;
                }
                $cells[] = "{$col}: {$value}";
            }
            if ($cells === []) {
                continue;
            }
            $lines[] = 'Row '.$i.': '.implode(', ', $cells);
            $i++;
        }

        return implode("\n", $lines);
    }

    /**
     * Card #504. This method used to carry its OWN refresh logic with two
     * bugs that combined into a live incident: it read `expires_at` with an
     * int-cast — an ISO string casts to its leading year, so every stored
     * ISO value looked expired and every sheet sync refreshed needlessly —
     * and it then WROTE `expires_at` back as a unix epoch, which
     * GoogleTokenStore (the ISO-format owner used by every Doc ingest)
     * cannot parse. The first sheet source a workspace added poisoned the
     * shared credentials row and flipped every Google Doc to `failed`.
     * One token owner now: the store.
     */
    private function resolveAccessToken(IntegrationConnection $integration): ?string
    {
        try {
            return app(GoogleTokenStore::class)->activeAccessToken($integration);
        } catch (GoogleException) {
            return null;
        }
    }

    /**
     * Terminal failure after all retries. Without this handler an
     * exhausted job left the source stranded in `crawling` forever —
     * the only indexing job missing it (audit 2026-07-04).
     */
    public function failed(\Throwable $e): void
    {
        $source = Source::query()->withoutWorkspaceScope()->find($this->sourceId);
        if ($source === null) {
            return;
        }
        $this->markFailed($source, 'Sheet sync failed: '.Str::limit($e->getMessage(), 480));
    }

    private function markFailed(Source $source, string $error): void
    {
        $source->forceFill([
            'status' => 'failed',
            'error' => $error,
        ])->save();
    }
}
