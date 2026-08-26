<?php

use App\Models\Agent;
use App\Models\Document;
use App\Models\Source;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

function knowledgeFixture(): array
{
    ['user' => $user, 'workspace' => $ws] = workspaceMember(['role' => 'owner']);
    $agent = Agent::factory()->create(['workspace_id' => $ws->id]);
    $source = Source::factory()->create(['agent_id' => $agent->id, 'type' => 'url', 'status' => 'indexed']);

    return ['user' => $user, 'workspace' => $ws, 'agent' => $agent, 'source' => $source];
}

function makeIndexedDocument(string $agentId, string $sourceId, string $url, string $title, string $text): Document
{
    $doc = Document::create([
        'source_id' => $sourceId,
        'agent_id' => $agentId,
        'url' => $url,
        'title' => $title,
        'content_hash' => hash('sha256', $text),
        'fetched_at' => now(),
    ]);
    DB::table('chunks')->insert([
        'id' => (string) Str::uuid7(),
        'document_id' => $doc->id,
        'agent_id' => $agentId,
        'ord' => 0,
        'text' => $text,
        'token_count' => (int) ceil(mb_strlen($text) / 4),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $doc;
}

test('owner can view knowledge page with totals + documents', function () {
    ['user' => $user, 'agent' => $agent, 'source' => $source] = knowledgeFixture();
    makeIndexedDocument($agent->id, $source->id, 'https://shop.com/products/macbook', 'MacBook M5', 'Apple MacBook Air with M5 chip. 18 hours battery.');
    makeIndexedDocument($agent->id, $source->id, 'https://shop.com/about', 'About TechShop', 'TechShop sells quality electronics since 2015.');

    $this->actingAs($user)
        ->get("/app/agents/{$agent->id}/knowledge")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('app/agents/knowledge')
            ->where('totals.documents', 2)
            ->where('totals.chunks', 2)
            ->has('totals.characters')
            ->has('documents.data', 2)
            ->where('documents.data', fn ($docs) => collect($docs)->pluck('title')->sort()->values()->all() === ['About TechShop', 'MacBook M5']),
        );
});

test('search filters documents by URL or title', function () {
    ['user' => $user, 'agent' => $agent, 'source' => $source] = knowledgeFixture();
    makeIndexedDocument($agent->id, $source->id, 'https://shop.com/products/macbook', 'MacBook M5', 'macbook content');
    makeIndexedDocument($agent->id, $source->id, 'https://shop.com/about', 'About', 'about content');

    $this->actingAs($user)
        ->get("/app/agents/{$agent->id}/knowledge?q=macbook")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('documents.data', fn ($docs) => count($docs) === 1
                && $docs[0]['title'] === 'MacBook M5')
            ->where('q', 'macbook'),
        );
});

test('document chunks are returned with the human-readable text', function () {
    ['user' => $user, 'agent' => $agent, 'source' => $source] = knowledgeFixture();
    makeIndexedDocument(
        $agent->id,
        $source->id,
        'https://shop.com/p/1',
        'P1',
        'Apple MacBook Air with M5 chip ships in space gray. $999.',
    );

    $this->actingAs($user)
        ->get("/app/agents/{$agent->id}/knowledge")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('documents.data.0.preview', fn ($p) => str_contains($p, 'MacBook Air'))
            ->where('documents.data.0.chunks.0.text', fn ($t) => str_contains($t, '$999'))
            ->where('documents.data.0.chunks.0.tokens', fn ($n) => $n > 0),
        );
});

test('source label maps types to friendly names', function () {
    ['user' => $user, 'agent' => $agent] = knowledgeFixture();

    $autoSource = Source::factory()->create(['agent_id' => $agent->id, 'type' => 'auto']);
    makeIndexedDocument($agent->id, $autoSource->id, 'https://shop.com/auto', 'Auto-page', 'auto content');

    $this->actingAs($user)
        ->get("/app/agents/{$agent->id}/knowledge")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('documents.data.0.source_label', 'Auto-indexed (visitor visit)'),
        );
});

test('cross-workspace knowledge access is forbidden', function () {
    // First user — owns one workspace.
    ['user' => $user] = workspaceMember(['role' => 'owner']);
    // A different workspace with its own agent — current user is NOT a member.
    $otherBag = workspaceMember(['role' => 'owner']);
    $otherAgent = Agent::factory()->create(['workspace_id' => $otherBag['workspace']->id]);

    $this->actingAs($user)
        ->get("/app/agents/{$otherAgent->id}/knowledge")
        ->assertForbidden();
});

test('crawler engine column lights up the right friendly label', function () {
    ['user' => $user, 'agent' => $agent, 'source' => $source] = knowledgeFixture();
    $cf = makeIndexedDocument($agent->id, $source->id, 'https://shop.com/cf', 'CF page', 'crawled by Cloudflare');
    $cf->forceFill(['crawler' => 'CloudflareBrowserClient'])->save();
    $plain = makeIndexedDocument($agent->id, $source->id, 'https://shop.com/plain', 'Plain page', 'crawled plain http');
    $plain->forceFill(['crawler' => 'PlainHttpCrawler'])->save();

    $this->actingAs($user)
        ->get("/app/agents/{$agent->id}/knowledge")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('documents.data', function ($docs) {
                $byTitle = collect($docs)->keyBy('title');
                expect($byTitle['CF page']['crawler'])->toBe('Cloudflare Browser');
                expect($byTitle['Plain page']['crawler'])->toBe('Plain HTTP');

                return true;
            }),
        );
});

test('non-crawled documents (pasted text, Notion, etc.) report a null crawler', function () {
    ['user' => $user, 'agent' => $agent] = knowledgeFixture();
    $textSource = Source::factory()->create(['agent_id' => $agent->id, 'type' => 'text']);
    // Pasted text sources have no source URL, but the documents table
    // requires a value — store an empty string to mirror production.
    makeIndexedDocument($agent->id, $textSource->id, '', 'Pasted', 'plain pasted text');

    $this->actingAs($user)
        ->get("/app/agents/{$agent->id}/knowledge")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('documents.data.0.crawler', null));
});

test('viewer can read but admin and editor can view too', function () {
    ['user' => $user, 'workspace' => $ws] = workspaceMember(['role' => 'viewer']);
    $agent = Agent::factory()->create(['workspace_id' => $ws->id]);

    $this->actingAs($user)
        ->get("/app/agents/{$agent->id}/knowledge")
        ->assertOk();
});
