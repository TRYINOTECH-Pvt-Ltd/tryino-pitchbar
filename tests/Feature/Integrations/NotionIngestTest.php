<?php

use App\Jobs\Crawl\IndexDocumentJob;
use App\Jobs\Crawl\IngestNotionPageJob;
use App\Models\Agent;
use App\Models\Document;
use App\Models\IntegrationConnection;
use App\Models\Source;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceUser;
use App\Services\Integrations\Notion\NotionBlockExtractor;
use App\Services\Integrations\Notion\NotionClient;
use Illuminate\Support\Facades\Bus;

function notionWorkspace(bool $connect = true): array
{
    $user = User::factory()->create();
    $ws = Workspace::factory()->create(['owner_user_id' => $user->id]);
    WorkspaceUser::create([
        'workspace_id' => $ws->id,
        'user_id' => $user->id,
        'role' => 'admin',
        'invited_at' => now(),
        'accepted_at' => now(),
    ]);
    $user->forceFill(['default_workspace_id' => $ws->id])->save();
    $agent = Agent::factory()->create(['workspace_id' => $ws->id]);

    if ($connect) {
        IntegrationConnection::create([
            'workspace_id' => $ws->id,
            'kind' => 'notion',
            'credentials_encrypted' => ['access_token' => 'secret_xyz'],
            'status' => 'active',
        ]);
    }

    return ['user' => $user, 'workspace' => $ws, 'agent' => $agent];
}

test('storeNotion accepts a page URL, normalises the id, and dispatches the job', function () {
    Bus::fake();
    ['user' => $user, 'agent' => $agent] = notionWorkspace();

    // Notion page URLs end in a 32-char hex id.
    $url = 'https://www.notion.so/My-Page-abcdef1234567890abcdef1234567890';

    $this->actingAs($user)
        ->post("/app/agents/{$agent->id}/sources/notion", ['page' => $url])
        ->assertRedirect();

    $source = Source::query()->withoutGlobalScopes()
        ->where('agent_id', $agent->id)
        ->where('type', 'notion')
        ->first();

    expect($source)->not->toBeNull();
    expect($source->config['notion_page_id'])->toBe('abcdef12-3456-7890-abcd-ef1234567890');
    Bus::assertDispatched(IngestNotionPageJob::class, fn ($job) => $job->sourceId === $source->id);
});

test('storeNotion fails clearly if Notion is not connected', function () {
    Bus::fake();
    ['user' => $user, 'agent' => $agent] = notionWorkspace(connect: false);

    $response = $this->actingAs($user)
        ->from("/app/agents/{$agent->id}/sources")
        ->post("/app/agents/{$agent->id}/sources/notion", ['page' => 'abcdef1234567890abcdef1234567890']);

    $response->assertRedirect("/app/agents/{$agent->id}/sources");
    $response->assertSessionHasErrors('page');
    Bus::assertNotDispatched(IngestNotionPageJob::class);
});

test('storeNotion rejects unparseable input', function () {
    Bus::fake();
    ['user' => $user, 'agent' => $agent] = notionWorkspace();

    $this->actingAs($user)
        ->from("/app/agents/{$agent->id}/sources")
        ->post("/app/agents/{$agent->id}/sources/notion", ['page' => 'not-a-page'])
        ->assertSessionHasErrors('page');

    Bus::assertNotDispatched(IngestNotionPageJob::class);
});

test('IngestNotionPageJob fetches blocks, creates Document, dispatches IndexDocumentJob', function () {
    Bus::fake([IndexDocumentJob::class]);
    ['agent' => $agent] = notionWorkspace();

    $source = Source::create([
        'agent_id' => $agent->id,
        'type' => 'notion',
        'status' => 'pending',
        'config' => ['notion_page_id' => 'abcdef12-3456-7890-abcd-ef1234567890'],
    ]);

    $client = Mockery::mock(NotionClient::class);
    $client->shouldReceive('getPage')->once()
        ->andReturn(['title' => 'Pricing FAQ', 'url' => 'https://notion.so/x', 'last_edited_time' => '2026-05-01']);
    $client->shouldReceive('getBlocks')->once()
        ->andReturn([
            ['type' => 'paragraph', 'paragraph' => ['rich_text' => [['plain_text' => str_repeat('Pricing details. ', 20)]]]],
            ['type' => 'heading_1', 'heading_1' => ['rich_text' => [['plain_text' => 'Plans']]]],
            ['type' => 'paragraph', 'paragraph' => ['rich_text' => [['plain_text' => str_repeat('Standard $49/mo. Pro $249/mo. ', 10)]]]],
        ]);

    (new IngestNotionPageJob($source->id))->handle($client, app(NotionBlockExtractor::class));

    $source->refresh();
    expect($source->status)->toBe('indexed');
    expect($source->error)->toBeNull();
    $doc = Document::query()->withoutGlobalScopes()->where('source_id', $source->id)->first();
    expect($doc)->not->toBeNull();
    expect($doc->title)->toBe('Pricing FAQ');
    Bus::assertDispatched(IndexDocumentJob::class);
});

test('IngestNotionPageJob fails if connection is missing', function () {
    ['agent' => $agent] = notionWorkspace(connect: false);

    $source = Source::create([
        'agent_id' => $agent->id,
        'type' => 'notion',
        'status' => 'pending',
        'config' => ['notion_page_id' => 'abcdef12-3456-7890-abcd-ef1234567890'],
    ]);

    (new IngestNotionPageJob($source->id))->handle(
        app(NotionClient::class),
        app(NotionBlockExtractor::class),
    );

    $source->refresh();
    expect($source->status)->toBe('failed');
    expect($source->error)->toContain('Notion is not connected');
});
