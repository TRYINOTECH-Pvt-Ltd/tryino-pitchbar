<?php

use App\Models\Agent;
use App\Models\Conversation;
use App\Models\Workspace;

function publishedAgentLang(string $lang = 'en'): Agent
{
    $ws = Workspace::factory()->create();

    return Agent::factory()->published()->create([
        'workspace_id' => $ws->id,
        'language_default' => $lang,
        'allowed_origins' => ['*'],
    ]);
}

test('init persists the visitor preferred language onto the conversation', function () {
    $agent = publishedAgentLang('en');

    $response = $this->postJson('/api/v1/widget/init', [
        'agent_id' => $agent->id,
        'page_url' => 'https://shop.example.com/macbook',
    ], [
        'Accept-Language' => 'fr-FR,fr;q=0.9,en;q=0.5',
        'Origin' => 'https://shop.example.com',
    ]);

    $response->assertOk();
    $cid = $response->json('data.conversation_id');
    expect($cid)->not->toBeNull();

    $conversation = Conversation::query()->withoutGlobalScopes()->find($cid);
    expect($conversation->lang)->toBe('fr');
});

test('init falls back to the agent default when Accept-Language is absent', function () {
    $agent = publishedAgentLang('es');

    // Symfony Request returns null for an unset header.
    $response = $this->postJson('/api/v1/widget/init', [
        'agent_id' => $agent->id,
    ], [
        'Origin' => 'https://shop.example.com',
        'Accept-Language' => '',
    ]);

    $response->assertOk();
    $cid = $response->json('data.conversation_id');
    $conversation = Conversation::query()->withoutGlobalScopes()->find($cid);
    expect($conversation->lang)->toBe('es');
});

test('init falls back to the agent default when only unsupported langs are sent', function () {
    $agent = publishedAgentLang('en');

    $response = $this->postJson('/api/v1/widget/init', [
        'agent_id' => $agent->id,
    ], [
        'Accept-Language' => 'tlh,eo;q=0.5',
        'Origin' => 'https://shop.example.com',
    ]);

    $response->assertOk();
    $cid = $response->json('data.conversation_id');
    $conversation = Conversation::query()->withoutGlobalScopes()->find($cid);
    expect($conversation->lang)->toBe('en');
});
