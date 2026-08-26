<?php

use App\Models\AuditLog;
use App\Models\Conversation;
use App\Models\DsrRequest;
use App\Models\Event;
use App\Models\Lead;
use App\Models\Message;
use App\Models\Visitor;
use App\Services\Gdpr\Eraser;
use App\Services\Gdpr\Exporter;
use App\Services\Gdpr\VisitorResolver;

function dsrSeed(string $agentId, string $email = 'subject@example.com'): array
{
    $visitor = Visitor::factory()->create(['agent_id' => $agentId]);
    $conversation = Conversation::factory()->create([
        'agent_id' => $agentId,
        'visitor_id' => $visitor->id,
        'page_url' => 'https://buyer.test/pricing',
    ]);
    $lead = Lead::create([
        'conversation_id' => $conversation->id,
        'agent_id' => $agentId,
        'email' => $email,
        'name' => 'Test Subject',
        'phone' => '+15550100',
        'status' => 'new',
        'fields' => ['plan' => 'pro'],
    ]);
    $message = Message::create([
        'conversation_id' => $conversation->id,
        'role' => 'user',
        'content' => 'Hello can I get pricing?',
        'citations' => null,
    ]);

    return compact('visitor', 'conversation', 'lead', 'message');
}

test('lookup by email returns visitor when in workspace', function () {
    ['user' => $user, 'agent' => $agent] = workspaceMemberWithAgent(['role' => 'admin']);
    $seed = dsrSeed($agent->id, 'alice@example.test');

    $this->actingAs($user)
        ->postJson('/app/dsr/lookup', ['email' => 'alice@example.test'])
        ->assertOk()
        ->assertJsonPath('count', 1)
        ->assertJsonPath('matches.0.visitor_id', $seed['visitor']->id);
});

test('lookup against another workspace returns empty', function () {
    ['user' => $user] = workspaceMemberWithAgent(['role' => 'admin']);
    $foreign = workspaceMemberWithAgent(['role' => 'admin'], ['name' => 'Foreign']);
    dsrSeed($foreign['agent']->id, 'foreign@example.test');

    $this->actingAs($user)
        ->postJson('/app/dsr/lookup', ['email' => 'foreign@example.test'])
        ->assertOk()
        ->assertJsonPath('count', 0);
});

test('lookup requires at least one criterion', function () {
    ['user' => $user] = workspaceMemberWithAgent(['role' => 'admin']);

    $this->actingAs($user)
        ->postJson('/app/dsr/lookup', [])
        ->assertStatus(422);
});

test('lookup by anonymous_id returns visitor', function () {
    ['user' => $user, 'agent' => $agent] = workspaceMemberWithAgent(['role' => 'admin']);
    $seed = dsrSeed($agent->id);

    $this->actingAs($user)
        ->postJson('/app/dsr/lookup', ['anonymous_id' => $seed['visitor']->anonymous_id])
        ->assertOk()
        ->assertJsonPath('count', 1)
        ->assertJsonPath('matches.0.visitor_id', $seed['visitor']->id);
});

test('viewer cannot lookup', function () {
    ['user' => $user] = workspaceMemberWithAgent(['role' => 'viewer']);

    $this->actingAs($user)
        ->postJson('/app/dsr/lookup', ['email' => 'x@y.test'])
        ->assertForbidden();
});

test('editor cannot erase', function () {
    ['user' => $user, 'agent' => $agent] = workspaceMemberWithAgent(['role' => 'editor']);
    $seed = dsrSeed($agent->id);

    $this->actingAs($user)
        ->postJson('/app/dsr/erase', [
            'visitor_id' => $seed['visitor']->id,
            'confirm_typed' => 'ERASE',
        ])
        ->assertForbidden();
});

