<?php

use App\Models\Agent;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Models\Visitor;
use App\Models\Workspace;
use App\Services\Widget\WidgetJwt;
use Illuminate\Support\Str;

function widgetJwtFor(Agent $agent): array
{
    $visitor = Visitor::factory()->create(['agent_id' => $agent->id]);
    $conversation = Conversation::factory()->create([
        'agent_id' => $agent->id,
        'visitor_id' => $visitor->id,
    ]);

    /** @var WidgetJwt $jwt */
    $jwt = app(WidgetJwt::class);
    $token = $jwt->issue($agent->id, $visitor->id, $conversation->id)['token'];

    return ['conversation' => $conversation, 'token' => $token];
}

function botMessage(Conversation $c, string $content): Message
{
    return Message::create([
        'id' => (string) Str::uuid7(),
        'conversation_id' => $c->id,
        'role' => 'assistant',
        'content' => $content,
        'citations' => [],
        'tokens_in' => 0,
        'tokens_out' => 0,
        'latency_ms' => 0,
        'model' => '@cf/meta/llama-3.3-70b',
    ]);
}

function humanMessage(Conversation $c, string $content): Message
{
    return Message::create([
        'id' => (string) Str::uuid7(),
        'conversation_id' => $c->id,
        'role' => 'assistant',
        'content' => $content,
        'citations' => [],
        'tokens_in' => 0,
        'tokens_out' => 0,
        'latency_ms' => 0,
        'model' => 'human:42',
    ]);
}

test('returns only human-operator messages, not bot messages', function () {
    $agent = Agent::factory()->create(['workspace_id' => Workspace::factory()->create()->id]);
    ['conversation' => $c, 'token' => $token] = widgetJwtFor($agent);

    botMessage($c, 'I am a bot reply.');
    humanMessage($c, 'Hi, I can help with that — Alice from sales.');
    botMessage($c, 'Another bot reply.');

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/widget/conversation/messages');

    $response->assertOk();
    $response->assertJsonPath('data.is_claimed', false);
    $response->assertJsonCount(1, 'data.messages');
    $response->assertJsonPath('data.messages.0.content', 'Hi, I can help with that — Alice from sales.');
    $response->assertJsonPath('data.messages.0.role', 'human-agent');
});

test('returns is_claimed=true when an operator has claimed', function () {
    $agent = Agent::factory()->create(['workspace_id' => Workspace::factory()->create()->id]);
    ['conversation' => $c, 'token' => $token] = widgetJwtFor($agent);

    $operator = User::factory()->create();
    $c->forceFill(['claimed_by_user_id' => $operator->id, 'claimed_at' => now()])->save();

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/widget/conversation/messages');

    $response->assertOk();
    $response->assertJsonPath('data.is_claimed', true);
});

test('after=<id> returns only messages newer than that id', function () {
    $agent = Agent::factory()->create(['workspace_id' => Workspace::factory()->create()->id]);
    ['conversation' => $c, 'token' => $token] = widgetJwtFor($agent);

    $first = humanMessage($c, 'first');
    $second = humanMessage($c, 'second');
    $third = humanMessage($c, 'third');

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/widget/conversation/messages?after='.urlencode($first->id));

    $response->assertOk();
    $response->assertJsonCount(2, 'data.messages');
    $response->assertJsonPath('data.messages.0.id', $second->id);
    $response->assertJsonPath('data.messages.1.id', $third->id);
});

test('returns 401 with no token', function () {
    $this->getJson('/api/v1/widget/conversation/messages')->assertStatus(401);
});

test('returns 401 with bad token', function () {
    $this->withHeader('Authorization', 'Bearer not-a-jwt')
        ->getJson('/api/v1/widget/conversation/messages')
        ->assertStatus(401);
});

test('returns 404 if the conversation in the JWT no longer exists', function () {
    $agent = Agent::factory()->create(['workspace_id' => Workspace::factory()->create()->id]);
    ['conversation' => $c, 'token' => $token] = widgetJwtFor($agent);

    $c->delete();

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/widget/conversation/messages')
        ->assertStatus(404);
});
