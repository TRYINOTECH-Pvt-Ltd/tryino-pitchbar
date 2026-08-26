<?php

use App\Models\Agent;
use App\Models\Conversation;
use App\Models\Visitor;
use App\Models\Workspace;
use App\Services\Widget\WidgetJwt;

function satisfactionConv(): array
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

    return ['conv' => $conv, 'jwt' => $jwt['token']];
}

test('first rating sets satisfaction + satisfaction_at', function () {
    ['conv' => $conv, 'jwt' => $jwt] = satisfactionConv();

    $this->withHeaders(['Authorization' => "Bearer {$jwt}"])
        ->postJson('/api/v1/widget/satisfaction', [
            'rating' => 'positive',
            'comment' => 'Great help, thanks!',
        ])
        ->assertOk();

    $conv->refresh();
    expect($conv->satisfaction)->toBe('positive');
    expect($conv->satisfaction_at)->not->toBeNull();
    expect($conv->satisfaction_comment)->toBe('Great help, thanks!');
});

test('second submission updates the comment but locks the rating', function () {
    ['conv' => $conv, 'jwt' => $jwt] = satisfactionConv();

    $this->withHeaders(['Authorization' => "Bearer {$jwt}"])
        ->postJson('/api/v1/widget/satisfaction', ['rating' => 'positive'])
        ->assertOk();

    $firstAt = $conv->fresh()->satisfaction_at;

    sleep(1);

    $this->withHeaders(['Authorization' => "Bearer {$jwt}"])
        ->postJson('/api/v1/widget/satisfaction', [
            'rating' => 'negative',
            'comment' => 'Actually I changed my mind',
        ])
        ->assertOk();

    $conv->refresh();
    // Rating stays positive — the first one wins.
    expect($conv->satisfaction)->toBe('positive');
    expect($conv->satisfaction_at?->toIso8601String())->toBe(
        $firstAt->toIso8601String(),
    );
    // Comment updated.
    expect($conv->satisfaction_comment)->toBe('Actually I changed my mind');
});

test('rejects unknown rating values', function () {
    ['jwt' => $jwt] = satisfactionConv();

    $this->withHeaders(['Authorization' => "Bearer {$jwt}"])
        ->postJson('/api/v1/widget/satisfaction', ['rating' => 'meh'])
        ->assertStatus(422);
});

test('missing token returns 401', function () {
    $this->postJson('/api/v1/widget/satisfaction', ['rating' => 'positive'])
        ->assertStatus(401);
});
