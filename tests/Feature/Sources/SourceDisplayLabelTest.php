<?php

use App\Models\Agent;
use App\Models\Document;
use App\Models\Source;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceUser;

function asAdminFor(Agent $agent): User
{
    $user = User::factory()->create();
    WorkspaceUser::create([
        'workspace_id' => $agent->workspace_id,
        'user_id' => $user->id,
        'role' => 'admin',
        'invited_at' => now(),
        'accepted_at' => now(),
    ]);
    $user->forceFill(['default_workspace_id' => $agent->workspace_id])->save();

    return $user;
}

test('url source uses the URL as the display title', function () {
    $agent = Agent::factory()->create(['workspace_id' => Workspace::factory()->create()->id]);
    Source::create([
        'agent_id' => $agent->id,
        'type' => 'url',
        'status' => 'indexed',
        'config' => ['url' => 'https://shop.example.com/macbook'],
    ]);

    $response = $this->actingAs(asAdminFor($agent))->get("/app/agents/{$agent->id}/sources");

    $response->assertOk();
    $response->assertInertia(fn ($p) => $p
        ->where('sources.0.display.title', 'https://shop.example.com/macbook')
        ->where('sources.0.display.subtitle', 'url')
        ->where('sources.0.display.link', 'https://shop.example.com/macbook')
    );
});

test('notion source shows the indexed Document title, not the raw page id', function () {
    $agent = Agent::factory()->create(['workspace_id' => Workspace::factory()->create()->id]);
    $source = Source::create([
        'agent_id' => $agent->id,
        'type' => 'notion',
        'status' => 'indexed',
        'config' => ['notion_page_id' => 'abcd1234-5678-90ef-abcd-1234567890ef'],
    ]);
    Document::factory()->create([
        'source_id' => $source->id,
        'agent_id' => $agent->id,
        'url' => 'notion://abcd1234-5678-90ef-abcd-1234567890ef',
        'title' => 'Pricing FAQ',
    ]);

    $response = $this->actingAs(asAdminFor($agent))->get("/app/agents/{$agent->id}/sources");

    $response->assertOk();
    $response->assertInertia(fn ($p) => $p
        ->where('sources.0.display.title', 'Pricing FAQ')
        ->where('sources.0.display.subtitle', fn ($s) => str_starts_with($s, 'Notion'))
    );
});

test('google doc source shows the document title and a docs.google link', function () {
    $agent = Agent::factory()->create(['workspace_id' => Workspace::factory()->create()->id]);
    $source = Source::create([
        'agent_id' => $agent->id,
        'type' => 'google_doc',
        'status' => 'indexed',
        'config' => ['google_file_id' => '1AbCdEfGhIjKlMnOpQrStUvWxYz0123456789'],
    ]);
    Document::factory()->create([
        'source_id' => $source->id,
        'agent_id' => $agent->id,
        'url' => 'gdoc://1AbCdEfGhIjKlMnOpQrStUvWxYz0123456789',
        'title' => 'Onboarding Runbook',
    ]);

    $response = $this->actingAs(asAdminFor($agent))->get("/app/agents/{$agent->id}/sources");

    $response->assertOk();
    $response->assertInertia(fn ($p) => $p
        ->where('sources.0.display.title', 'Onboarding Runbook')
        ->where('sources.0.display.subtitle', 'Google Doc')
        ->where('sources.0.display.link', 'https://docs.google.com/document/d/1AbCdEfGhIjKlMnOpQrStUvWxYz0123456789/edit')
    );
});

test('notion source falls back to "Notion page" when no doc has been indexed yet', function () {
    $agent = Agent::factory()->create(['workspace_id' => Workspace::factory()->create()->id]);
    Source::create([
        'agent_id' => $agent->id,
        'type' => 'notion',
        'status' => 'pending',
        'config' => ['notion_page_id' => 'p1'],
    ]);

    $response = $this->actingAs(asAdminFor($agent))->get("/app/agents/{$agent->id}/sources");

    $response->assertOk();
    $response->assertInertia(fn ($p) => $p->where('sources.0.display.title', 'Notion page'));
});
