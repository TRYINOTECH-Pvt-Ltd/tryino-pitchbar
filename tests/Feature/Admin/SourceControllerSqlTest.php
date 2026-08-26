<?php

use App\Jobs\Crawl\SyncSqlSourceJob;
use App\Models\Agent;
use App\Models\Source;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceUser;
use Illuminate\Support\Facades\Queue;

function sqlTestActor(string $role = 'owner'): array
{
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['owner_user_id' => $user->id]);
    WorkspaceUser::create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'role' => $role,
        'invited_at' => now(),
        'accepted_at' => now(),
    ]);
    $user->forceFill(['default_workspace_id' => $workspace->id])->save();
    $agent = Agent::factory()->create(['workspace_id' => $workspace->id]);

    return ['user' => $user, 'workspace' => $workspace, 'agent' => $agent];
}

test('owner can add a SQL source with encrypted credentials', function () {
    Queue::fake();
    ['user' => $user, 'agent' => $agent] = sqlTestActor();

    $payload = [
        'driver' => 'mysql',
        'host' => 'db.example.com',
        'port' => 3306,
        'database' => 'crm',
        'username' => 'readonly',
        'password' => 'secret-pw',
        'query' => 'SELECT id, name FROM customers WHERE active = 1',
        'title' => 'Production CRM',
        'title_column' => 'name',
        'body_column' => null,
    ];

    $response = $this->actingAs($user)->post(
        "/app/agents/{$agent->id}/sources/sql",
        $payload,
    );
    $response->assertRedirect();

    $source = Source::query()->where('agent_id', $agent->id)->where('type', 'sql')->firstOrFail();

    expect($source->config['driver'])->toBe('mysql');
    expect($source->config['query'])->toBe('SELECT id, name FROM customers WHERE active = 1');
    expect($source->config['title'])->toBe('Production CRM');

    expect($source->credentials_encrypted)->toBeArray();
    expect($source->credentials_encrypted['host'])->toBe('db.example.com');
    expect($source->credentials_encrypted['password'])->toBe('secret-pw');

    // Raw column on the row is ciphertext — never the plaintext password.
    $raw = (string) $source->getRawOriginal('credentials_encrypted');
    expect($raw)->not->toContain('secret-pw');
    expect($raw)->not->toContain('db.example.com');

    Queue::assertPushed(SyncSqlSourceJob::class);
});

test('non-SELECT queries are rejected at the validator boundary', function () {
    Queue::fake();
    ['user' => $user, 'agent' => $agent] = sqlTestActor();

    $response = $this->actingAs($user)->post(
        "/app/agents/{$agent->id}/sources/sql",
        [
            'driver' => 'mysql', 'host' => 'db.example.com', 'port' => 3306,
            'database' => 'd', 'username' => 'u', 'password' => 'p',
            'query' => 'DELETE FROM customers',
        ],
    );

    $response->assertSessionHasErrors('query');
    expect(Source::query()->where('agent_id', $agent->id)->count())->toBe(0);
    Queue::assertNothingPushed();
});

test('unsupported drivers are rejected', function () {
    Queue::fake();
    ['user' => $user, 'agent' => $agent] = sqlTestActor();

    $response = $this->actingAs($user)->post(
        "/app/agents/{$agent->id}/sources/sql",
        [
            'driver' => 'oracle', 'host' => 'db.example.com', 'port' => 1521,
            'database' => 'd', 'username' => 'u', 'password' => 'p',
            'query' => 'SELECT 1 FROM dual',
        ],
    );

    $response->assertSessionHasErrors('driver');
    Queue::assertNothingPushed();
});

test('viewer role cannot add a SQL source', function () {
    Queue::fake();
    ['user' => $user, 'agent' => $agent] = sqlTestActor('viewer');

    $response = $this->actingAs($user)->post(
        "/app/agents/{$agent->id}/sources/sql",
        [
            'driver' => 'mysql', 'host' => 'db.example.com', 'port' => 3306,
            'database' => 'd', 'username' => 'u', 'password' => 'p',
            'query' => 'SELECT 1',
        ],
    );

    $response->assertForbidden();
    Queue::assertNothingPushed();
});
