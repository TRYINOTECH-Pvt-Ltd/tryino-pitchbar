<?php

use App\Models\Agent;
use App\Models\Chunk;
use App\Models\Document;
use App\Models\Source;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceUser;
use Illuminate\Support\Str;

function asAdminWithAgent(): array
{
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['owner_user_id' => $user->id]);
    WorkspaceUser::create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'role' => 'admin',
        'invited_at' => now(),
        'accepted_at' => now(),
    ]);
    $user->forceFill(['default_workspace_id' => $workspace->id])->save();

    $agent = Agent::factory()->create(['workspace_id' => $workspace->id]);

    return ['user' => $user, 'workspace' => $workspace, 'agent' => $agent];
}

test('preview endpoint returns documents and chunk preview text', function () {
    ['user' => $user, 'agent' => $agent] = asAdminWithAgent();
    $source = Source::factory()->create(['agent_id' => $agent->id, 'status' => 'indexed']);
    $doc = Document::factory()->create([
        'source_id' => $source->id,
        'agent_id' => $agent->id,
        'url' => 'https://shop.example.com/macbook',
        'title' => 'MacBook Air M5',
    ]);
    Chunk::create([
        'document_id' => $doc->id,
        'agent_id' => $agent->id,
        'ord' => 0,
        'text' => 'MacBook Air M5 13-inch. Price 148,000. EMI for 12 months.',
        'token_count' => 12,
        'qdrant_point_id' => (string) Str::uuid7(),
    ]);

    $response = $this->actingAs($user)->getJson("/app/sources/{$source->id}/preview");

    $response->assertOk();
    $response->assertJsonPath('data.source.id', $source->id);
    $response->assertJsonPath('data.documents.0.title', 'MacBook Air M5');
    $response->assertJsonPath('data.documents.0.chunks_count', 1);
    $response->assertJsonPath('data.documents.0.chunks.0.text', 'MacBook Air M5 13-inch. Price 148,000. EMI for 12 months.');
    $response->assertJsonPath('data.documents.0.chunks.0.ord', 0);
});

test('preview returns up to 20 chunks per document, ordered by ord', function () {
    ['user' => $user, 'agent' => $agent] = asAdminWithAgent();
    $source = Source::factory()->create(['agent_id' => $agent->id, 'status' => 'indexed']);
    $doc = Document::factory()->create([
        'source_id' => $source->id,
        'agent_id' => $agent->id,
        'url' => 'https://shop.example.com/macbook',
        'title' => 'MacBook Air M5',
    ]);

    // Insert in reversed order to confirm we sort by ord, not insertion time.
    foreach (array_reverse(range(0, 24)) as $i) {
        Chunk::create([
            'document_id' => $doc->id,
            'agent_id' => $agent->id,
            'ord' => $i,
            'text' => "chunk #{$i}",
            'token_count' => 4,
            'qdrant_point_id' => (string) Str::uuid7(),
        ]);
    }

    $response = $this->actingAs($user)->getJson("/app/sources/{$source->id}/preview");

    $response->assertOk();
    $response->assertJsonPath('data.documents.0.chunks_count', 25);
    $response->assertJsonCount(20, 'data.documents.0.chunks');
    $response->assertJsonPath('data.documents.0.chunks.0.ord', 0);
    $response->assertJsonPath('data.documents.0.chunks.19.ord', 19);
});

test('preview reports progress (pages_indexed / pages_total)', function () {
    ['user' => $user, 'agent' => $agent] = asAdminWithAgent();
    $source = Source::factory()->create([
        'agent_id' => $agent->id,
        'type' => 'sitemap',
        'status' => 'indexed',
    ]);

    foreach (['/a', '/b', '/c'] as $path) {
        Document::factory()->create([
            'source_id' => $source->id,
            'agent_id' => $agent->id,
            'url' => "https://shop.example.com{$path}",
        ]);
    }

    $response = $this->actingAs($user)->getJson("/app/sources/{$source->id}/preview");

    $response->assertOk();
    $response->assertJsonPath('data.progress.pages_indexed', 3);
    // pages_total mirrors `services.crawl.max_pages_per_source`. The
    // default was bumped from 25 → 500 so a 100-URL sitemap stops
    // silently losing 75 pages. Reading the config keeps this test
    // honest if the default moves again.
    $response->assertJsonPath(
        'data.progress.pages_total',
        (int) config('services.crawl.max_pages_per_source'),
    );
});

test('preview exposes the true per-chunk char length and caps displayed text at 4000', function () {
    // A buyer mistook the display cap for lost content. The endpoint must
    // report the REAL chunk length (`chars`) so the UI can flag a trim,
    // while the `text` it ships stays bounded for payload size.
    ['user' => $user, 'agent' => $agent] = asAdminWithAgent();
    $source = Source::factory()->create(['agent_id' => $agent->id, 'status' => 'indexed']);
    $doc = Document::factory()->create([
        'source_id' => $source->id,
        'agent_id' => $agent->id,
        'url' => 'https://shop.example.com/long',
        'title' => 'Long doc',
    ]);
    Chunk::create([
        'document_id' => $doc->id,
        'agent_id' => $agent->id,
        'ord' => 0,
        'text' => str_repeat('A', 5000),
        'token_count' => 1250,
        'qdrant_point_id' => (string) Str::uuid7(),
    ]);

    $response = $this->actingAs($user)->getJson("/app/sources/{$source->id}/preview");

    $response->assertOk();
    $chunk = $response->json('data.documents.0.chunks.0');
    expect(mb_strlen($chunk['text']))->toBe(4000)
        ->and($chunk['chars'])->toBe(5000);
});

test('preview enforces per-source authorisation', function () {
    ['user' => $user] = asAdminWithAgent();
    $otherWs = Workspace::factory()->create();
    $otherAgent = Agent::factory()->create(['workspace_id' => $otherWs->id]);
    $otherSource = Source::factory()->create(['agent_id' => $otherAgent->id]);

    $this->actingAs($user)
        ->getJson("/app/sources/{$otherSource->id}/preview")
        ->assertForbidden();
});
