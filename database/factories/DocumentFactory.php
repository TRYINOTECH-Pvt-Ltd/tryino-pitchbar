<?php

namespace Database\Factories;

use App\Models\Document;
use App\Models\Source;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Document>
 */
class DocumentFactory extends Factory
{
    protected $model = Document::class;

    public function definition(): array
    {
        $source = Source::factory()->create();

        return [
            'source_id' => $source->id,
            'agent_id' => $source->agent_id,
            'url' => fake()->url(),
            'title' => fake()->sentence(),
            'content_hash' => hash('sha256', fake()->paragraph()),
            'text_path' => 's3://docs/'.fake()->uuid().'.txt',
            'lang' => 'en',
            'fetched_at' => now(),
        ];
    }
}
