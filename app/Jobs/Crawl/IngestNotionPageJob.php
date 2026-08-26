<?php

namespace App\Jobs\Crawl;

use App\Models\Document;
use App\Models\IntegrationConnection;
use App\Models\Source;
use App\Services\Integrations\Notion\NotionBlockExtractor;
use App\Services\Integrations\Notion\NotionClient;
use App\Services\Integrations\Notion\NotionException;
use App\Services\Vector\Contracts\QdrantClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Pulls a single Notion page (identified by source.config.notion_page_id),
 * walks its block tree, extracts plain text, and runs it through the same
 * Document → IndexDocumentJob pipeline that the website crawler uses.
 *
 * Multi-tenant safe: the OAuth token is loaded from the source's parent
 * workspace, never from another workspace's IntegrationConnection.
 */
class IngestNotionPageJob implements ShouldQueue
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

    public function handle(NotionClient $client, NotionBlockExtractor $extractor): void
    {
        $source = Source::query()->withoutWorkspaceScope()->find($this->sourceId);
        if ($source === null) {
            Log::info('jobs.target_missing', ['job' => static::class, 'id' => $this->sourceId]);

            return;
        }
        $source->forceFill(['status' => 'crawling'])->save();

        $pageId = $source->config['notion_page_id'] ?? null;
        if (! is_string($pageId) || $pageId === '') {
            $this->markFailed($source, 'Source has no notion_page_id.');

            return;
        }

        $agent = $source->agent()->withoutWorkspaceScope()->first();
        if ($agent === null) {
            $this->markFailed($source, 'Agent missing.');

            return;
        }

        $integration = IntegrationConnection::query()->withoutWorkspaceScope()
            ->where('workspace_id', $agent->workspace_id)
            ->where('kind', 'notion')
            ->where('status', 'active')
            ->first();

        if ($integration === null) {
            $this->markFailed($source, 'Notion is not connected for this workspace.');

            return;
        }

        $token = (string) ($integration->credentials_encrypted['access_token'] ?? '');
        if ($token === '') {
            $this->markFailed($source, 'Notion access token missing.');

            return;
        }

        try {
            $page = $client->getPage($token, $pageId);
            $blocks = $client->getBlocks($token, $pageId);
        } catch (NotionException $e) {
            $this->markFailed($source, 'Notion API error: '.$e->getMessage());

            return;
        }

        $text = $extractor->toText($blocks);
        if (mb_strlen($text) < 50) {
            $this->markFailed($source, 'Page is empty or too short to index.');

            return;
        }

        $hash = hash('sha256', $text);
        $existing = Document::query()->withoutWorkspaceScope()
            ->where('agent_id', $agent->id)
            ->where('content_hash', $hash)
            ->first();

        if ($existing === null) {
            // Fresh content: drop any prior document for this Notion page id
            // (and its vectors) before re-indexing.
            $stale = Document::query()->withoutWorkspaceScope()
                ->where('source_id', $source->id)
                ->where('url', "notion://{$pageId}")
                ->get();
            foreach ($stale as $old) {
                $this->purgeVectors($old);
                $old->delete();
            }

            $document = Document::create([
                'source_id' => $source->id,
                'agent_id' => $agent->id,
                'url' => "notion://{$pageId}",
                'title' => Str::limit($page['title'], 250) ?: 'Untitled Notion page',
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
        $source->forceFill([
            'status' => 'failed',
            'error' => $reason,
        ])->save();
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
