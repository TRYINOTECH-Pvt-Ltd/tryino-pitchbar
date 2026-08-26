<?php

use App\Models\Agent;
use App\Models\CuratedAnswer;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceUser;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function curatedAdmin(): array
{
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create([
        'owner_user_id' => $user->id,
        'slug' => 'acme',
    ]);
    WorkspaceUser::create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'role' => 'admin',
        'invited_at' => now(),
        'accepted_at' => now(),
    ]);
    $user->forceFill(['default_workspace_id' => $workspace->id])->save();
    $agent = Agent::factory()->create([
        'workspace_id' => $workspace->id,
        'is_published' => true,
    ]);

    return ['user' => $user, 'workspace' => $workspace, 'agent' => $agent];
}

test('store accepts kb_title and kb_published and persists them', function () {
    ['user' => $user, 'agent' => $agent] = curatedAdmin();

    $this->actingAs($user)
        ->post("/app/agents/{$agent->id}/curated", [
            'question_pattern' => 'How do I reset my password',
            'answer' => 'Click forgot password.',
            'kb_title' => 'Reset your password',
            'kb_published' => true,
        ])
        ->assertRedirect();

    $row = CuratedAnswer::query()
        ->withoutWorkspaceScope()
        ->where('agent_id', $agent->id)
        ->firstOrFail();

    expect($row->kb_title)->toBe('Reset your password');
    expect($row->kb_published)->toBeTrue();
    expect($row->slug)->toBe('how-do-i-reset-my-password');
});

test('update can toggle kb_published without touching answer text', function () {
    ['user' => $user, 'agent' => $agent] = curatedAdmin();

    $answer = CuratedAnswer::query()
        ->withoutWorkspaceScope()
        ->create([
            'agent_id' => $agent->id,
            'question_pattern' => 'Pricing',
            'answer' => 'Plans start at $49/mo.',
            'enabled' => true,
            'kb_published' => false,
        ]);

    $this->actingAs($user)
        ->patch("/app/curated-answers/{$answer->id}", [
            'kb_published' => true,
        ])
        ->assertRedirect();

    $answer->refresh();
    expect($answer->kb_published)->toBeTrue();
    expect($answer->answer)->toBe('Plans start at $49/mo.');
});

test('update can rename kb_title without changing other fields', function () {
    ['user' => $user, 'agent' => $agent] = curatedAdmin();

    $answer = CuratedAnswer::query()
        ->withoutWorkspaceScope()
        ->create([
            'agent_id' => $agent->id,
            'question_pattern' => 'Refunds',
            'answer' => '30 days, full refund.',
            'enabled' => true,
        ]);

    $this->actingAs($user)
        ->patch("/app/curated-answers/{$answer->id}", [
            'kb_title' => 'Our refund policy',
        ])
        ->assertRedirect();

    $answer->refresh();
    expect($answer->kb_title)->toBe('Our refund policy');
    expect($answer->question_pattern)->toBe('Refunds');
});

test('index exposes kb_url and workspace_slug for each answer', function () {
    ['user' => $user, 'workspace' => $workspace, 'agent' => $agent] = curatedAdmin();

    CuratedAnswer::query()
        ->withoutWorkspaceScope()
        ->create([
            'agent_id' => $agent->id,
            'question_pattern' => 'How to install',
            'answer' => 'Run composer install.',
            'enabled' => true,
            'kb_published' => true,
        ]);

    $this->actingAs($user)
        ->get("/app/agents/{$agent->id}/curated")
        ->assertOk()
        ->assertInertia(
            fn ($page) => $page
                ->component('app/agents/curated')
                ->where('workspace_slug', $workspace->slug)
                ->has('answers', 1, fn ($a) => $a
                    ->where('slug', 'how-to-install')
                    ->where('kb_published', true)
                    ->where('kb_url', "/kb/{$workspace->slug}/how-to-install")
                    ->etc()),
        );
});
