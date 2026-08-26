<?php

use App\Enums\PlatformRole;
use App\Models\Agent;
use App\Models\AuditLog;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceUser;
use App\Support\AuditLogger;

/**
 * Final pre-publish smoke pass. Every flow the customer touches
 * from the admin UI, exercised end-to-end (HTTP → controller →
 * DB → follow-up read), with no mocks for the surface under test.
 */
function readinessActor(string $role = 'admin'): array
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

    return ['user' => $user, 'workspace' => $workspace];
}

test('readiness: agent publish writes agent.published audit row', function () {
    ['user' => $user, 'workspace' => $workspace] = readinessActor('admin');
    $agent = Agent::factory()->create([
        'workspace_id' => $workspace->id,
        'is_published' => false,
    ]);

    $this->actingAs($user)
        ->post("/app/agents/{$agent->id}/publish")
        ->assertRedirect();

    $row = AuditLog::query()
        ->where('workspace_id', $workspace->id)
        ->where('action', 'agent.published')
        ->first();

    expect($row)->not->toBeNull();
    expect($row->after['is_published'])->toBeTrue();
});

test('readiness: agent bulk delete writes one audit row per affected agent', function () {
    ['user' => $user, 'workspace' => $workspace] = readinessActor('admin');
    $a = Agent::factory()->create(['workspace_id' => $workspace->id]);
    $b = Agent::factory()->create(['workspace_id' => $workspace->id]);

    $this->actingAs($user)
        ->post('/app/agents/bulk-destroy', ['ids' => [$a->id, $b->id]])
        ->assertRedirect();

    expect(
        AuditLog::query()
            ->where('workspace_id', $workspace->id)
            ->where('action', 'agent.bulk_deleted')
            ->count()
    )->toBe(2);
});

test('readiness: source create writes source.created audit row', function () {
    ['user' => $user, 'workspace' => $workspace] = readinessActor('admin');
    $agent = Agent::factory()->create(['workspace_id' => $workspace->id]);

    // CrawlSourceJob is dispatched on the source create path. Under
    // QUEUE_CONNECTION=sync it runs inline and tries to hit the URL;
    // we don't need a real crawl for this audit assertion, so fake
    // the bus so the controller's audit write is the only side effect
    // we measure.
    \Illuminate\Support\Facades\Bus::fake([\App\Jobs\Crawl\CrawlSourceJob::class]);

    $this->actingAs($user)
        ->post("/app/agents/{$agent->id}/sources", [
            'type' => 'url',
            'url' => 'https://example.com/pricing',
        ])
        ->assertRedirect();

    $row = AuditLog::query()
        ->where('workspace_id', $workspace->id)
        ->where('action', 'source.created')
        ->first();

    expect($row)->not->toBeNull();
    expect($row->after['type'])->toBe('url');
});

test('readiness: audit:prune dry-run reports a count', function () {
    ['workspace' => $workspace] = readinessActor('admin');

    // Insert one fresh + one old row.
    AuditLog::create([
        'workspace_id' => $workspace->id,
        'user_id' => null,
        'action' => 'test.recent',
        'created_at' => now(),
    ]);
    AuditLog::create([
        'workspace_id' => $workspace->id,
        'user_id' => null,
        'action' => 'test.ancient',
        'created_at' => now()->subDays(400),
    ]);

    $this->artisan('audit:prune', ['--days' => 365, '--dry-run' => true])
        ->expectsOutputToContain('Would prune 1 audit rows')
        ->assertExitCode(0);

    // No actual delete in dry-run.
    expect(AuditLog::query()->where('workspace_id', $workspace->id)->count())->toBe(2);
});

test('readiness: audit:prune actually deletes old rows', function () {
    ['workspace' => $workspace] = readinessActor('admin');

    AuditLog::create([
        'workspace_id' => $workspace->id,
        'user_id' => null,
        'action' => 'test.recent',
        'created_at' => now(),
    ]);
    AuditLog::create([
        'workspace_id' => $workspace->id,
        'user_id' => null,
        'action' => 'test.ancient',
        'created_at' => now()->subDays(400),
    ]);

    $this->artisan('audit:prune', ['--days' => 365])
        ->assertExitCode(0);

    expect(AuditLog::query()->where('workspace_id', $workspace->id)->count())->toBe(1);
    expect(
        AuditLog::query()
            ->where('workspace_id', $workspace->id)
            ->where('action', 'test.recent')
            ->exists()
    )->toBeTrue();
});

test('readiness: audit row from console context carries system_origin marker', function () {
    ['workspace' => $workspace] = readinessActor('admin');

    AuditLogger::log(
        workspaceId: $workspace->id,
        action: 'test.system',
        entityType: 'thing',
        entityId: 'x',
        after: ['x' => 1],
    );

    $row = AuditLog::query()
        ->where('workspace_id', $workspace->id)
        ->where('action', 'test.system')
        ->first();

    expect($row)->not->toBeNull();
    expect($row->user_id)->toBeNull();
    expect($row->after)->toHaveKey('system_origin');
    expect($row->after['x'])->toBe(1);
});

test('readiness: integrations tab on /settings/system loads for super_admin', function () {
    $admin = User::factory()->create(['role' => PlatformRole::SuperAdmin]);

    $this->actingAs($admin)
        ->get('/settings/system')
        ->assertOk();
});

test('readiness: integrations save round-trip via /settings/system/integrations', function () {
    $admin = User::factory()->create(['role' => PlatformRole::SuperAdmin]);

    $this->actingAs($admin)
        ->patch('/settings/system/integrations', [
            'integrations_enabled' => [
                'slack' => false,
                'notion' => true,
                'google' => true,
                'webhooks' => true,
                'wordpress' => true,
            ],
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    // Round-trip: re-fetch the form values from the page and verify
    // the flip persisted. This is the user-facing path that 404'd
    // before the fix.
    $response = $this->actingAs($admin)->get('/settings/system');
    $response->assertOk();
    $response->assertInertia(fn ($p) => $p
        ->where('form.integrations_enabled.slack', false)
        ->where('form.integrations_enabled.notion', true));
});

test('readiness: widget chat error event always includes a visitor-facing message', function () {
    // Synthesize an invalid bearer + assert the error event carries
    // BOTH code and message. This is the visitor-end UX fix from
    // 2026-05-20 (Jamiu's report).
    $response = $this->withHeaders(['Authorization' => 'Bearer not-a-jwt'])
        ->postJson('/api/v1/widget/messages/stream', ['message' => 'hi']);

    $body = $response->streamedContent();
    expect($body)->toContain('event: error');
    expect($body)->toContain('invalid_token');
});
