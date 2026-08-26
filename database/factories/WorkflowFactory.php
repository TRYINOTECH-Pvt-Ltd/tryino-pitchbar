<?php

namespace Database\Factories;

use App\Models\Workflow;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Workflow>
 */
class WorkflowFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'name' => 'Pricing FAQ flow',
            'status' => 'active',
            'trigger_kind' => 'on_keyword',
            'trigger_config' => ['keywords' => ['price', 'pricing', 'cost']],
            'definition' => [
                'steps' => [
                    ['type' => 'message', 'text' => 'We have three plans — Free, Standard, and Pro.'],
                    ['type' => 'question', 'text' => 'Which plan are you most interested in?', 'var_name' => 'plan_interest'],
                    ['type' => 'message', 'text' => "Great — here's the breakdown."],
                ],
            ],
        ];
    }

    public function draft(): self
    {
        return $this->state(['status' => 'draft']);
    }

    public function disabled(): self
    {
        return $this->state(['status' => 'disabled']);
    }
}
