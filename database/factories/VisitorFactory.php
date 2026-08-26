<?php

namespace Database\Factories;

use App\Models\Agent;
use App\Models\Visitor;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Visitor>
 */
class VisitorFactory extends Factory
{
    protected $model = Visitor::class;

    public function definition(): array
    {
        return [
            'agent_id' => Agent::factory(),
            'anonymous_id' => 'anon_'.Str::random(16),
            'ip_hash' => hash('sha256', fake()->ipv4()),
            'country' => fake()->countryCode(),
            'ua' => fake()->userAgent(),
            'first_seen_at' => now(),
            'last_seen_at' => now(),
            'visit_count' => 1,
        ];
    }
}
