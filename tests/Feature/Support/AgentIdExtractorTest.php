<?php

use App\Models\Agent;
use App\Models\Conversation;
use App\Models\Document;
use App\Models\Source;
use App\Models\Visitor;
use App\Models\Workspace;
use App\Support\AgentIdExtractor;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Build a serialized-command-like string that has the property the
 * extractor looks for. Avoids the cost + side-effects of actually
 * `serialize()`-ing a real job (which pulls models, hits the DB during
 * sleep/wakeup, etc.).
 */
function fakeCmd(string $property, string $value): string
{
    return sprintf(
        'O:30:"App\Jobs\Fake":1:{s:%d:"%s";s:%d:"%s";}',
        strlen($property),
        $property,
        strlen($value),
        $value,
    );
}

test('extracts a direct agentId', function () {
    $workspace = Workspace::factory()->create();
    $agent = Agent::factory()->create(['workspace_id' => $workspace->id]);

    expect(AgentIdExtractor::fromCommand(fakeCmd('agentId', (string) $agent->id)))
        ->toBe((string) $agent->id);
});

test('walks documentId -> document.agent_id', function () {
    $workspace = Workspace::factory()->create();
    $agent = Agent::factory()->create(['workspace_id' => $workspace->id]);
    $source = Source::factory()->create(['agent_id' => $agent->id]);
    $document = Document::factory()->create(['agent_id' => $agent->id, 'source_id' => $source->id]);

    expect(AgentIdExtractor::fromCommand(fakeCmd('documentId', (string) $document->id)))
        ->toBe((string) $agent->id);
});

test('walks conversationId -> conversation.agent_id', function () {
    $workspace = Workspace::factory()->create();
    $agent = Agent::factory()->create(['workspace_id' => $workspace->id]);
    $visitor = Visitor::factory()->create(['agent_id' => $agent->id]);
    $conv = Conversation::factory()->create([
        'agent_id' => $agent->id,
        'visitor_id' => $visitor->id,
    ]);

    expect(AgentIdExtractor::fromCommand(fakeCmd('conversationId', (string) $conv->id)))
        ->toBe((string) $agent->id);
});

test('walks sourceId -> source.agent_id', function () {
    $workspace = Workspace::factory()->create();
    $agent = Agent::factory()->create(['workspace_id' => $workspace->id]);
    $source = Source::factory()->create(['agent_id' => $agent->id]);

    expect(AgentIdExtractor::fromCommand(fakeCmd('sourceId', (string) $source->id)))
        ->toBe((string) $agent->id);
});

test('returns null when no recognised id is present', function () {
    expect(AgentIdExtractor::fromCommand('O:1:"X":0:{}'))->toBeNull();
});

test('returns null for an empty payload', function () {
    expect(AgentIdExtractor::fromCommand(''))->toBeNull();
});

test('returns null when documentId points to a non-existent row', function () {
    expect(AgentIdExtractor::fromCommand(fakeCmd('documentId', '019e0000-aaaa-bbbb-cccc-000000000000')))
        ->toBeNull();
});
