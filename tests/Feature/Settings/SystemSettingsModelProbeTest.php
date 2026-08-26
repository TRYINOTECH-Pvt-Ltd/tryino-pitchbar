<?php

use App\Enums\PlatformRole;
use App\Models\User;
use App\Services\Llm\Contracts\OpenAiClient;
use App\Services\Llm\Fakes\FakeOpenAi;
use App\Services\Llm\ModelLatencyProbe;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    Cache::flush();
});

function bindFake(): FakeOpenAi
{
    $fake = new FakeOpenAi;
    app()->instance(OpenAiClient::class, $fake);

    return $fake;
}

it('measures latency, caches, and returns ttft/total ms', function () {
    $fake = bindFake();
    $fake->setDefaultResponse('ok');
    $fake->setLatency(firstByteDelayMs: 20);

    $admin = User::factory()->create(['role' => PlatformRole::SuperAdmin]);

    $res = $this->actingAs($admin)->postJson('/settings/system/probe/llm-latency', [
        'provider' => 'openai',
        'model' => 'gpt-4o-mini',
        'force' => true,
    ]);

    $res->assertOk();
    expect($res->json('ok'))->toBeTrue()
        ->and($res->json('provider'))->toBe('openai')
        ->and($res->json('model'))->toBe('gpt-4o-mini')
        ->and($res->json('ttft_ms'))->toBeInt()->toBeGreaterThanOrEqual(0)
        ->and($res->json('total_ms'))->toBeInt()->toBeGreaterThanOrEqual(0)
        ->and($res->json('measured_at'))->toBeString();

    // Probe is cached.
    $cached = app(ModelLatencyProbe::class)->cached('openai', 'gpt-4o-mini');
    expect($cached)->toBeArray()
        ->and($cached['ok'])->toBeTrue();
});

it('records an error when the client throws', function () {
    $fake = bindFake();
    // No scripted response, but setDefaultResponse('') => empty stream
    // → ttft never recorded → ok=false. To simulate a hard error we
    // bind a different fake that throws.
    app()->instance(OpenAiClient::class, new class extends FakeOpenAi
    {
        public function streamChat(array $messages, array $opts = []): iterable
        {
            throw new RuntimeException('cloudflare down: 503 service unavailable');
            yield ''; // unreachable
        }
    });

    $admin = User::factory()->create(['role' => PlatformRole::SuperAdmin]);

    $res = $this->actingAs($admin)->postJson('/settings/system/probe/llm-latency', [
        'provider' => 'cloudflare',
        'model' => '@cf/meta/llama-3.3-70b-instruct-fp8-fast',
        'force' => true,
    ]);

    $res->assertOk();
    expect($res->json('ok'))->toBeFalse()
        ->and($res->json('error'))->toContain('cloudflare down')
        ->and($res->json('ttft_ms'))->toBeNull();
});

it('rejects non-super-admin callers', function () {
    bindFake();

    $user = User::factory()->create(['role' => PlatformRole::Customer]);

    $res = $this->actingAs($user)->postJson('/settings/system/probe/llm-latency', [
        'provider' => 'openai',
        'model' => 'gpt-4o-mini',
    ]);

    // super_admin middleware hides the route (404 existence-hide).
    expect($res->status())->toBeIn([403, 404]);
});

it('validates provider and model fields', function () {
    bindFake();

    $admin = User::factory()->create(['role' => PlatformRole::SuperAdmin]);

    $this->actingAs($admin)->postJson('/settings/system/probe/llm-latency', [
        'provider' => 'invalid-provider',
        'model' => 'x',
    ])->assertStatus(422);

    $this->actingAs($admin)->postJson('/settings/system/probe/llm-latency', [
        'provider' => 'openai',
    ])->assertStatus(422);
});

it('rate-limits to 10 probes per minute per user', function () {
    $fake = bindFake();
    $fake->setDefaultResponse('ok');

    $admin = User::factory()->create(['role' => PlatformRole::SuperAdmin]);

    for ($i = 0; $i < 10; $i++) {
        $this->actingAs($admin)->postJson('/settings/system/probe/llm-latency', [
            'provider' => 'openai',
            'model' => 'gpt-4o-mini',
            'force' => true,
        ])->assertOk();
    }

    $blocked = $this->actingAs($admin)->postJson('/settings/system/probe/llm-latency', [
        'provider' => 'openai',
        'model' => 'gpt-4o-mini',
        'force' => true,
    ]);
    expect($blocked->status())->toBe(429);
});
