<?php

namespace Database\Factories;

use App\Models\Conversation;
use App\Models\Lead;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Lead>
 */
class LeadFactory extends Factory
{
    protected $model = Lead::class;

    public function definition(): array
    {
        $conversation = Conversation::factory()->create();

        return [
            'conversation_id' => $conversation->id,
            'agent_id' => $conversation->agent_id,
            'email' => fake()->safeEmail(),
            'phone' => fake()->phoneNumber(),
            'name' => fake()->name(),
            'fields' => [],
            'status' => 'new',
            'owner_user_id' => null,
            'routed_to' => null,
        ];
    }
}
