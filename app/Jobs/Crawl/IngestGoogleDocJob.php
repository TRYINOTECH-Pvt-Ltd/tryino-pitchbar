<?php

namespace App\Jobs\Crawl;

use App\Models\Document;
use App\Models\IntegrationConnection;
use App\Models\Source;
use App\Services\Integrations\Google\GoogleClient;
use App\Services\Integrations\Google\GoogleException;
use App\Services\Integrations\Google\GoogleTokenStore;
use App\Services\Vector\Contracts\QdrantClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Pulls a single Google Doc (config.google_file_id), exports as text/plain
 * via Drive API v3, and runs through the standard Document → IndexDocumentJob
 * pipeline.
 *
 * Token refresh is handled transparently by GoogleTokenStore.
 */
class IngestGoogleDocJob implements ShouldQueue
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

    public function __construct(public string $sourceId) {}

    public function handle(GoogleClient $client, GoogleTokenStore $tokens): void
    {
        $source = Source::query()->withoutWorkspaceScope()->find($this->sourceId);
        if ($source === null) {
            Log::info('jobs.target_missing', ['job' => static::class, 'id' => $this->sourceId]);

            return;
        }
        $source->forceFill(['status' => 'crawling'])->save();

        $fileId = $source->config['google_file_id'] ?? null;
        if (! is_string($fileId) || $fileId === '') {
            $this->markFailed($source, 'Source has no google_file_id.');

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

        try {
            $token = $tokens->activeAccessToken($integration);
            $doc = $client->getDoc($token, $fileId);
        } catch (GoogleException $e) {
            $this->markFailed($source, 'Google API error: '.$e->getMessage());

            return;
        }

        $text = trim($doc['text']);
        if (mb_strlen($text) < 50) {
            $this->markFailed($source, 'Google Doc is empty or too short to index.');

            return;
        }

        $hash = hash('sha256', $text);
        $existing = Document::query()->withoutWorkspaceScope()
            ->where('agent_id', $agent->id)
            ->where('content_hash', $hash)
            ->first();

        if ($existing === null) {
            $stale = Document::query()->withoutWorkspaceScope()
                ->where('source_id', $source->id)
                ->where('url', "gdoc://{$fileId}")
                ->get();
            foreach ($stale as $old) {
                $this->purgeVectors($old);
                $old->delete();
            }

            $document = Document::create([
                'source_id' => $source->id,
                'agent_id' => $agent->id,
                'url' => "gdoc://{$fileId}",
                'title' => Str::limit($doc['title'], 250),
                'content_hash' => $hash,
                'lang' => null,
                'fetched_at' => now(),
            ]);

            IndexDocumentJob::dispatch($document->id, $text)->onQueue('index');
        }

        $source->forceFill([
            'status' => 'indexed',
            'error' => null,
            'last_synced_at' => now(),
        ])->save();
    }

    public function failed(\Throwable $e): void
    {
        $source = Source::query()->withoutWorkspaceScope()->find($this->sourceId);
        if ($source === null) {
            return;
        }
        $this->markFailed($source, 'Ingest failed: '.Str::limit($e->getMessage(), 480));
    }

    private function markFailed(Source $source, string $reason): void
    {
        $source->forceFill(['status' => 'failed', 'error' => $reason])->save();
    }

    private function purgeVectors(Document $doc): void
    {
        try {
            app(QdrantClient::class)->deleteByFilter(
                (string) config('services.vector_collection', 'pitchbar-chunks'),
                ['document_id' => $doc->id],
            );
        } catch (\Throwable) {
            // best-effort
        }
    }
}
