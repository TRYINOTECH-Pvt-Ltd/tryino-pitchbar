<?php

use App\Enums\PlatformRole;
use App\Jobs\Analytics\PersistUsageJob;
use App\Models\Agent;
use App\Models\AppSetting;
use App\Models\AuditLog;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Plan;
use App\Models\UsageLog;
use App\Models\User;
use App\Models\Visitor;
use App\Models\Workspace;
use App\Models\WorkspaceApiToken;
use App\Models\WorkspaceUser;

/**
 * Integration tests exercising every customer-reported flow end-to-end.
 * Each test simulates the full user journey from HTTP → Inertia prop
 * payload → follow-up action → DB side effect, with no mocks for the
 * controllers/models under test. Acts as the e2e safety net for the
 * 2026-05-20 batch of customer reports.
 */
function reportingSuperAdmin(): array
{
    $admin = User::factory()->create(['role' => PlatformRole::SuperAdmin]);
    $workspace = Workspace::factory()->create(['owner_user_id' => $admin->id]);
    WorkspaceUser::create([
        'workspace_id' => $workspace->id,
        'user_id' => $admin->id,
        'role' => 'admin',
        'invited_at' => now(),
        'accepted_at' => now(),
    ]);
    $admin->forceFill(['default_workspace_id' => $workspace->id])->save();

    return ['admin' => $admin, 'workspace' => $workspace];
}

function reportingMember(string $role = 'admin'): array
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

test('e2e #1 PersistUsageJob survives cascade-deleted agent (FK 1452 root-cause)', function () {
    ['user' => $user, 'workspace' => $workspace] = reportingMember('admin');
    $agent = Agent::factory()->create(['workspace_id' => $workspace->id]);
    $visitor = Visitor::factory()->create(['agent_id' => $agent->id]);
    $conv = Conversation::factory()->create([
        'agent_id' => $agent->id,
        'visitor_id' => $visitor->id,
    ]);
    $msg = Message::factory()->create([
        'conversation_id' => $conv->id,
        'role' => 'assistant',
    ]);
    $msgId = $msg->id;
    $convId = $conv->id;
    $agentId = $agent->id;

    // Real-world reproduction: agent gets hard-deleted between SSE
    // stream completion (which dispatched PersistUsageJob) and worker
    // pickup. Cascade kills the conversation + message rows. Pre-fix
    // the job blew up with SQLSTATE[23000] 1452 because INSERT still
    // requires every referenced parent.
    $this->actingAs($user)
        ->delete(route('agents.destroy', ['agent' => $agentId]))
        ->assertRedirect();

    // Sanity: cascade deletion actually happened.
    expect(Message::query()->find($msgId))->toBeNull();
    expect(Conversation::query()->withoutGlobalScopes()->find($convId))->toBeNull();
    expect(Agent::query()->withoutGlobalScopes()->find($agentId))->toBeNull();

    // Run the queued job. Agent-missing guard returns early — we
    // assert that's the path taken AND that no usage row leaked
    // through. Either no row at all (agent-missing branch) or a row
    // with both FKs nulled out is acceptable; a thrown exception is
    // not.
    $beforeCount = UsageLog::query()->withoutGlobalScopes()->count();

    (new PersistUsageJob(
        conversationId: $convId,
        agentId: $agentId,
        messageId: $msgId,
        calls: [[
            'provider' => 'cloudflare',
            'model' => '@cf/meta/llama-3.3-70b-instruct-fp8-fast',
            'purpose' => 'chat',
            'tokens_in' => 10,
            'tokens_out' => 5,
            'latency_ms' => 50,
        ]],
    ))->handle();

    $afterCount = UsageLog::query()->withoutGlobalScopes()->count();

    // Agent missing → job logs + returns; no insert attempt.
    expect($afterCount)->toBe($beforeCount);
});

test('e2e #1b PersistUsageJob persists row with null FKs when message-only is missing', function () {
    // Tighter coverage: agent + workspace + conversation all alive,
    // but the assistant Message was hard-deleted (e.g. PersistTurnJob
    // failed silently). PersistUsageJob must still write the usage
    // row with message_id=null so analytics keep flowing.
    ['user' => $user, 'workspace' => $workspace] = reportingMember('admin');
    $agent = Agent::factory()->create(['workspace_id' => $workspace->id]);
    $visitor = Visitor::factory()->create(['agent_id' => $agent->id]);
    $conv = Conversation::factory()->create([
        'agent_id' => $agent->id,
        'visitor_id' => $visitor->id,
    ]);

    (new PersistUsageJob(
        conversationId: $conv->id,
        agentId: $agent->id,
        messageId: '019ffff-ffff-ffff-ffff-ffffffffffff',
        calls: [[
            'provider' => 'cloudflare',
            'model' => '@cf/meta/llama-3.3-70b-instruct-fp8-fast',
            'purpose' => 'chat',
            'tokens_in' => 11,
            'tokens_out' => 6,
            'latency_ms' => 60,
        ]],
    ))->handle();

    $row = UsageLog::query()
        ->withoutGlobalScopes()
        ->where('workspace_id', $workspace->id)
        ->first();

    expect($row)->not->toBeNull();
    expect($row->message_id)->toBeNull();
    expect($row->conversation_id)->toBe($conv->id);
    expect($row->tokens_in)->toBe(11);
});

test('e2e #2 admin /admin/workspaces Footprint counts foreign-workspace agents', function () {
    ['admin' => $admin] = reportingSuperAdmin();
    $foreign = Workspace::factory()->create();
    Agent::factory()->count(4)->create(['workspace_id' => $foreign->id]);

    $this->actingAs($admin)
        ->get('/admin/workspaces')
        ->assertOk()
        ->assertInertia(fn ($p) => $p
            ->where('workspaces', fn ($rows) => collect($rows)
                ->firstWhere('id', $foreign->id)['agents_count'] === 4)
        );
});

