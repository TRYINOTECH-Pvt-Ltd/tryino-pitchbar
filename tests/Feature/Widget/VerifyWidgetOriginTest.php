<?php

use App\Models\Agent;
use App\Models\Conversation;
use App\Models\Visitor;
use App\Models\Workspace;
use App\Services\Widget\WidgetJwt;
use Illuminate\Support\Str;

/**
 * Pins the Origin re-check on every bearer-token widget endpoint.
 * Before this middleware, a stolen JWT could be replayed from any
 * origin until expiry (60min default). After: every privileged
 * endpoint re-validates Origin against the JWT-bound agent's
 * allowed_origins, so the only thing a leaked token gets you is
 * a 403 unless the attacker also controls a listed origin.
 *
 * /widget/init is exempt — it enforces the same check inline before
 * issuing the JWT, and the issuance itself is what the middleware
 * is layered on top of.
 */
function widgetOriginAgent(array $allowedOrigins): array
{
    $workspace = Workspace::factory()->create();
    $agent = Agent::factory()->create([
        'workspace_id' => $workspace->id,
        'allowed_origins' => $allowedOrigins,
        'is_published' => true,
    ]);
    $visitor = Visitor::factory()->create(['agent_id' => $agent->id]);
    $conversation = Conversation::create([
        'id' => (string) Str::uuid7(),
        'workspace_id' => $workspace->id,
        'agent_id' => $agent->id,
        'visitor_id' => $visitor->id,
        'started_at' => now(),
    ]);
    $token = app(WidgetJwt::class)->issue($agent->id, $visitor->id, $conversation->id);

    return [
        'agent' => $agent,
        'token' => $token['token'],
        'conversation_id' => $conversation->id,
    ];
}

test('rejects a request whose Origin is not in the agent allowed_origins', function () {
    ['token' => $token] = widgetOriginAgent(['https://shop.example.com']);

    $this->postJson('/api/v1/widget/messages/stream',
        ['message' => 'hi'],
        [
            'Authorization' => 'Bearer '.$token,
            'Origin' => 'https://attacker.example',
        ],
    )->assertStatus(403)
        ->assertJsonPath('error.code', 'origin_forbidden');
});

test('rejects a request with NO Origin header at all', function () {
    ['token' => $token] = widgetOriginAgent(['https://shop.example.com']);

    // Bearer present but no Origin/Referer — common shape for
    // server-to-server replay or curl from a malicious script.
    $this->postJson('/api/v1/widget/messages/stream',
        ['message' => 'hi'],
        ['Authorization' => 'Bearer '.$token],
    )->assertStatus(403);
});

test('accepts a request whose Origin matches the allowlist verbatim', function () {
    ['token' => $token] = widgetOriginAgent(['https://shop.example.com']);

    // Origin matches — controller takes over. We don't care here
    // what status the controller returns (this test isolates the
    // middleware), only that it isn't the 403 we'd see for an
    // origin_forbidden rejection.
    $response = $this->postJson('/api/v1/widget/messages/stream',
        ['message' => 'hi'],
        [
            'Authorization' => 'Bearer '.$token,
            'Origin' => 'https://shop.example.com',
        ],
    );
    expect($response->getStatusCode())->not->toBe(403);
});

test('wildcard allowed_origins accepts any request (including no Origin)', function () {
    ['token' => $token] = widgetOriginAgent(['*']);

    // Wildcard policy mirrors InitController exactly: `*` accepts
    // every origin AND requests with no Origin header at all. The
    // wildcard is the explicit "I'm running an internal / demo
    // widget" opt-out — it would be surprising if init accepted a
    // request that the post-init middleware then 403'd.
    $response = $this->postJson('/api/v1/widget/messages/stream',
        ['message' => 'hi'],
        ['Authorization' => 'Bearer '.$token],
    );
    expect($response->getStatusCode())->not->toBe(403);

    $response = $this->postJson('/api/v1/widget/messages/stream',
        ['message' => 'hi'],
        [
            'Authorization' => 'Bearer '.$token,
            'Origin' => 'https://random.example',
        ],
    );
    expect($response->getStatusCode())->not->toBe(403);
});

test('empty allowed_origins refuses every request', function () {
    ['token' => $token] = widgetOriginAgent([]);

    // Matches /widget/init's "empty list = deny all" policy. A
    // workspace owner who hasn't configured their origins must NOT
    // accidentally let the world through any backend endpoint.
    $this->postJson('/api/v1/widget/messages/stream',
        ['message' => 'hi'],
        [
            'Authorization' => 'Bearer '.$token,
            'Origin' => 'https://anything.example',
        ],
    )->assertStatus(403);
});

test('Origin matching is normalised (case + trailing slash)', function () {
    ['token' => $token] = widgetOriginAgent(['https://shop.example.com']);

    // Browser-canonical Origin matches the customer's listed origin
    // even with the trailing slash + case shenanigans they pasted in
    // the admin UI.
    $response = $this->postJson('/api/v1/widget/messages/stream',
        ['message' => 'hi'],
        [
            'Authorization' => 'Bearer '.$token,
            'Origin' => 'https://SHOP.example.com/',
        ],
    );
    expect($response->getStatusCode())->not->toBe(403);
});
