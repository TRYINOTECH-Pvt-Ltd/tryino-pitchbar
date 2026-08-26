<?php

use App\Models\Agent;
use App\Models\Workspace;

test('init payload exposes agent.locale + agent.copy from the widget dictionary', function () {
    $workspace = Workspace::factory()->create();
    $agent = Agent::factory()->published()->create([
        'workspace_id' => $workspace->id,
        'allowed_origins' => ['https://example.com'],
        'language_default' => 'es',
    ]);

    $response = $this->withHeaders(['Origin' => 'https://example.com'])
        ->postJson('/api/v1/widget/init', ['agent_id' => $agent->id]);

    $response->assertOk()
        ->assertJsonPath('data.agent.locale', 'es')
        ->assertJsonPath('data.agent.copy.Send', 'Enviar')
        ->assertJsonPath('data.agent.copy.Close', 'Cerrar')
        ->assertJsonPath('data.agent.copy.Connect to a human', 'Conectar con una persona');
});

test('init falls back to Accept-Language when agent locale is unsupported', function () {
    $workspace = Workspace::factory()->create();
    $agent = Agent::factory()->published()->create([
        'workspace_id' => $workspace->id,
        'allowed_origins' => ['https://example.com'],
        'language_default' => 'zz',
    ]);

    $response = $this->withHeaders([
        'Origin' => 'https://example.com',
        'Accept-Language' => 'fr-FR,fr;q=0.9',
    ])->postJson('/api/v1/widget/init', ['agent_id' => $agent->id]);

    $response->assertOk()
        ->assertJsonPath('data.agent.locale', 'fr')
        ->assertJsonPath('data.agent.copy.Send', 'Envoyer');
});

test('init defaults to en when nothing matches', function () {
    $workspace = Workspace::factory()->create();
    $agent = Agent::factory()->published()->create([
        'workspace_id' => $workspace->id,
        'allowed_origins' => ['https://example.com'],
        'language_default' => 'en',
    ]);

    $response = $this->withHeaders(['Origin' => 'https://example.com'])
        ->postJson('/api/v1/widget/init', ['agent_id' => $agent->id]);

    $response->assertOk()
        ->assertJsonPath('data.agent.locale', 'en')
        ->assertJsonPath('data.agent.copy.Send', 'Send');
});

test('every key in WidgetCopy::KEYS resolves to a non-empty string', function () {
    $workspace = Workspace::factory()->create();
    $agent = Agent::factory()->published()->create([
        'workspace_id' => $workspace->id,
        'allowed_origins' => ['https://example.com'],
        'language_default' => 'tr',
    ]);

    $response = $this->withHeaders(['Origin' => 'https://example.com'])
        ->postJson('/api/v1/widget/init', ['agent_id' => $agent->id]);

    $copy = $response->json('data.agent.copy');
    expect($copy)->toBeArray()->not->toBeEmpty();
    foreach ($copy as $key => $value) {
        expect($value)->toBeString()->not->toBeEmpty(
            "Empty translation for key: {$key}"
        );
    }
});
