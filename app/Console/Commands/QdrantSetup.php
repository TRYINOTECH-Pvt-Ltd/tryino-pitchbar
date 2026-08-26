<?php

namespace App\Console\Commands;

use App\Services\Vector\Contracts\QdrantClient;
use Illuminate\Console\Command;

class QdrantSetup extends Command
{
    protected $signature = 'vector:setup';

    protected $aliases = ['qdrant:setup'];

    protected $description = 'Idempotently create the vector index/collection (Vectorize on Cloudflare, or Qdrant)';

    public function handle(QdrantClient $client): int
    {
        // Cloudflare Vectorize uses CLOUDFLARE_VECTORIZE_INDEX, Qdrant uses QDRANT_COLLECTION.
        $name = (string) (
            env('VECTOR_PROVIDER') === 'cloudflare' || env('CLOUDFLARE_API_TOKEN')
                ? config('services.cloudflare.vectorize_index', 'pitchbar-chunks')
                : config('services.qdrant.collection', 'pitchbar_chunks')
        );
        $dim = (int) config('services.vector_dim', 768);
        $client->ensureCollection($name, $dim, 'Cosine');
        $this->info("Vector collection '{$name}' is ready ({$dim}d).");

        return self::SUCCESS;
    }
}
