<?php

use App\Models\Agent;
use App\Models\Conversation;
use App\Models\Lead;
use App\Models\Message;
use App\Models\Visitor;
use App\Models\Workspace;
use App\Services\Widget\WidgetJwt;
use Illuminate\Support\Str;

function makeVisitorAndJwt(Agent $agent): array
{
    $visitor = Visitor::factory()->create(['agent_id' => $agent->id]);
    $conversation = Conversation::factory()->create([
        'agent_id' => $agent->id,
        'visitor_id' => $visitor->id,
    ]);

    Message::create([
        'id' => (string) Str::uuid7(),
        'conversation_id' => $conversation->id,
        'role' => 'user',
        'content' => 'hi',
        'citations' => [],
        'tokens_in' => 0,
        'tokens_out' => 0,
        'latency_ms' => 0,
    ]);
    Message::create([
        'id' => (string) Str::uuid7(),
        'conversation_id' => $conversation->id,
        'role' => 'assistant',
        'content' => 'hello',
        'citations' => [],
        'tokens_in' => 0,
        'tokens_out' => 0,
        'latency_ms' => 0,
    ]);

    Lead::create([
        'conversation_id' => $conversation->id,
        'agent_id' => $agent->id,
        'email' => 'visitor@example.com',
        'status' => 'new',
        'fields' => [],
    ]);

    /** @var WidgetJwt $jwt */
    $jwt = app(WidgetJwt::class);
    $token = $jwt->issue($agent->id, $visitor->id, $conversation->id)['token'];

    return ['visitor' => $visitor, 'conversation' => $conversation, 'token' => $token];
}

test('delete wipes the visitor, their conversations, messages, and leads', function () {
    $agent = Agent::factory()->create(['workspace_id' => Workspace::factory()->create()->id]);
    ['visitor' => $visitor, 'conversation' => $c, 'token' => $token] = makeVisitorAndJwt($agent);

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->deleteJson('/api/v1/widget/me');

    $response->assertOk();
    $response->assertJsonPath('data.ok', true);
    $response->assertJsonPath('data.conversations', 1);
    $response->assertJsonPath('data.messages', 2);
    $response->assertJsonPath('data.leads', 1);

    expect(Visitor::query()->withoutGlobalScopes()->where('id', $visitor->id)->exists())->toBeFalse();
    expect(Conversation::query()->withoutGlobalScopes()->where('id', $c->id)->exists())->toBeFalse();
    expect(Message::query()->where('conversation_id', $c->id)->exists())->toBeFalse();
    expect(Lead::query()->withoutGlobalScopes()->where('conversation_id', $c->id)->exists())->toBeFalse();
});

test('delete returns 401 without a token', function () {
    $this->deleteJson('/api/v1/widget/me')->assertStatus(401);
});

test('delete returns 401 for a malformed token', function () {
    $this->withHeader('Authorization', 'Bearer not-a-valid-jwt')
        ->deleteJson('/api/v1/widget/me')
        ->assertStatus(401);
});

test('delete only touches the holder of the JWT, not other visitors', function () {
    $agent = Agent::factory()->create(['workspace_id' => Workspace::factory()->create()->id]);
    ['token' => $token] = makeVisitorAndJwt($agent);
    ['visitor' => $other] = makeVisitorAndJwt($agent);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->deleteJson('/api/v1/widget/me')
        ->assertOk();

    // The other visitor should be untouched.
    expect(Visitor::query()->withoutGlobalScopes()->where('id', $other->id)->exists())->toBeTrue();
});

test('delete is a no-op when the visitor row is already gone', function () {
    $agent = Agent::factory()->create(['workspace_id' => Workspace::factory()->create()->id]);
    ['visitor' => $visitor, 'token' => $token] = makeVisitorAndJwt($agent);

    $visitor->delete();

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->deleteJson('/api/v1/widget/me');

    $response->assertOk();
    $response->assertJsonPath('data.conversations', 0);
    $response->assertJsonPath('data.messages', 0);
});
