<?php

namespace App\Services\Integrations\WordPress;

use App\Jobs\Crawl\IndexDocumentJob;
use App\Models\Agent;
use App\Models\Chunk;
use App\Models\Document;
use App\Models\Source;
use App\Services\Vector\Contracts\QdrantClient;
use Illuminate\Support\Facades\Log;

/**
 * Receives validated WooCommerce product payloads from
 * `ProductSyncController` + `ProductDeltaController` and writes them
 * into the existing Source -> Document -> IndexDocumentJob pipeline.
 *
 * Source type: `woocommerce_products`. One per (agent, site host).
 * Document key: external_id = "wc:{product_id}" scoped to the agent.
 *
 * Side-effect: on the first successful upsert against an agent whose
 * site_type is `generic`, automatically switch the agent to
 * `ecommerce` so the EcommercePreset prompt fragment + `<product/>`
 * block capability kick in without manual intervention. Never
 * overwrites a deliberate admin choice.
 */
class WooProductIngestService
{
    public function __construct(private readonly QdrantClient $vector) {}

    /**
     * @return array{accepted:int, queued:int, skipped_unchanged:int}
     */
    public function upsertBatch(Agent $agent, string $siteUrl, string $pluginVersion, array $products): array
    {
        $source = $this->resolveSource($agent, $siteUrl, $pluginVersion);

        $accepted = 0;
        $queued = 0;
        $skipped = 0;

        foreach ($products as $raw) {
            $payload = WooProductPayload::fromArray($raw);
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

        if ($queued > 0) {
            $this->maybeSwitchToEcommerce($agent);
        }

        return ['accepted' => $accepted, 'queued' => $queued, 'skipped_unchanged' => $skipped];
    }

    public function upsertOne(Agent $agent, Source $source, WooProductPayload $payload): string
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
                'title' => $payload->name,
                'content_hash' => $payload->contentHash,
                'fetched_at' => now(),
                'external_id' => $payload->externalId,
                'external_updated_at' => $modifiedAt,
            ]);
        } else {
            $existing->forceFill([
                'source_id' => $source->id,
                'url' => $payload->permalink,
                'title' => $payload->name,
                'content_hash' => $payload->contentHash,
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

    public function resolveSource(Agent $agent, string $siteUrl, string $pluginVersion): Source
    {
        $host = $this->canonicalHost($siteUrl);

        $existing = Source::query()
            ->withoutWorkspaceScope()
            ->where('agent_id', $agent->id)
            ->where('type', 'woocommerce_products')
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
            'type' => 'woocommerce_products',
            'status' => 'crawling',
            'config' => [
                'site_url' => $siteUrl,
                'site_host' => $host,
                'plugin_version' => $pluginVersion,
            ],
        ]);
    }

    /**
     * Auto-switch a generic agent to ecommerce on first product sync.
     * Idempotent — agents already on a non-generic site_type stay put.
     */
    private function maybeSwitchToEcommerce(Agent $agent): void
    {
        if ((string) $agent->site_type !== 'generic') {
            return;
        }

        $agent->forceFill(['site_type' => 'ecommerce'])->save();
        Log::info('Pitchbar WC sync auto-switched agent to ecommerce site_type', [
            'agent_id' => $agent->id,
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
