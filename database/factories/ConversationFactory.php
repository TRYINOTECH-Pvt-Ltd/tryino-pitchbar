<?php

namespace Database\Factories;

use App\Models\Conversation;
use App\Models\Visitor;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Conversation>
 */
class ConversationFactory extends Factory
{
    protected $model = Conversation::class;

    public function definition(): array
    {
        $visitor = Visitor::factory()->create();

        return [
            'agent_id' => $visitor->agent_id,
            'visitor_id' => $visitor->id,
            'page_url' => fake()->url(),
            'lang' => 'en',
            'started_at' => now(),
            'ended_at' => null,
            'message_count' => 0,
            'is_lead' => false,
            'is_playground' => false,
            'attribution' => [],
        ];
    }
}
