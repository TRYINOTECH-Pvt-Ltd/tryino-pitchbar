<?php

use App\Enums\PlatformRole;
use App\Models\User;
use App\Services\Llm\Contracts\OpenAiClient;

function probeSuperAdmin(): User
{
    return User::factory()->create([
        'email' => 'admin-probe@example.com',
        'role' => PlatformRole::SuperAdmin,
    ]);
}

class ProbeStreamEmptyClient implements OpenAiClient
{
    public function streamChat(array $messages, array $opts = []): iterable
    {
        return;
        yield;
    }

    public function chatWithTools(array $messages, array $tools, array $opts = []): array
    {
        return ['content' => 'ok', 'finish_reason' => 'stop'];
    }

    public function embed(array $inputs): array
    {
        return [];
    }
}

class ProbeBothEmptyClient implements OpenAiClient
{
    public function streamChat(array $messages, array $opts = []): iterable
    {
        return;
        yield;
    }

    public function chatWithTools(array $messages, array $tools, array $opts = []): array
    {
        return ['content' => '', 'finish_reason' => 'stop'];
    }

    public function embed(array $inputs): array
    {
        return [];
    }
}

class ProbeStreamWorkingClient implements OpenAiClient
{
    public function streamChat(array $messages, array $opts = []): iterable
    {
        yield 'ok';
    }

    public function chatWithTools(array $messages, array $tools, array $opts = []): array
    {
        return ['content' => 'ok', 'finish_reason' => 'stop'];
    }

    public function embed(array $inputs): array
    {
        return [];
    }
}

test('testLlm falls back to non-streaming when stream returns zero tokens', function () {
    // Buyer report 2026-05-29: non-Llama Cloudflare models returned
    // zero streamed tokens. Pre-fix the probe ended with "LLM stream
    // returned no tokens." with no further hint. After the fix it
    // should retry via chatWithTools and surface a clear next step.
    $this->app->bind(OpenAiClient::class, fn () => new ProbeStreamEmptyClient);

    $admin = probeSuperAdmin();

    $response = $this->actingAs($admin)
        ->post('/settings/system/test/llm')
        ->assertOk();

    $payload = $response->json();
    expect($payload['ok'])->toBeFalse();
    expect($payload['message'])->toContain('Non-streaming worked');
    expect($payload['message'])->toContain('streaming-capable');
});

test('testLlm surfaces a clear error when BOTH streaming and non-streaming are empty', function () {
    $this->app->bind(OpenAiClient::class, fn () => new ProbeBothEmptyClient);

    $admin = probeSuperAdmin();

    $response = $this->actingAs($admin)
        ->post('/settings/system/test/llm')
        ->assertOk();

    $payload = $response->json();
    expect($payload['ok'])->toBeFalse();
    expect($payload['message'])->toContain('OpenAI-compatible chat endpoint');
    expect($payload['message'])->toContain('streaming-capable');
});

test('testLlm reports success when streaming works (no fallback path)', function () {
    $this->app->bind(OpenAiClient::class, fn () => new ProbeStreamWorkingClient);

    $admin = probeSuperAdmin();

    $response = $this->actingAs($admin)
        ->post('/settings/system/test/llm')
        ->assertOk();

    $payload = $response->json();
    expect($payload['ok'])->toBeTrue();
    expect($payload['message'])->toContain('LLM responded');
});
