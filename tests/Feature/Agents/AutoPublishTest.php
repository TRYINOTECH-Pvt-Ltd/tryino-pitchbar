<?php

use App\Models\Agent;
use App\Models\Source;
use App\Models\Workspace;

test('agent auto-publishes when its first source flips to indexed', function () {
    $workspace = Workspace::factory()->create();
    $agent = Agent::factory()->create([
        'workspace_id' => $workspace->id,
        'is_published' => false,
    ]);
    $source = Source::factory()->create([
        'agent_id' => $agent->id,
        'status' => 'crawling',
    ]);

    expect($agent->fresh()->is_published)->toBeFalse();

    $source->update(['status' => 'indexed']);

    expect($agent->fresh()->is_published)->toBeTrue();
    expect($agent->fresh()->published_version_id)->not->toBeNull();
});

test('does not re-publish when a second source becomes indexed', function () {
    $workspace = Workspace::factory()->create();
    $agent = Agent::factory()->create([
        'workspace_id' => $workspace->id,
        'is_published' => false,
    ]);

    $first = Source::factory()->create(['agent_id' => $agent->id, 'status' => 'crawling']);
    $first->update(['status' => 'indexed']);

    $firstVersionId = $agent->fresh()->published_version_id;
    expect($firstVersionId)->not->toBeNull();

    $second = Source::factory()->create(['agent_id' => $agent->id, 'status' => 'crawling']);
    $second->update(['status' => 'indexed']);

    // Same published_version_id — listener didn't re-snapshot
    expect($agent->fresh()->published_version_id)->toBe($firstVersionId);
});

test('does not auto-publish if agent was already manually published', function () {
    $workspace = Workspace::factory()->create();
    $agent = Agent::factory()->published()->create(['workspace_id' => $workspace->id]);
    $source = Source::factory()->create(['agent_id' => $agent->id, 'status' => 'crawling']);

    // Manual publish would have created a version; track it
    $beforeVersionCount = $agent->versions()->count();

    $source->update(['status' => 'indexed']);

    // No new version snapshotted by the listener
    expect($agent->versions()->count())->toBe($beforeVersionCount);
});
