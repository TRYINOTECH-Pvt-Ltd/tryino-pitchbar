<?php

use App\Models\Agent;
use App\Models\Conversation;
use App\Models\Visitor;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

function publishedAgent(): Agent
{
    $workspace = Workspace::factory()->create();

    return Agent::factory()->published()->create([
        'workspace_id' => $workspace->id,
        'allowed_origins' => ['https://example.com'],
    ]);
}

test('returning visitor with the same anon_id resumes their recent conversation', function () {
    $agent = publishedAgent();

    // First visit — creates a fresh conversation + a couple of messages.
    $first = $this->withHeaders(['Origin' => 'https://example.com'])
        ->postJson('/api/v1/widget/init', [
            'agent_id' => $agent->id,
            'page_url' => 'https://example.com/p1',
        ])
        ->assertOk()
        ->json('data');

    $convId = $first['conversation_id'];
    $anonId = $first['anonymous_id'];

    DB::table('messages')->insert([
        [
            'id' => (string) Str::uuid7(),
            'conversation_id' => $convId,
            'role' => 'user',
            'content' => 'what is the price?',
            'citations' => json_encode([]),
            'tokens_in' => 0,
            'tokens_out' => 0,
            'latency_ms' => 0,
            'model' => null,
            'created_at' => now()->subMinutes(2),
        ],
        [
            'id' => (string) Str::uuid7(),
            'conversation_id' => $convId,
            'role' => 'assistant',
            'content' => 'The price is $999.',
            'citations' => json_encode([['id' => 1, 'url' => 'https://example.com/p1']]),
            'tokens_in' => 0,
            'tokens_out' => 0,
            'latency_ms' => 0,
            'model' => 'gpt-4o-mini',
            'created_at' => now()->subMinutes(1),
        ],
    ]);

    // Second visit (page reload) — same anon_id. Should resume.
    $second = $this->withHeaders(['Origin' => 'https://example.com'])
        ->postJson('/api/v1/widget/init', [
            'agent_id' => $agent->id,
            'page_url' => 'https://example.com/p1',
            'anon_id' => $anonId,
        ])
        ->assertOk()
        ->json('data');

    expect($second['conversation_id'])->toBe($convId);
    expect($second['messages'])->toHaveCount(2);
    expect($second['messages'][0]['role'])->toBe('user');
    expect($second['messages'][0]['content'])->toBe('what is the price?');
    expect($second['messages'][1]['role'])->toBe('assistant');
    expect($second['messages'][1]['content'])->toBe('The price is $999.');
    expect($second['messages'][1]['citations'])->toBe([['id' => 1, 'url' => 'https://example.com/p1']]);
});

test('a different anon_id never sees another visitor\'s history', function () {
    $agent = publishedAgent();

    // Visitor A starts a conversation with messages.
    $a = $this->withHeaders(['Origin' => 'https://example.com'])
        ->postJson('/api/v1/widget/init', ['agent_id' => $agent->id])
        ->assertOk()
        ->json('data');

    DB::table('messages')->insert([
        'id' => (string) Str::uuid7(),
        'conversation_id' => $a['conversation_id'],
        'role' => 'user',
        'content' => 'secret question from visitor A',
        'citations' => json_encode([]),
        'tokens_in' => 0,
        'tokens_out' => 0,
        'latency_ms' => 0,
        'model' => null,
        'created_at' => now(),
    ]);

    // Visitor B (no anon_id provided — gets a fresh one).
    $b = $this->withHeaders(['Origin' => 'https://example.com'])
        ->postJson('/api/v1/widget/init', ['agent_id' => $agent->id])
        ->assertOk()
        ->json('data');

    expect($b['conversation_id'])->not->toBe($a['conversation_id']);
    expect($b['anonymous_id'])->not->toBe($a['anonymous_id']);
    expect($b['messages'])->toBe([]);
});

test('a stale conversation (older than 24h) is NOT resumed — start fresh', function () {
    $agent = publishedAgent();

    $visitor = Visitor::factory()->create([
        'agent_id' => $agent->id,
        'anonymous_id' => 'anon_stale',
    ]);
    $stale = Conversation::factory()->create([
        'agent_id' => $agent->id,
        'visitor_id' => $visitor->id,
        'started_at' => now()->subDays(2),
    ]);

    $resp = $this->withHeaders(['Origin' => 'https://example.com'])
        ->postJson('/api/v1/widget/init', [
            'agent_id' => $agent->id,
            'anon_id' => 'anon_stale',
        ])
        ->assertOk()
        ->json('data');

    expect($resp['conversation_id'])->not->toBe($stale->id);
    expect($resp['messages'])->toBe([]);
});

test('resumed conversation pins the latest page_url so prompt context stays accurate', function () {
    $agent = publishedAgent();

    $first = $this->withHeaders(['Origin' => 'https://example.com'])
        ->postJson('/api/v1/widget/init', [
            'agent_id' => $agent->id,
            'page_url' => 'https://example.com/products/a',
        ])
        ->json('data');

    $second = $this->withHeaders(['Origin' => 'https://example.com'])
        ->postJson('/api/v1/widget/init', [
            'agent_id' => $agent->id,
            'page_url' => 'https://example.com/products/b',
            'anon_id' => $first['anonymous_id'],
        ])
        ->json('data');

    expect($second['conversation_id'])->toBe($first['conversation_id']);
    $conv = Conversation::find($second['conversation_id']);
    expect($conv->page_url)->toBe('https://example.com/products/b');
});
