<?php

use App\Jobs\Conversations\BuildConversationExportJob;
use App\Models\Conversation;
use App\Models\ConversationExport;
use App\Models\Lead;
use App\Models\Message;
use App\Models\Visitor;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

function exportFixture(string $agentId): Conversation
{
    $visitor = Visitor::factory()->create(['agent_id' => $agentId]);
    $conversation = Conversation::factory()->create([
        'agent_id' => $agentId,
        'visitor_id' => $visitor->id,
        'page_url' => 'https://buyer.test/pricing',
        'started_at' => now()->subDay(),
    ]);
    Message::create([
        'conversation_id' => $conversation->id,
        'role' => 'user',
        'content' => 'How much is the pro plan?',
        'citations' => null,
    ]);
    Message::create([
        'conversation_id' => $conversation->id,
        'role' => 'assistant',
        'content' => 'The pro plan is $49/mo.',
        'citations' => null,
    ]);
    Lead::create([
        'conversation_id' => $conversation->id,
        'agent_id' => $agentId,
        'email' => 'lead@example.test',
        'name' => 'Pricing Lead',
        'status' => 'new',
        'fields' => [],
    ]);

    return $conversation;
}

test('admin can request a csv export', function () {
    ['user' => $user, 'agent' => $agent] = workspaceMemberWithAgent(['role' => 'admin']);
    exportFixture($agent->id);
    Queue::fake();

    $this->actingAs($user)
        ->postJson('/app/conversation-exports', ['format' => 'csv'])
        ->assertStatus(202)
        ->assertJsonPath('status', 'pending');

    Queue::assertPushed(BuildConversationExportJob::class);

    expect(ConversationExport::query()->withoutWorkspaceScope()->count())->toBe(1);
});

test('editor cannot request export', function () {
    ['user' => $user] = workspaceMemberWithAgent(['role' => 'editor']);

    $this->actingAs($user)
        ->postJson('/app/conversation-exports', ['format' => 'csv'])
        ->assertForbidden();
});

test('build job produces csv with expected columns', function () {
    ['user' => $user, 'workspace' => $workspace, 'agent' => $agent] = workspaceMemberWithAgent(['role' => 'admin']);
    exportFixture($agent->id);

    Storage::fake('local');

    $export = ConversationExport::create([
        'workspace_id' => $workspace->id,
        'requested_by_user_id' => $user->id,
        'format' => 'csv',
        'filters' => [],
        'status' => ConversationExport::STATUS_PENDING,
    ]);

    (new BuildConversationExportJob($export->id))->handle();

    $export->refresh();
    expect($export->status)->toBe(ConversationExport::STATUS_READY);
    expect($export->row_count)->toBe(1);
    expect(Storage::disk('local')->exists($export->file_path))->toBeTrue();

    $contents = Storage::disk('local')->get($export->file_path);
    expect($contents)->toContain('conversation_id');
    expect($contents)->toContain('lead@example.test');
    expect($contents)->toContain('How much is the pro plan?');
});

test('build job produces json with conversations array', function () {
    ['user' => $user, 'workspace' => $workspace, 'agent' => $agent] = workspaceMemberWithAgent(['role' => 'admin']);
    exportFixture($agent->id);
    Storage::fake('local');

    $export = ConversationExport::create([
        'workspace_id' => $workspace->id,
        'requested_by_user_id' => $user->id,
        'format' => 'json',
        'filters' => [],
        'status' => ConversationExport::STATUS_PENDING,
    ]);

    (new BuildConversationExportJob($export->id))->handle();

    $contents = Storage::disk('local')->get($export->fresh()->file_path);
    $decoded = json_decode($contents, true);
    expect($decoded['count'])->toBe(1);
    expect($decoded['conversations'][0]['lead_email'])->toBe('lead@example.test');
    expect($decoded['conversations'][0]['messages'])->toHaveCount(2);
});

test('build job is workspace-isolated', function () {
    ['workspace' => $workspace, 'agent' => $agent, 'user' => $user] = workspaceMemberWithAgent(['role' => 'admin']);
    $foreign = workspaceMemberWithAgent(['role' => 'admin']);
    exportFixture($agent->id);
    exportFixture($foreign['agent']->id);
    Storage::fake('local');

    $export = ConversationExport::create([
        'workspace_id' => $workspace->id,
        'requested_by_user_id' => $user->id,
        'format' => 'json',
        'filters' => [],
        'status' => ConversationExport::STATUS_PENDING,
    ]);

    (new BuildConversationExportJob($export->id))->handle();

    $decoded = json_decode(Storage::disk('local')->get($export->fresh()->file_path), true);
    expect($decoded['count'])->toBe(1);
});

