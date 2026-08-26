<?php

use App\Models\Agent;
use App\Models\Workspace;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

function widgetAgentForRateLimit(): Agent
{
    $workspace = Workspace::factory()->create();

    return Agent::factory()->published()->create([
        'workspace_id' => $workspace->id,
        'allowed_origins' => ['https://example.com'],
    ]);
}

/**
 * The real production limits are 1000/min/init, 300/min/session,
 * 30/min/leads — much too high to exhaust in a unit-test loop.
 * Override each limiter to a tiny per-test bucket so we verify the
 * MECHANISM (429 + rate_limited code + Retry-After header) without
 * making 1000+ HTTP calls per test.
 */
function shrinkWidgetLimiters(): void
{
    RateLimiter::for('widget-init', fn (Request $request) => [
        Limit::perMinute(3)
            ->by('widget-init-test:'.($request->ip() ?? 'x').':'.$request->input('agent_id', 'x'))
            ->response(fn (Request $r, array $headers) => response()->json([
                'error' => ['code' => 'rate_limited', 'message' => 'test'],
            ], 429, $headers)),
    ]);
    RateLimiter::for('widget-session', fn (Request $request) => [
        Limit::perMinute(3)
            ->by('widget-session-test:'.($request->bearerToken() ?: 'x'))
            ->response(fn (Request $r, array $headers) => response()->json([
                'error' => ['code' => 'rate_limited', 'message' => 'test'],
            ], 429, $headers)),
    ]);
    RateLimiter::for('widget-leads', fn (Request $request) => [
        Limit::perMinute(3)
            ->by('widget-leads-test:'.($request->bearerToken() ?: 'x'))
            ->response(fn (Request $r, array $headers) => response()->json([
                'error' => ['code' => 'rate_limited', 'message' => 'test'],
            ], 429, $headers)),
    ]);
}

test('widget init throttles per (ip + agent) — mechanism verified', function () {
    shrinkWidgetLimiters();
    $agent = widgetAgentForRateLimit();

    for ($attempt = 0; $attempt < 3; $attempt++) {
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.10'])
            ->withHeaders(['Origin' => 'https://example.com'])
            ->postJson('/api/v1/widget/init', [
                'agent_id' => $agent->id,
                'anon_id' => 'anon-rate-limit-init',
                'page_url' => 'https://example.com/pricing',
            ])
            ->assertOk();
    }

    $response = $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.10'])
        ->withHeaders(['Origin' => 'https://example.com'])
        ->postJson('/api/v1/widget/init', [
            'agent_id' => $agent->id,
            'anon_id' => 'anon-rate-limit-init',
            'page_url' => 'https://example.com/pricing',
        ]);

    $response->assertStatus(429)
        ->assertJsonPath('error.code', 'rate_limited');

    expect($response->headers->get('Retry-After'))->not->toBeNull();
});

test('widget session endpoints throttle per widget token — NOT per IP (NAT-safe)', function () {
    shrinkWidgetLimiters();

    for ($attempt = 0; $attempt < 3; $attempt++) {
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.11'])
            ->withHeaders(['Authorization' => 'Bearer widget-rate-limit-token'])
            ->postJson('/api/v1/widget/messages/stream', [
                'message' => 'Hello',
            ])
            ->assertStatus(401);
    }

    $response = $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.11'])
        ->withHeaders(['Authorization' => 'Bearer widget-rate-limit-token'])
        ->postJson('/api/v1/widget/messages/stream', [
            'message' => 'Hello',
        ]);

    $response->assertStatus(429)
        ->assertJsonPath('error.code', 'rate_limited');

    expect($response->headers->get('Retry-After'))->not->toBeNull();
});

test('widget session does NOT share a bucket between two tokens on the same IP', function () {
    // Critical NAT-safety test: visitor A sitting on one corporate IP
    // can burst their token bucket to exhaustion without poisoning
    // visitor B's token bucket on the SAME IP. Pre-fix, the shared
    // per-IP bucket let A's traffic 429 B; this verifies the bucket
    // is per-token only.
    shrinkWidgetLimiters();

    // Exhaust visitor A's bucket.
    for ($attempt = 0; $attempt < 3; $attempt++) {
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.13'])
            ->withHeaders(['Authorization' => 'Bearer token-a'])
            ->postJson('/api/v1/widget/messages/stream', ['message' => 'hi'])
            ->assertStatus(401);
    }
    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.13'])
        ->withHeaders(['Authorization' => 'Bearer token-a'])
        ->postJson('/api/v1/widget/messages/stream', ['message' => 'hi'])
        ->assertStatus(429);

    // Visitor B on the SAME IP, different token: must still get through.
    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.13'])
        ->withHeaders(['Authorization' => 'Bearer token-b'])
        ->postJson('/api/v1/widget/messages/stream', ['message' => 'hi'])
        ->assertStatus(401); // Auth fails (no real JWT) — but NOT 429.
});

test('widget lead capture throttles per widget token', function () {
    shrinkWidgetLimiters();

    for ($attempt = 0; $attempt < 3; $attempt++) {
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.12'])
            ->withHeaders(['Authorization' => 'Bearer widget-lead-rate-limit-token'])
            ->postJson('/api/v1/widget/leads', [
                'email' => 'visitor@example.com',
            ])
            ->assertStatus(401);
    }

    $response = $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.12'])
        ->withHeaders(['Authorization' => 'Bearer widget-lead-rate-limit-token'])
        ->postJson('/api/v1/widget/leads', [
            'email' => 'visitor@example.com',
        ]);

    $response->assertStatus(429)
        ->assertJsonPath('error.code', 'rate_limited');

    expect($response->headers->get('Retry-After'))->not->toBeNull();
});
