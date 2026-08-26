<?php

use App\Models\AuditLog;
use App\Models\User;

function makeAuditLog(string $workspaceId, array $overrides = []): AuditLog
{
    return AuditLog::create(array_merge([
        'workspace_id' => $workspaceId,
        'user_id' => null,
        'action' => 'test.action',
        'entity_type' => 'integration',
        'entity_id' => 'slack',
        'before' => [],
        'after' => ['status' => 'ok'],
        'ip' => '127.0.0.1',
        'ua' => 'PestRunner/1.0',
        'created_at' => now(),
    ], $overrides));
}

test('admin sees audit page with their workspace rows only', function () {
    ['user' => $user, 'workspace' => $workspace] = workspaceMember(['role' => 'admin']);
    $foreign = workspaceMember(['role' => 'admin']);

    makeAuditLog($workspace->id, ['action' => 'integration.added']);
    makeAuditLog($workspace->id, ['action' => 'integration.removed']);
    makeAuditLog($foreign['workspace']->id, ['action' => 'foreign.action']);

    $this->actingAs($user)
        ->get('/app/audit')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('app/audit/index')
            ->where('pagination.total', 2)
            ->where('rows', fn ($rows) => collect($rows)
                ->pluck('action')
                ->sort()
                ->values()
                ->all() === ['integration.added', 'integration.removed']));
});

test('viewer is blocked from audit page', function () {
    ['user' => $user] = workspaceMember(['role' => 'viewer']);

    $this->actingAs($user)
        ->get('/app/audit')
        ->assertForbidden();
});

test('editor is blocked from audit page', function () {
    ['user' => $user] = workspaceMember(['role' => 'editor']);

    $this->actingAs($user)
        ->get('/app/audit')
        ->assertForbidden();
});

test('action filter narrows results', function () {
    ['user' => $user, 'workspace' => $workspace] = workspaceMember(['role' => 'admin']);
    makeAuditLog($workspace->id, ['action' => 'dsr.exported']);
    makeAuditLog($workspace->id, ['action' => 'dsr.erased']);
    makeAuditLog($workspace->id, ['action' => 'integration.added']);

    $this->actingAs($user)
        ->get('/app/audit?action=dsr')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('pagination.total', 2)
            ->where('filters.action', 'dsr'));
});

test('entity type filter narrows results', function () {
    ['user' => $user, 'workspace' => $workspace] = workspaceMember(['role' => 'admin']);
    makeAuditLog($workspace->id, ['entity_type' => 'integration']);
    makeAuditLog($workspace->id, ['entity_type' => 'integration']);
    makeAuditLog($workspace->id, ['entity_type' => 'dsr_request']);

    $this->actingAs($user)
        ->get('/app/audit?entity_type=dsr_request')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('pagination.total', 1));
});

test('actor email filter joins user table', function () {
    ['user' => $user, 'workspace' => $workspace] = workspaceMember(['role' => 'admin']);
    $other = User::factory()->create(['email' => 'auditor@example.test']);

    makeAuditLog($workspace->id, ['user_id' => $user->id, 'action' => 'a.1']);
    makeAuditLog($workspace->id, ['user_id' => $other->id, 'action' => 'a.2']);
    makeAuditLog($workspace->id, ['user_id' => null, 'action' => 'a.3']);

    $this->actingAs($user)
        ->get('/app/audit?user_email=auditor@example.test')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('pagination.total', 1)
            ->where('rows.0.action', 'a.2'));
});

test('date range filter brackets created_at', function () {
    ['user' => $user, 'workspace' => $workspace] = workspaceMember(['role' => 'admin']);
    makeAuditLog($workspace->id, ['created_at' => now()->subDays(10), 'action' => 'old']);
    makeAuditLog($workspace->id, ['created_at' => now()->subDays(3), 'action' => 'middle']);
    makeAuditLog($workspace->id, ['created_at' => now(), 'action' => 'fresh']);

    $from = now()->subDays(5)->toDateString();

    $this->actingAs($user)
        ->get('/app/audit?from='.$from)
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('pagination.total', 2));
});

test('entity type list is workspace-scoped', function () {
    ['user' => $user, 'workspace' => $workspace] = workspaceMember(['role' => 'admin']);
    $foreign = workspaceMember(['role' => 'admin']);

    makeAuditLog($workspace->id, ['entity_type' => 'integration']);
    makeAuditLog($foreign['workspace']->id, ['entity_type' => 'foreign_only']);

    $this->actingAs($user)
        ->get('/app/audit')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('entityTypes', function ($types) {
                $arr = collect($types)->all();

                return in_array('integration', $arr, true)
                    && ! in_array('foreign_only', $arr, true);
            }));
});

test('pagination shows last_page > 1 when over 25 rows', function () {
    ['user' => $user, 'workspace' => $workspace] = workspaceMember(['role' => 'admin']);
    foreach (range(1, 30) as $i) {
        makeAuditLog($workspace->id, ['action' => "bulk.{$i}"]);
    }

    $this->actingAs($user)
        ->get('/app/audit')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('pagination.last_page', 2)
            ->where('pagination.total', 30));
});
