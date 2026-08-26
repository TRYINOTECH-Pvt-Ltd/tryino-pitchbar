<?php

use App\Models\Agent;
use App\Models\Conversation;
use App\Models\CuratedAnswer;
use App\Models\IntegrationConnection;
use App\Models\Source;
use App\Models\Workspace;
use App\Support\CurrentWorkspace;

beforeEach(function () {
    // start each test with no workspace bound
    app(CurrentWorkspace::class)->clear();
});

it('scopes Agent queries to the current workspace', function () {
    $a = Workspace::factory()->create();
    $b = Workspace::factory()->create();

    Agent::factory()->create(['workspace_id' => $a->id]);
    Agent::factory()->create(['workspace_id' => $a->id]);
    Agent::factory()->create(['workspace_id' => $b->id]);

    app(CurrentWorkspace::class)->set($a);
    expect(Agent::query()->count())->toBe(2);

    app(CurrentWorkspace::class)->set($b);
    expect(Agent::query()->count())->toBe(1);
});

it('auto-fills workspace_id on create when one is bound', function () {
    $w = Workspace::factory()->create();
    app(CurrentWorkspace::class)->set($w);

    $agent = Agent::factory()->create([
        'workspace_id' => null,
    ]);

    expect($agent->fresh()->workspace_id)->toBe($w->id);
});

it('lets withoutWorkspaceScope() see all rows', function () {
    $a = Workspace::factory()->create();
    $b = Workspace::factory()->create();

    Agent::factory()->create(['workspace_id' => $a->id]);
    Agent::factory()->create(['workspace_id' => $b->id]);

    app(CurrentWorkspace::class)->set($a);
    expect(Agent::query()->count())->toBe(1);
    expect(Agent::query()->withoutWorkspaceScope()->count())->toBe(2);
});

it('scopes agent-bound models (sources, curated answers, chunks) by workspace via the agent', function () {
    $aWs = Workspace::factory()->create();
    $bWs = Workspace::factory()->create();

    $aAgent = Agent::factory()->create(['workspace_id' => $aWs->id]);
    $bAgent = Agent::factory()->create(['workspace_id' => $bWs->id]);

    Source::factory()->create(['agent_id' => $aAgent->id]);
    Source::factory()->create(['agent_id' => $bAgent->id]);
    CuratedAnswer::factory()->create(['agent_id' => $aAgent->id]);

    app(CurrentWorkspace::class)->set($aWs);
    expect(Source::query()->count())->toBe(1);
    expect(CuratedAnswer::query()->count())->toBe(1);

    app(CurrentWorkspace::class)->set($bWs);
    expect(Source::query()->count())->toBe(1);
    expect(CuratedAnswer::query()->count())->toBe(0);
});

it('keeps integration_connections scoped per workspace', function () {
    $a = Workspace::factory()->create();
    $b = Workspace::factory()->create();

    IntegrationConnection::factory()->create(['workspace_id' => $a->id]);
    IntegrationConnection::factory()->create(['workspace_id' => $b->id]);
    IntegrationConnection::factory()->create(['workspace_id' => $b->id]);

    app(CurrentWorkspace::class)->set($a);
    expect(IntegrationConnection::query()->count())->toBe(1);

    app(CurrentWorkspace::class)->set($b);
    expect(IntegrationConnection::query()->count())->toBe(2);
});

it('scopes Conversations by workspace through the agent', function () {
    $aWs = Workspace::factory()->create();
    $bWs = Workspace::factory()->create();

    $aAgent = Agent::factory()->create(['workspace_id' => $aWs->id]);
    $bAgent = Agent::factory()->create(['workspace_id' => $bWs->id]);

    Conversation::factory()->create(['agent_id' => $aAgent->id]);
    Conversation::factory()->create(['agent_id' => $aAgent->id]);
    Conversation::factory()->create(['agent_id' => $bAgent->id]);

    app(CurrentWorkspace::class)->set($aWs);
    expect(Conversation::query()->count())->toBe(2);

    app(CurrentWorkspace::class)->set($bWs);
    expect(Conversation::query()->count())->toBe(1);
});