test('download streams the file to admin', function () {
    ['user' => $user, 'workspace' => $workspace, 'agent' => $agent] = workspaceMemberWithAgent(['role' => 'admin']);
    exportFixture($agent->id);
    Storage::fake('local');

    $export = ConversationExport::create([
        'workspace_id' => $workspace->id,
        'requested_by_user_id' => $user->id,
        'format' => 'csv',
        'filters' => [],
        'status' => ConversationExport::STATUS_PENDING,
    ]);
    (new BuildConversationExportJob($export->id))->handle();

    $this->actingAs($user)
        ->get("/app/conversation-exports/{$export->id}/download")
        ->assertOk()
        ->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
});

test('download from another workspace 404s', function () {
    ['user' => $user] = workspaceMemberWithAgent(['role' => 'admin']);
    $foreign = workspaceMemberWithAgent(['role' => 'admin']);
    exportFixture($foreign['agent']->id);
    Storage::fake('local');

    $export = ConversationExport::create([
        'workspace_id' => $foreign['workspace']->id,
        'requested_by_user_id' => $foreign['user']->id,
        'format' => 'csv',
        'filters' => [],
        'status' => ConversationExport::STATUS_PENDING,
    ]);
    (new BuildConversationExportJob($export->id))->handle();

    $this->actingAs($user)
        ->get("/app/conversation-exports/{$export->id}/download")
        ->assertNotFound();
});

test('download of expired export returns 410', function () {
    ['user' => $user, 'workspace' => $workspace] = workspaceMemberWithAgent(['role' => 'admin']);
    Storage::fake('local');
    $export = ConversationExport::create([
        'workspace_id' => $workspace->id,
        'requested_by_user_id' => $user->id,
        'format' => 'csv',
        'filters' => [],
        'status' => ConversationExport::STATUS_READY,
        'file_path' => 'exports/x.csv',
        'expires_at' => now()->subDay(),
    ]);
    Storage::disk('local')->put('exports/x.csv', 'old');

    $this->actingAs($user)
        ->get("/app/conversation-exports/{$export->id}/download")
        ->assertStatus(410);
});

test('prune command deletes expired exports + files', function () {
    ['workspace' => $workspace, 'user' => $user] = workspaceMemberWithAgent(['role' => 'admin']);
    Storage::fake('local');
    Storage::disk('local')->put('exports/dead.csv', 'old');
    $dead = ConversationExport::create([
        'workspace_id' => $workspace->id,
        'requested_by_user_id' => $user->id,
        'format' => 'csv',
        'filters' => [],
        'status' => ConversationExport::STATUS_READY,
        'file_path' => 'exports/dead.csv',
        'expires_at' => now()->subDay(),
    ]);
    $alive = ConversationExport::create([
        'workspace_id' => $workspace->id,
        'requested_by_user_id' => $user->id,
        'format' => 'csv',
        'filters' => [],
        'status' => ConversationExport::STATUS_READY,
        'file_path' => 'exports/alive.csv',
        'expires_at' => now()->addDay(),
    ]);
    Storage::disk('local')->put('exports/alive.csv', 'fresh');

    $this->artisan('conversations:prune-exports')->assertOk();

    expect(ConversationExport::query()->withoutWorkspaceScope()->find($dead->id))->toBeNull();
    expect(ConversationExport::query()->withoutWorkspaceScope()->find($alive->id))->not->toBeNull();
    expect(Storage::disk('local')->exists('exports/dead.csv'))->toBeFalse();
    expect(Storage::disk('local')->exists('exports/alive.csv'))->toBeTrue();
});

test('index lists workspace exports', function () {
    ['user' => $user, 'workspace' => $workspace] = workspaceMemberWithAgent(['role' => 'admin']);
    $foreign = workspaceMemberWithAgent(['role' => 'admin']);

    ConversationExport::create([
        'workspace_id' => $workspace->id,
        'requested_by_user_id' => $user->id,
        'format' => 'csv',
        'filters' => [],
        'status' => ConversationExport::STATUS_PENDING,
    ]);
    ConversationExport::create([
        'workspace_id' => $foreign['workspace']->id,
        'requested_by_user_id' => $foreign['user']->id,
        'format' => 'csv',
        'filters' => [],
        'status' => ConversationExport::STATUS_PENDING,
    ]);

    $this->actingAs($user)
        ->getJson('/app/conversation-exports')
        ->assertOk()
        ->assertJsonPath('exports.0.status', 'pending')
        ->assertJsonCount(1, 'exports');
});
