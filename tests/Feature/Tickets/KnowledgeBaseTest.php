<?php

use App\Models\Agent;
use App\Models\CuratedAnswer;
use App\Models\Workspace;
use App\Services\Tools\Tools\SendKbArticleTool;

function kbWorkspace(): Workspace
{
    return Workspace::factory()->create(['slug' => 'acme-co']);
}

function kbAgent(Workspace $w): Agent
{
    return Agent::factory()->create([
        'workspace_id' => $w->id,
        'is_published' => true,
    ]);
}

test('CuratedAnswer auto-derives a slug on save when blank', function () {
    $workspace = kbWorkspace();
    $agent = kbAgent($workspace);

    $answer = CuratedAnswer::query()->withoutWorkspaceScope()->create([
        'agent_id' => $agent->id,
        'question_pattern' => 'How do I cancel my subscription?',
        'answer' => 'Open Settings → Billing → Cancel.',
        'enabled' => true,
    ]);

    expect($answer->slug)->toBe('how-do-i-cancel-my-subscription');
});

test('CuratedAnswer slug stays unique per agent on collision', function () {
    $workspace = kbWorkspace();
    $agent = kbAgent($workspace);

    $a = CuratedAnswer::query()->withoutWorkspaceScope()->create([
        'agent_id' => $agent->id,
        'question_pattern' => 'Refund policy',
        'answer' => 'A.',
        'enabled' => true,
    ]);
    $b = CuratedAnswer::query()->withoutWorkspaceScope()->create([
        'agent_id' => $agent->id,
        'question_pattern' => 'Refund policy',
        'answer' => 'B.',
        'enabled' => true,
    ]);

    expect($a->slug)->toBe('refund-policy');
    expect($b->slug)->toBe('refund-policy-2');
});

test('GET /kb/{workspace} lists only published articles for that workspace', function () {
    $workspace = kbWorkspace();
    $agent = kbAgent($workspace);

    CuratedAnswer::query()->withoutWorkspaceScope()->create([
        'agent_id' => $agent->id,
        'question_pattern' => 'How to reset password',
        'answer' => 'Click forgot password.',
        'enabled' => true,
        'kb_published' => true,
    ]);
    CuratedAnswer::query()->withoutWorkspaceScope()->create([
        'agent_id' => $agent->id,
        'question_pattern' => 'Draft article',
        'answer' => 'Not ready.',
        'enabled' => true,
        'kb_published' => false,
    ]);

    $response = $this->get('/kb/acme-co');
    $response->assertOk();
    $response->assertSee('How to reset password');
    $response->assertDontSee('Draft article');
});

test('GET /kb/{workspace}/{slug} renders the article markdown', function () {
    $workspace = kbWorkspace();
    $agent = kbAgent($workspace);

    CuratedAnswer::query()->withoutWorkspaceScope()->create([
        'agent_id' => $agent->id,
        'question_pattern' => 'How to enable BYOK',
        'kb_title' => 'Enable BYOK for your workspace',
        'answer' => "## Steps\n\n1. Open Settings\n2. Toggle BYOK",
        'enabled' => true,
        'kb_published' => true,
    ]);

    $response = $this->get('/kb/acme-co/how-to-enable-byok');
    $response->assertOk();
    $response->assertSee('Enable BYOK for your workspace');
    $response->assertSee('<li>Open Settings', false);
});

test('GET /kb/{workspace}/{slug} 404s for unpublished articles', function () {
    $workspace = kbWorkspace();
    $agent = kbAgent($workspace);

    CuratedAnswer::query()->withoutWorkspaceScope()->create([
        'agent_id' => $agent->id,
        'question_pattern' => 'Secret internal note',
        'answer' => 'Internal only.',
        'enabled' => true,
        'kb_published' => false,
    ]);

    $this->get('/kb/acme-co/secret-internal-note')->assertNotFound();
});

test('GET /kb/{workspace} 404s for an unknown workspace slug', function () {
    $this->get('/kb/does-not-exist')->assertNotFound();
});

test('SendKbArticleTool returns title + excerpt + url for a published article', function () {
    $workspace = kbWorkspace();
    $agent = kbAgent($workspace);

    CuratedAnswer::query()->withoutWorkspaceScope()->create([
        'agent_id' => $agent->id,
        'question_pattern' => 'How to install',
        'answer' => 'Run composer install and npm install. Then migrate.',
        'enabled' => true,
        'kb_published' => true,
    ]);

    $tool = new SendKbArticleTool;
    $out = $tool->execute(['slug' => 'how-to-install'], $agent);

    expect($out['result']['success'])->toBeTrue();
    expect($out['result']['title'])->toBe('How to install');
    expect($out['result']['url'])->toBe('/kb/acme-co/how-to-install');
    expect($out['block']['type'])->toBe('kb_article');
});

test('SendKbArticleTool refuses an unpublished article', function () {
    $workspace = kbWorkspace();
    $agent = kbAgent($workspace);

    CuratedAnswer::query()->withoutWorkspaceScope()->create([
        'agent_id' => $agent->id,
        'question_pattern' => 'Internal note',
        'answer' => 'Hidden.',
        'enabled' => true,
        'kb_published' => false,
    ]);

    $tool = new SendKbArticleTool;
    $out = $tool->execute(['slug' => 'internal-note'], $agent);

    expect($out['result']['success'])->toBeFalse();
    expect($out['result']['error'])->toBe('article not found or not published');
});

test('SendKbArticleTool refuses an article from another workspace (tenancy guard)', function () {
    $alpha = kbWorkspace();
    $alphaAgent = kbAgent($alpha);

    $bravo = Workspace::factory()->create(['slug' => 'bravo-co']);
    $bravoAgent = kbAgent($bravo);

    CuratedAnswer::query()->withoutWorkspaceScope()->create([
        'agent_id' => $bravoAgent->id,
        'question_pattern' => 'Bravo secret',
        'answer' => 'Bravo workspace only.',
        'enabled' => true,
        'kb_published' => true,
    ]);

    $tool = new SendKbArticleTool;
    $out = $tool->execute(['slug' => 'bravo-secret'], $alphaAgent);

    expect($out['result']['success'])->toBeFalse();
});

test('SendKbArticleTool finds workspace-sibling articles published on a different agent', function () {
    // Operator publishes the KB on a single "support" agent and wants
    // every sibling agent in the workspace to be able to cite it.
    $workspace = kbWorkspace();
    $supportAgent = kbAgent($workspace);
    $salesAgent = kbAgent($workspace);

    CuratedAnswer::query()->withoutWorkspaceScope()->create([
        'agent_id' => $supportAgent->id,
        'question_pattern' => 'Refund policy',
        'answer' => 'Refund within 30 days.',
        'enabled' => true,
        'kb_published' => true,
    ]);

    $tool = new SendKbArticleTool;
    // Call the tool with the SALES agent — the article lives on the
    // support agent. The workspace-wide fallback must still find it.
    $out = $tool->execute(['slug' => 'refund-policy'], $salesAgent);

    expect($out['result']['success'])->toBeTrue();
    expect($out['result']['title'])->toBe('Refund policy');
});
