<?php

use App\Models\Agent;
use App\Models\Experiment;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceUser;

function asExperimentAdmin(): array
{
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['owner_user_id' => $user->id]);
    WorkspaceUser::create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'role' => 'admin',
        'invited_at' => now(),
        'accepted_at' => now(),
    ]);
    $user->forceFill(['default_workspace_id' => $workspace->id])->save();

    return ['user' => $user, 'workspace' => $workspace];
}

test('admin can create an experiment with at least 2 variants', function () {
    ['user' => $user, 'workspace' => $workspace] = asExperimentAdmin();
    $agent = Agent::factory()->create(['workspace_id' => $workspace->id]);

    $this->actingAs($user)->post("/app/agents/{$agent->id}/experiments", [
        'name' => 'CTA copy',
        'kind' => 'cta',
        'variants' => [
            ['name' => 'control', 'weight' => 50],
            ['name' => 'treatment', 'weight' => 50],
        ],
    ])->assertRedirect();

    $experiment = Experiment::query()->withoutGlobalScopes()->where('agent_id', $agent->id)->firstOrFail();
    expect($experiment->name)->toBe('CTA copy');
    expect($experiment->variants()->count())->toBe(2);
});

test('persona-kind variants persist name + tone in config', function () {
    ['user' => $user, 'workspace' => $workspace] = asExperimentAdmin();
    $agent = Agent::factory()->create(['workspace_id' => $workspace->id]);

    $this->actingAs($user)->post("/app/agents/{$agent->id}/experiments", [
        'name' => 'Persona test',
        'kind' => 'persona',
        'variants' => [
            [
                'name' => 'Aria',
                'weight' => 50,
                'config' => [
                    'persona' => [
                        'name' => 'Aria',
                        'tone' => 'warm and concise',
                    ],
                ],
            ],
            [
                'name' => 'Max',
                'weight' => 50,
                'config' => [
                    'persona' => [
                        'name' => 'Max',
                        'tone' => 'professional and brief',
                    ],
                ],
            ],
        ],
    ])->assertRedirect();

    $experiment = Experiment::query()->withoutGlobalScopes()->where('agent_id', $agent->id)->firstOrFail();
    $variants = $experiment->variants()->get();
    expect($variants)->toHaveCount(2);

    $aria = $variants->firstWhere('name', 'Aria');
    expect($aria->config)->toBeArray();
    expect($aria->config['persona']['name'])->toBe('Aria');
    expect($aria->config['persona']['tone'])->toBe('warm and concise');
});

test('persona variant without explicit config.persona.name auto-fills from variant name', function () {
    ['user' => $user, 'workspace' => $workspace] = asExperimentAdmin();
    $agent = Agent::factory()->create(['workspace_id' => $workspace->id]);

    $this->actingAs($user)->post("/app/agents/{$agent->id}/experiments", [
        'name' => 'Persona default fill',
        'kind' => 'persona',
        'variants' => [
            ['name' => 'Aria', 'weight' => 50],
            ['name' => 'Max', 'weight' => 50, 'config' => ['persona' => ['tone' => 'punchy']]],
        ],
    ])->assertRedirect();

    $experiment = Experiment::query()->withoutGlobalScopes()->where('agent_id', $agent->id)->firstOrFail();
    $aria = $experiment->variants()->where('name', 'Aria')->first();
    expect($aria->config['persona']['name'])->toBe('Aria');

    $max = $experiment->variants()->where('name', 'Max')->first();
    expect($max->config['persona']['name'])->toBe('Max');
    expect($max->config['persona']['tone'])->toBe('punchy');
});

test('duplicate variant names within an experiment are rejected', function () {
    ['user' => $user, 'workspace' => $workspace] = asExperimentAdmin();
    $agent = Agent::factory()->create(['workspace_id' => $workspace->id]);

    $this->actingAs($user)->post("/app/agents/{$agent->id}/experiments", [
        'name' => 'Dup names',
        'kind' => 'cta',
        'variants' => [
            ['name' => 'control', 'weight' => 50],
            ['name' => 'Control', 'weight' => 50],
        ],
    ])->assertSessionHasErrors('variants');
});

test('duplicate persona names are rejected even when variant labels differ', function () {
    ['user' => $user, 'workspace' => $workspace] = asExperimentAdmin();
    $agent = Agent::factory()->create(['workspace_id' => $workspace->id]);

    $this->actingAs($user)->post("/app/agents/{$agent->id}/experiments", [
        'name' => 'Persona collision',
        'kind' => 'persona',
        'variants' => [
            [
                'name' => 'variant_a',
                'weight' => 50,
                'config' => ['persona' => ['name' => 'Aria', 'tone' => 'warm']],
            ],
            [
                'name' => 'variant_b',
                'weight' => 50,
                'config' => ['persona' => ['name' => 'aria', 'tone' => 'crisp']],
            ],
        ],
    ])->assertSessionHasErrors('variants');
});

test('persona tone too long is rejected', function () {
    ['user' => $user, 'workspace' => $workspace] = asExperimentAdmin();
    $agent = Agent::factory()->create(['workspace_id' => $workspace->id]);

    $this->actingAs($user)->post("/app/agents/{$agent->id}/experiments", [
        'name' => 'Bad',
        'kind' => 'persona',
        'variants' => [
            ['name' => 'A', 'weight' => 50, 'config' => ['persona' => ['tone' => str_repeat('x', 500)]]],
            ['name' => 'B', 'weight' => 50],
        ],
    ])->assertSessionHasErrors('variants.0.config.persona.tone');
});

test('start flips status to running', function () {
    ['user' => $user, 'workspace' => $workspace] = asExperimentAdmin();
    $agent = Agent::factory()->create(['workspace_id' => $workspace->id]);
    $experiment = Experiment::factory()->create(['agent_id' => $agent->id, 'status' => 'draft']);

    $this->actingAs($user)->post("/app/experiments/{$experiment->id}/start")->assertRedirect();

    expect($experiment->fresh()->status)->toBe('running');
    expect($experiment->fresh()->started_at)->not->toBeNull();
});

test('stop flips status to stopped', function () {
    ['user' => $user, 'workspace' => $workspace] = asExperimentAdmin();
    $agent = Agent::factory()->create(['workspace_id' => $workspace->id]);
    $experiment = Experiment::factory()->create([
        'agent_id' => $agent->id,
        'status' => 'running',
        'started_at' => now()->subHour(),
    ]);

    $this->actingAs($user)->post("/app/experiments/{$experiment->id}/stop")->assertRedirect();

    expect($experiment->fresh()->status)->toBe('stopped');
    expect($experiment->fresh()->stopped_at)->not->toBeNull();
});

test('cross-workspace experiment access is forbidden', function () {
    ['user' => $user] = asExperimentAdmin();
    $other = Workspace::factory()->create();
    $otherAgent = Agent::factory()->create(['workspace_id' => $other->id]);
    $otherExp = Experiment::factory()->create(['agent_id' => $otherAgent->id]);

    $this->actingAs($user)->post("/app/experiments/{$otherExp->id}/start")->assertForbidden();
});
