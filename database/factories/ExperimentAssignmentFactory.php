<?php

namespace Database\Factories;

use App\Models\Experiment;
use App\Models\ExperimentAssignment;
use App\Models\Variant;
use App\Models\Visitor;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ExperimentAssignment>
 */
class ExperimentAssignmentFactory extends Factory
{
    protected $model = ExperimentAssignment::class;

    public function definition(): array
    {
        return [
            'visitor_id' => Visitor::factory(),
            'experiment_id' => Experiment::factory(),
            'variant_id' => Variant::factory(),
            'assigned_at' => now(),
        ];
    }
}