test('e2e #3 admin /admin/subscriptions reports truthful manual status', function () {
    ['admin' => $admin] = reportingSuperAdmin();

    $pro = Plan::factory()->create([
        'slug' => 'pro-e2e-'.uniqid(),
        'price_cents' => 24900,
        'is_active' => true,
    ]);
    $manualPro = Workspace::factory()->create(['plan_id' => $pro->id]);

    $this->actingAs($admin)
        ->get('/admin/subscriptions')
        ->assertOk()
        ->assertInertia(fn ($p) => $p
            ->where('workspaces', fn ($rows) => collect($rows)
                ->firstWhere('workspace_id', $manualPro->id)['stripe_status'] === 'manual')
        );
});

test('e2e #4 CSAT positive ratings produce non-zero score', function () {
    ['user' => $user, 'workspace' => $workspace] = reportingMember('admin');
    $agent = Agent::factory()->create(['workspace_id' => $workspace->id]);
    $visitor = Visitor::factory()->create(['agent_id' => $agent->id]);

    foreach (['positive', 'positive', 'negative'] as $rating) {
        Conversation::factory()->create([
            'agent_id' => $agent->id,
            'visitor_id' => $visitor->id,
            'satisfaction' => $rating,
            'satisfaction_at' => now(),
            'is_playground' => false,
        ]);
    }

    $this->actingAs($user)
        ->get('/app/analytics')
        ->assertOk()
        ->assertInertia(fn ($p) => $p
            ->where('csat.total_rated', 3)
            ->where('csat.latest_score', 66.7));
});

test('e2e #5 customizable WordPress plugin link reaches the customer integrations page', function () {
    ['user' => $user] = reportingMember('admin');

    $admin = User::factory()->create(['role' => PlatformRole::SuperAdmin]);
    $this->actingAs($admin)
        ->patch('/settings/system/wordpress_plugin', [
            'wordpress_plugin_download_url' => 'https://replibar.com/plugin',
            'wordpress_plugin_help_text' => 'E2E custom blurb.',
        ])
        ->assertRedirect();

    AppSetting::singleton()->forceFill([
        'integrations_enabled' => ['wordpress' => true],
    ])->save();

    $this->actingAs($user)
        ->get('/app/integrations')
        ->assertOk()
        ->assertInertia(fn ($p) => $p
            ->where('wordpressPlugin.download_url', 'https://replibar.com/plugin')
            ->where('wordpressPlugin.help_text', 'E2E custom blurb.'));
});

test('e2e #6 customer actions produce visible audit rows', function () {
    ['user' => $user, 'workspace' => $workspace] = reportingMember('admin');

    $this->actingAs($user)
        ->post('/app/agents', ['name' => 'Audit subject', 'language_default' => 'en'])
        ->assertRedirect();

    $agent = Agent::query()->where('workspace_id', $workspace->id)->first();
    expect($agent)->not->toBeNull();

    $this->actingAs($user)
        ->patch("/app/agents/{$agent->id}", ['name' => 'Audit subject v2'])
        ->assertRedirect();

    $this->actingAs($user)
        ->delete("/app/agents/{$agent->id}")
        ->assertRedirect();

    $this->actingAs($user)
        ->get('/app/audit')
        ->assertOk()
        ->assertInertia(fn ($p) => $p
            ->where('rows', function ($rows) {
                $actions = collect($rows)->pluck('action')->all();

                return in_array('agent.created', $actions, true)
                    && in_array('agent.updated', $actions, true)
                    && in_array('agent.deleted', $actions, true);
            }));
});

test('e2e #7 API token forget + purge-revoked tidy the token list', function () {
    ['user' => $user, 'workspace' => $workspace] = reportingMember('admin');

    $active = WorkspaceApiToken::factory()->create(['workspace_id' => $workspace->id]);
    WorkspaceApiToken::factory()->revoked()->create(['workspace_id' => $workspace->id]);
    WorkspaceApiToken::factory()->revoked()->create(['workspace_id' => $workspace->id]);

    $this->actingAs($user)
        ->delete("/settings/api-tokens/{$active->id}/forget")
        ->assertRedirect();

    $this->actingAs($user)
        ->post('/settings/api-tokens/purge-revoked')
        ->assertRedirect();

    $this->actingAs($user)
        ->get('/settings/api-tokens')
        ->assertOk()
        ->assertInertia(fn ($p) => $p->where('tokens', []));

    expect(WorkspaceApiToken::query()->where('workspace_id', $workspace->id)->count())->toBe(0);
    expect(
        AuditLog::query()
            ->where('workspace_id', $workspace->id)
            ->whereIn('action', ['api_token.forgotten', 'api_token.purged'])
            ->count()
    )->toBeGreaterThanOrEqual(2);
});

test('e2e bundle: every customer issue plays back without a 500', function () {
    ['admin' => $admin] = reportingSuperAdmin();

    foreach ([
        '/admin/workspaces',
        '/admin/subscriptions',
        '/admin',
        '/settings/system?section=mail',
        '/admin/plans',
    ] as $url) {
        $this->actingAs($admin)
            ->get($url)
            ->assertStatus(200);
    }

    ['user' => $member] = reportingMember('admin');
    foreach ([
        '/app/analytics',
        '/app/integrations',
        '/app/audit',
        '/settings/api-tokens',
    ] as $url) {
        $this->actingAs($member)
            ->get($url)
            ->assertStatus(200);
    }
});

test('e2e #5 docs: WordPress plugin distribution page renders 200', function () {
    $this->get('/documentation/wordpress-plugin-distribution')
        ->assertStatus(200);
});
