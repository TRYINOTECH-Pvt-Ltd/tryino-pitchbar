<?php

namespace Database\Factories;

use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Message>
 */
class MessageFactory extends Factory
{
    protected $model = Message::class;

    public function definition(): array
    {
        return [
            'conversation_id' => Conversation::factory(),
            'role' => fake()->randomElement(['user', 'assistant']),
            'content' => fake()->paragraph(),
            'citations' => [],
            'confidence' => fake()->randomFloat(2, 0, 1),
            'tokens_in' => fake()->numberBetween(10, 500),
            'tokens_out' => fake()->numberBetween(10, 500),
            'latency_ms' => fake()->numberBetween(200, 1500),
            'model' => 'gpt-4o-mini',
            'feedback' => null,
        ];
    }
}
