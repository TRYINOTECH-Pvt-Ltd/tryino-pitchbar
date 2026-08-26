<?php

use App\Models\Agent;
use App\Models\Workspace;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

function clearTestAgent(): Agent
{
    $workspace = Workspace::factory()->create();

    return Agent::factory()->published()->create([
        'workspace_id' => $workspace->id,
        'allowed_origins' => ['https://example.com'],
    ]);
}

test('clear endpoint stamps cleared_at on the JWT conversation', function () {
    $agent = clearTestAgent();

    $init = $this->withHeaders(['Origin' => 'https://example.com'])
        ->postJson('/api/v1/widget/init', ['agent_id' => $agent->id])
        ->assertOk()
        ->json('data');

    $this->withHeaders(['Authorization' => 'Bearer '.$init['jwt']])
        ->postJson('/api/v1/widget/conversation/clear')
        ->assertOk()
        ->assertJsonPath('data.conversation_id', $init['conversation_id']);

    $this->assertNotNull(
        DB::table('conversations')->where('id', $init['conversation_id'])->value('cleared_at'),
    );
});

test('clear endpoint forgets the LLM history cache so the next turn starts fresh', function () {
    $agent = clearTestAgent();

    $init = $this->withHeaders(['Origin' => 'https://example.com'])
        ->postJson('/api/v1/widget/init', ['agent_id' => $agent->id])
        ->assertOk()
        ->json('data');

    // The prompt builder reads history from this cache, not the
    // messages table — a clear that leaves it behind continues the
    // "cleared" conversation on the very next turn.
    $historyKey = "conv:{$init['conversation_id']}:history";
    Cache::put($historyKey, [
        ['role' => 'user', 'content' => 'old question'],
        ['role' => 'assistant', 'content' => 'old answer'],
    ], now()->addHour());

    $this->withHeaders(['Authorization' => 'Bearer '.$init['jwt']])
        ->postJson('/api/v1/widget/conversation/clear')
        ->assertOk();

    expect(Cache::get($historyKey))->toBeNull();
});

test('clear endpoint rejects requests without a token', function () {
    $this->postJson('/api/v1/widget/conversation/clear')
        ->assertStatus(401)
        ->assertJsonPath('error.code', 'missing_token');
});

test('init does not re-hydrate messages from before cleared_at', function () {
    $agent = clearTestAgent();

    $init = $this->withHeaders(['Origin' => 'https://example.com'])
        ->postJson('/api/v1/widget/init', [
            'agent_id' => $agent->id,
            'page_url' => 'https://example.com/p',
        ])
        ->assertOk()
        ->json('data');

    // Two old messages on the conversation, both predating the clear.
    DB::table('messages')->insert([
        [
            'id' => (string) Str::uuid7(),
            'conversation_id' => $init['conversation_id'],
            'role' => 'user',
            'content' => 'what is your pricing?',
            'citations' => json_encode([]),
            'tokens_in' => 0, 'tokens_out' => 0, 'latency_ms' => 0,
            'model' => null,
            'created_at' => now()->subMinutes(5),
        ],
        [
            'id' => (string) Str::uuid7(),
            'conversation_id' => $init['conversation_id'],
            'role' => 'assistant',
            'content' => 'We have a free tier and...',
            'citations' => json_encode([]),
            'tokens_in' => 0, 'tokens_out' => 0, 'latency_ms' => 0,
            'model' => 'gpt-test',
            'created_at' => now()->subMinutes(4),
        ],
    ]);

    // Clear, then re-init with the same anon_id (resumes same conversation).
    $this->withHeaders(['Authorization' => 'Bearer '.$init['jwt']])
        ->postJson('/api/v1/widget/conversation/clear')
        ->assertOk();

    $second = $this->withHeaders(['Origin' => 'https://example.com'])
        ->postJson('/api/v1/widget/init', [
            'agent_id' => $agent->id,
            'anon_id' => $init['anonymous_id'],
        ])
        ->assertOk()
        ->json('data');

    expect($second['conversation_id'])->toBe($init['conversation_id']);
    expect($second['messages'])->toBe([]);
});

test('messages sent after a clear still hydrate on the next init', function () {
    $agent = clearTestAgent();

    $init = $this->withHeaders(['Origin' => 'https://example.com'])
        ->postJson('/api/v1/widget/init', ['agent_id' => $agent->id])
        ->assertOk()
        ->json('data');

    // Pre-clear: an old message.
    DB::table('messages')->insert([[
        'id' => (string) Str::uuid7(),
        'conversation_id' => $init['conversation_id'],
        'role' => 'user',
        'content' => 'old turn',
        'citations' => json_encode([]),
        'tokens_in' => 0, 'tokens_out' => 0, 'latency_ms' => 0,
        'model' => null,
        'created_at' => now()->subMinutes(10),
    ]]);

    $this->withHeaders(['Authorization' => 'Bearer '.$init['jwt']])
        ->postJson('/api/v1/widget/conversation/clear')
        ->assertOk();

    // Post-clear: a fresh turn lands AFTER cleared_at.
    DB::table('messages')->insert([[
        'id' => (string) Str::uuid7(),
        'conversation_id' => $init['conversation_id'],
        'role' => 'user',
        'content' => 'fresh turn',
        'citations' => json_encode([]),
        'tokens_in' => 0, 'tokens_out' => 0, 'latency_ms' => 0,
        'model' => null,
        'created_at' => now()->addSeconds(2),
    ]]);

    $second = $this->withHeaders(['Origin' => 'https://example.com'])
        ->postJson('/api/v1/widget/init', [
            'agent_id' => $agent->id,
            'anon_id' => $init['anonymous_id'],
        ])
        ->assertOk()
        ->json('data');

    expect($second['messages'])->toHaveCount(1);
    expect($second['messages'][0]['content'])->toBe('fresh turn');
});
