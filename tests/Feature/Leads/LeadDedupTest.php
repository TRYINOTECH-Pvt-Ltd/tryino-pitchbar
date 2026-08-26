<?php

use App\Models\Agent;
use App\Models\Conversation;
use App\Models\Lead;
use App\Models\Visitor;
use App\Models\Workspace;
use App\Services\Widget\WidgetJwt;

function leadFixture(): array
{
    $ws = Workspace::factory()->create();
    $agent = Agent::factory()->published()->create([
        'workspace_id' => $ws->id,
        'allowed_origins' => ['https://example.com'],
    ]);
    $visitor = Visitor::factory()->create(['agent_id' => $agent->id]);
    $conv = Conversation::factory()->create([
        'agent_id' => $agent->id,
        'visitor_id' => $visitor->id,
    ]);
    $jwt = app(WidgetJwt::class)->issue($agent->id, $visitor->id, $conv->id);

    return ['agent' => $agent, 'visitor' => $visitor, 'conversation' => $conv, 'jwt' => $jwt['token']];
}

test('same conversation + same email → updates the existing lead, no duplicate', function () {
    ['jwt' => $jwt, 'conversation' => $conv] = leadFixture();

    $this->withHeaders(['Authorization' => "Bearer {$jwt}"])
        ->postJson('/api/v1/widget/leads', [
            'email' => 'alice@example.com',
            'name' => 'Alice',
        ])
        ->assertOk();

    $this->withHeaders(['Authorization' => "Bearer {$jwt}"])
        ->postJson('/api/v1/widget/leads', [
            'email' => 'alice@example.com',
            'name' => 'Alice Smith', // updated last name
            'phone' => '555-0100',
        ])
        ->assertOk();

    expect(Lead::query()->where('conversation_id', $conv->id)->count())->toBe(1);
    $lead = Lead::query()->where('conversation_id', $conv->id)->first();
    expect($lead->name)->toBe('Alice Smith');
    expect($lead->phone)->toBe('555-0100');
});

test('different conversation + same email + same agent → reattaches the lead', function () {
    ['agent' => $agent, 'visitor' => $visitor, 'jwt' => $jwt1] = leadFixture();

    $this->withHeaders(['Authorization' => "Bearer {$jwt1}"])
        ->postJson('/api/v1/widget/leads', ['email' => 'alice@example.com'])
        ->assertOk();

    // Visitor came back, different conversation, submitted again.
    $conv2 = Conversation::factory()->create(['agent_id' => $agent->id, 'visitor_id' => $visitor->id]);
    $jwt2 = app(WidgetJwt::class)->issue($agent->id, $visitor->id, $conv2->id)['token'];

    $this->withHeaders(['Authorization' => "Bearer {$jwt2}"])
        ->postJson('/api/v1/widget/leads', [
            'email' => 'alice@example.com',
            'phone' => '555-0200',
        ])
        ->assertOk();

    expect(Lead::query()->where('agent_id', $agent->id)->count())->toBe(1);
    $lead = Lead::query()->where('agent_id', $agent->id)->first();
    expect($lead->conversation_id)->toBe($conv2->id);
    expect($lead->phone)->toBe('555-0200');
});

test('case + whitespace insensitive — Alice@Example.COM matches alice@example.com', function () {
    ['agent' => $agent, 'jwt' => $jwt] = leadFixture();

    $this->withHeaders(['Authorization' => "Bearer {$jwt}"])
        ->postJson('/api/v1/widget/leads', ['email' => 'alice@example.com'])
        ->assertOk();

    $visitor2 = Visitor::factory()->create(['agent_id' => $agent->id]);
    $conv2 = Conversation::factory()->create(['agent_id' => $agent->id, 'visitor_id' => $visitor2->id]);
    $jwt2 = app(WidgetJwt::class)->issue($agent->id, $visitor2->id, $conv2->id)['token'];

    $this->withHeaders(['Authorization' => "Bearer {$jwt2}"])
        ->postJson('/api/v1/widget/leads', ['email' => '  Alice@Example.COM  '])
        ->assertOk();

    expect(Lead::query()->where('agent_id', $agent->id)->count())->toBe(1);
});

test('different agents keep their leads separate even with same email', function () {
    ['agent' => $agent1, 'jwt' => $jwt1] = leadFixture();
    ['agent' => $agent2, 'jwt' => $jwt2] = leadFixture();

    $this->withHeaders(['Authorization' => "Bearer {$jwt1}"])
        ->postJson('/api/v1/widget/leads', ['email' => 'alice@example.com'])
        ->assertOk();

    $this->withHeaders(['Authorization' => "Bearer {$jwt2}"])
        ->postJson('/api/v1/widget/leads', ['email' => 'alice@example.com'])
        ->assertOk();

    expect(Lead::query()->where('agent_id', $agent1->id)->count())->toBe(1);
    expect(Lead::query()->where('agent_id', $agent2->id)->count())->toBe(1);
    expect(Lead::query()->count())->toBe(2);
});
