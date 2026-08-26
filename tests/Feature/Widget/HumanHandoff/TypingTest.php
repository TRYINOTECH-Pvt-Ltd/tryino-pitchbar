<?php

use App\Models\Agent;
use App\Models\Conversation;
use App\Models\User;
use App\Models\Visitor;
use App\Models\Workspace;
use App\Models\WorkspaceUser;
use App\Services\Widget\WidgetJwt;

function typingConv(): array
{
    $workspace = Workspace::factory()->create();
    $agent = Agent::factory()->published()->create([
        'workspace_id' => $workspace->id,
        'allowed_origins' => ['https://example.com'],
    ]);
    $visitor = Visitor::factory()->create(['agent_id' => $agent->id]);
    $conv = Conversation::factory()->create([
        'agent_id' => $agent->id,
        'visitor_id' => $visitor->id,
    ]);
    $jwt = app(WidgetJwt::class)->issue($agent->id, $visitor->id, $conv->id);

    return [
        'workspace' => $workspace,
        'conv' => $conv,
        'jwt' => $jwt['token'],
    ];
}

test('visitor typing endpoint sets visitor_typing_until ~5s in the future', function () {
    ['conv' => $conv, 'jwt' => $jwt] = typingConv();

    $this->withHeaders(['Authorization' => "Bearer {$jwt}"])
        ->postJson('/api/v1/widget/typing')
        ->assertOk();

    $until = $conv->fresh()->visitor_typing_until;
    expect($until)->not->toBeNull();
    expect($until->timestamp - now()->timestamp)->toBeGreaterThan(3);
    expect($until->timestamp - now()->timestamp)->toBeLessThan(7);
});

test('operator typing endpoint sets operator_typing_until', function () {
    ['workspace' => $workspace, 'conv' => $conv] = typingConv();
    $user = User::factory()->create();
    WorkspaceUser::create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'role' => 'admin',
        'invited_at' => now(),
        'accepted_at' => now(),
    ]);

    $this->actingAs($user)
        ->postJson("/app/conversations/{$conv->id}/typing")
        ->assertOk();

    $until = $conv->fresh()->operator_typing_until;
    expect($until)->not->toBeNull();
    expect($until->isFuture())->toBeTrue();
});

test('visitor poll exposes operator_typing flag while window is fresh', function () {
    ['workspace' => $workspace, 'conv' => $conv, 'jwt' => $jwt] = typingConv();
    $user = User::factory()->create();
    WorkspaceUser::create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'role' => 'admin',
        'invited_at' => now(),
        'accepted_at' => now(),
    ]);

    // Operator fires typing.
    $this->actingAs($user)
        ->postJson("/app/conversations/{$conv->id}/typing")
        ->assertOk();

    // Visitor poll should now report operator_typing=true.
    $body = $this->withHeaders(['Authorization' => "Bearer {$jwt}"])
        ->get('/api/v1/widget/conversation/messages')
        ->json('data');

    expect($body['operator_typing'])->toBeTrue();
});

test('operator_typing flips to false once the window expires', function () {
    ['conv' => $conv, 'jwt' => $jwt] = typingConv();

    // Set the column directly to a past timestamp — simulating a
    // typing event from 6 seconds ago.
    $conv->forceFill(['operator_typing_until' => now()->subSeconds(1)])->save();

    $body = $this->withHeaders(['Authorization' => "Bearer {$jwt}"])
        ->get('/api/v1/widget/conversation/messages')
        ->json('data');

    expect($body['operator_typing'])->toBeFalse();
});
