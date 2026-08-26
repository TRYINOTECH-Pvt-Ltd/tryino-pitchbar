<?php

namespace Database\Factories;

use App\Models\Chunk;
use App\Models\Document;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Chunk>
 */
class ChunkFactory extends Factory
{
    protected $model = Chunk::class;

    public function definition(): array
    {
        $doc = Document::factory()->create();

        return [
            'document_id' => $doc->id,
            'agent_id' => $doc->agent_id,
            'ord' => 0,
            'text' => fake()->paragraph(),
            'token_count' => fake()->numberBetween(50, 500),
            'qdrant_point_id' => null,
        ];
    }
}