test('export returns visitor payload + audit row', function () {
    ['user' => $user, 'agent' => $agent, 'workspace' => $workspace] = workspaceMemberWithAgent(['role' => 'admin']);
    $seed = dsrSeed($agent->id);

    $response = $this->actingAs($user)
        ->postJson('/app/dsr/export', ['visitor_id' => $seed['visitor']->id])
        ->assertOk();

    $response->assertJsonPath('payload.visitor.id', $seed['visitor']->id);
    $response->assertJsonPath('payload.leads.0.email', $seed['lead']->email);
    expect($response->json('payload.conversations.0.messages.0.content'))->toBe('Hello can I get pricing?');

    $dsr = DsrRequest::query()->withoutGlobalScopes()->where('workspace_id', $workspace->id)->first();
    expect($dsr->action)->toBe('export');
    expect($dsr->status)->toBe('completed');

    expect(AuditLog::query()->where('action', 'dsr.exported')->count())->toBe(1);
});

test('erase nulls lead PII, deletes visitor, retains messages', function () {
    ['user' => $user, 'agent' => $agent, 'workspace' => $workspace] = workspaceMemberWithAgent(['role' => 'admin']);
    $seed = dsrSeed($agent->id);

    Event::create([
        'workspace_id' => $workspace->id,
        'agent_id' => $agent->id,
        'conversation_id' => $seed['conversation']->id,
        'kind' => 'cta.clicked',
        'payload' => ['visitor_id' => $seed['visitor']->id],
        'created_at' => now(),
    ]);

    $this->actingAs($user)
        ->postJson('/app/dsr/erase', [
            'visitor_id' => $seed['visitor']->id,
            'confirm_typed' => 'ERASE',
        ])
        ->assertOk()
        ->assertJsonPath('status', 'completed');

    expect(Visitor::query()->withoutGlobalScopes()->find($seed['visitor']->id))->toBeNull();

    $lead = Lead::query()->withoutGlobalScopes()->find($seed['lead']->id);
    expect($lead->email)->toBeNull();
    expect($lead->phone)->toBeNull();
    expect($lead->name)->toBeNull();
    expect($lead->fields)->toBe([]);

    $message = Message::find($seed['message']->id);
    expect($message)->not->toBeNull();
    expect($message->content)->toBe('Hello can I get pricing?');

    $conv = Conversation::query()->withoutGlobalScopes()->find($seed['conversation']->id);
    expect($conv)->not->toBeNull();
    expect($conv->visitor_id)->toBeNull();

    expect(Event::query()->where('conversation_id', $seed['conversation']->id)->count())->toBe(0);

    expect(AuditLog::query()->where('action', 'dsr.erased')->count())->toBe(1);
});

test('erase requires confirm_typed=ERASE', function () {
    ['user' => $user, 'agent' => $agent] = workspaceMemberWithAgent(['role' => 'admin']);
    $seed = dsrSeed($agent->id);

    $this->actingAs($user)
        ->postJson('/app/dsr/erase', [
            'visitor_id' => $seed['visitor']->id,
            'confirm_typed' => 'delete',
        ])
        ->assertStatus(422);

    expect(Visitor::query()->withoutGlobalScopes()->find($seed['visitor']->id))->not->toBeNull();
});

test('erase against another workspace 404s', function () {
    ['user' => $user] = workspaceMemberWithAgent(['role' => 'admin']);
    $foreign = workspaceMemberWithAgent(['role' => 'admin'], ['name' => 'Foreign']);
    $seed = dsrSeed($foreign['agent']->id);

    $this->actingAs($user)
        ->postJson('/app/dsr/erase', [
            'visitor_id' => $seed['visitor']->id,
            'confirm_typed' => 'ERASE',
        ])
        ->assertNotFound();

    expect(Visitor::query()->withoutGlobalScopes()->find($seed['visitor']->id))->not->toBeNull();
});

test('exporter and eraser services compose correctly', function () {
    ['agent' => $agent, 'workspace' => $workspace] = workspaceMemberWithAgent(['role' => 'admin']);
    $seed = dsrSeed($agent->id);

    $resolver = app(VisitorResolver::class);
    $exporter = app(Exporter::class);
    $eraser = app(Eraser::class);

    $found = $resolver->resolve($workspace, ['email' => $seed['lead']->email]);
    expect($found)->toHaveCount(1);

    $payload = $exporter->export($found->first());
    expect($payload['leads'][0]['email'])->toBe($seed['lead']->email);

    $eraser->erase($found->first());
    expect(Visitor::query()->withoutGlobalScopes()->find($seed['visitor']->id))->toBeNull();
});
