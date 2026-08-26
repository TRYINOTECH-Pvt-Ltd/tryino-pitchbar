<?php

namespace App\Services\Integrations\WordPress;

use App\Jobs\Crawl\IndexDocumentJob;
use App\Models\Agent;
use App\Models\Chunk;
use App\Models\Document;
use App\Models\Source;
use App\Services\Vector\Contracts\QdrantClient;

/**
 * Receives validated WordPress post payloads from `PostSyncController`
 * + `PostDeltaController` and writes them into the existing Source →
 * Document → IndexDocumentJob pipeline.
 *
 * Source row: one per (agent, site_url) — multiple WP sites can attach
 * to the same agent, each as its own Source. Idempotent on the same
 * pair.
 *
 * Document row: keyed by `external_id = "wp:{post_id}"` scoped to the
 * agent. Skip-on-unchanged keyed off `content_hash` so a re-sync of an
 * untouched post is a no-op (no re-chunk, no re-embed).
 */
class WordPressIngestService
{
    public function __construct(private readonly QdrantClient $vector) {}

    /**
     * @return array{accepted:int, queued:int, skipped_unchanged:int}
     */
    public function upsertBatch(Agent $agent, string $siteUrl, string $pluginVersion, array $posts): array
    {
        $source = $this->resolveSource($agent, $siteUrl, $pluginVersion);

        $accepted = 0;
        $queued = 0;
        $skipped = 0;

        foreach ($posts as $raw) {
            $payload = PostPayload::fromArray($raw);
            $result = $this->upsertOne($agent, $source, $payload);

            $accepted++;
            if ($result === 'queued') {
                $queued++;
            } elseif ($result === 'skipped_unchanged') {
                $skipped++;
            }
        }

        $source->forceFill([
            'status' => 'indexed',
            'last_synced_at' => now(),
            'error' => null,
        ])->save();

        return ['accepted' => $accepted, 'queued' => $queued, 'skipped_unchanged' => $skipped];
    }

    /**
     * Single-post delta: upsert + queue indexing if changed.
     */
    public function upsertOne(Agent $agent, Source $source, PostPayload $payload): string
    {
        $existing = Document::query()
            ->withoutWorkspaceScope()
            ->where('agent_id', $agent->id)
            ->where('external_id', $payload->externalId)
            ->first();

        if ($existing !== null && $existing->content_hash === $payload->contentHash && $payload->contentHash !== '') {
            return 'skipped_unchanged';
        }

        $modifiedAt = $payload->modifiedAt !== null ? $this->safeTimestamp($payload->modifiedAt) : null;

        if ($existing === null) {
            $document = Document::create([
                'source_id' => $source->id,
                'agent_id' => $agent->id,
                'url' => $payload->permalink,
                'title' => $payload->title,
                'content_hash' => $payload->contentHash,
                'lang' => $payload->language,
                'fetched_at' => now(),
                'external_id' => $payload->externalId,
                'external_updated_at' => $modifiedAt,
            ]);
        } else {
            $existing->forceFill([
                'source_id' => $source->id,
                'url' => $payload->permalink,
                'title' => $payload->title,
                'content_hash' => $payload->contentHash,
                'lang' => $payload->language,
                'fetched_at' => now(),
                'external_updated_at' => $modifiedAt,
            ])->save();
            $document = $existing;
        }

        $text = $payload->indexableText();
        if ($text === '') {
            return 'queued_empty';
        }

        IndexDocumentJob::dispatch($document->id, $text);

        return 'queued';
    }

    /**
     * Resolve a single-post delete keyed by external_id. Silent no-op
     * when the post is unknown so deleting a post that was never
     * synced doesn't 404.
     */
    public function delete(Agent $agent, string $externalId): bool
    {
        $document = Document::query()
            ->withoutWorkspaceScope()
            ->where('agent_id', $agent->id)
            ->where('external_id', $externalId)
            ->first();

        if ($document === null) {
            return false;
        }

        $collection = (string) config('services.vector_collection', 'pitchbar-chunks');
        $this->vector->deleteByFilter($collection, ['document_id' => $document->id]);

        Chunk::query()
            ->withoutWorkspaceScope()
            ->where('document_id', $document->id)
            ->delete();

        $document->delete();

        return true;
    }

    /**
     * Find-or-create the `wordpress` source for this agent + site URL.
     * Multiple WP sites can attach to the same agent — each gets its
     * own Source row matched on the canonical site host.
     */
    public function resolveSource(Agent $agent, string $siteUrl, string $pluginVersion): Source
    {
        $host = $this->canonicalHost($siteUrl);

        $existing = Source::query()
            ->withoutWorkspaceScope()
            ->where('agent_id', $agent->id)
            ->where('type', 'wordpress')
            ->get()
            ->first(function (Source $candidate) use ($host) {
                $existingHost = $this->canonicalHost((string) ($candidate->config['site_url'] ?? ''));

                return $existingHost === $host;
            });

        if ($existing !== null) {
            $config = is_array($existing->config) ? $existing->config : [];
            $config['site_url'] = $siteUrl;
            $config['site_host'] = $host;
            $config['plugin_version'] = $pluginVersion;
            $existing->forceFill(['config' => $config, 'status' => 'crawling'])->save();

            return $existing;
        }

        return Source::create([
            'agent_id' => $agent->id,
            'type' => 'wordpress',
            'status' => 'crawling',
            'config' => [
                'site_url' => $siteUrl,
                'site_host' => $host,
                'plugin_version' => $pluginVersion,
            ],
        ]);
    }

    private function canonicalHost(string $url): string
    {
        $host = parse_url($url, PHP_URL_HOST);
        if (! is_string($host) || $host === '') {
            return strtolower($url);
        }

        return strtolower($host);
    }

    private function safeTimestamp(string $raw): ?\DateTimeImmutable
    {
        try {
            return new \DateTimeImmutable($raw);
        } catch (\Throwable) {
            return null;
        }
    }
}
