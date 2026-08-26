<?php

use App\Jobs\Analytics\DetectAgentSiteTypeJob;
use App\Models\Agent;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceUser;
use App\Services\Vertical\SiteTypeDetector;
use GuzzleHttp\Client as Guzzle;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use Illuminate\Support\Facades\Bus;

function autoDetectAsMember(string $role = 'admin'): array
{
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['owner_user_id' => $user->id]);
    WorkspaceUser::create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'role' => $role,
        'invited_at' => now(),
        'accepted_at' => now(),
    ]);
    $user->forceFill(['default_workspace_id' => $workspace->id])->save();

    return ['user' => $user, 'workspace' => $workspace];
}

test('creating an agent without site_type dispatches DetectAgentSiteTypeJob', function () {
    Bus::fake();
    ['user' => $user] = autoDetectAsMember('admin');

    $this->actingAs($user)->post(route('agents.store'), [
        'name' => 'Test Agent',
        'language_default' => 'en',
        'allowed_origins' => ['https://example.com'],
        'system_prompt' => 'Be helpful.',
        'confidence_threshold' => 0.78,
    ])->assertRedirect();

    Bus::assertDispatched(DetectAgentSiteTypeJob::class);
});

test('creating an agent with explicit site_type does NOT dispatch detect job', function () {
    Bus::fake();
    ['user' => $user] = autoDetectAsMember('admin');

    $this->actingAs($user)->post(route('agents.store'), [
        'name' => 'Test Agent',
        'language_default' => 'en',
        'allowed_origins' => ['https://example.com'],
        'system_prompt' => 'Be helpful.',
        'confidence_threshold' => 0.78,
        'site_type' => 'ecommerce',
    ])->assertRedirect();

    Bus::assertNotDispatched(DetectAgentSiteTypeJob::class);
});

test('DetectAgentSiteTypeJob persists detected site_type when homepage signals ecommerce', function () {
    $agent = Agent::factory()->create([
        'site_type' => null,
        'allowed_origins' => ['https://shop.example.com'],
    ]);

    $html = '<html><head><meta property="og:type" content="product"/></head><body></body></html>';
    $mock = new MockHandler([new GuzzleResponse(200, [], $html)]);
    $guzzle = new Guzzle(['handler' => HandlerStack::create($mock)]);

    $detector = Mockery::mock(SiteTypeDetector::class);
    $detector->shouldReceive('detect')
        ->once()
        ->andReturn(['type' => 'ecommerce', 'confidence' => 0.9, 'alternatives' => [], 'signals' => ['og:type=product']]);

    (new DetectAgentSiteTypeJob($agent->id))->handle($detector, $guzzle);

    $agent->refresh();
    expect($agent->site_type)->toBe('ecommerce');
    expect($agent->vertical_signals['auto'] ?? null)->toBeTrue();
});

test('DetectAgentSiteTypeJob skips when agent already has site_type set', function () {
    $agent = Agent::factory()->create([
        'site_type' => 'saas',
        'allowed_origins' => ['https://example.com'],
    ]);

    $detector = Mockery::mock(SiteTypeDetector::class);
    $detector->shouldNotReceive('detect');

    $mock = new MockHandler([new GuzzleResponse(200, [], '<html></html>')]);
    $guzzle = new Guzzle(['handler' => HandlerStack::create($mock)]);

    (new DetectAgentSiteTypeJob($agent->id))->handle($detector, $guzzle);

    $agent->refresh();
    expect($agent->site_type)->toBe('saas');
});

test('DetectAgentSiteTypeJob skips when allowed_origins has nothing usable', function () {
    $agent = Agent::factory()->create([
        'site_type' => null,
        'allowed_origins' => ['*'],
    ]);

    $detector = Mockery::mock(SiteTypeDetector::class);
    $detector->shouldNotReceive('detect');

    $mock = new MockHandler;
    $guzzle = new Guzzle(['handler' => HandlerStack::create($mock)]);

    (new DetectAgentSiteTypeJob($agent->id))->handle($detector, $guzzle);

    $agent->refresh();
    expect($agent->site_type)->toBeNull();
});

test('DetectAgentSiteTypeJob does not throw when the fetch fails', function () {
    $agent = Agent::factory()->create([
        'site_type' => null,
        'allowed_origins' => ['https://offline.example.com'],
    ]);

    $mock = new MockHandler([new GuzzleResponse(503, [], '')]);
    $guzzle = new Guzzle(['handler' => HandlerStack::create($mock)]);

    $detector = Mockery::mock(SiteTypeDetector::class);
    $detector->shouldNotReceive('detect');

    (new DetectAgentSiteTypeJob($agent->id))->handle($detector, $guzzle);

    $agent->refresh();
    expect($agent->site_type)->toBeNull();
})->throwsNoExceptions();

test('DetectAgentSiteTypeJob records the attempt but leaves site_type null when detection returns generic', function () {
    $agent = Agent::factory()->create([
        'site_type' => null,
        'allowed_origins' => ['https://example.com'],
    ]);

    $mock = new MockHandler([new GuzzleResponse(200, [], '<html></html>')]);
    $guzzle = new Guzzle(['handler' => HandlerStack::create($mock)]);

    $detector = Mockery::mock(SiteTypeDetector::class);
    $detector->shouldReceive('detect')
        ->once()
        ->andReturn(['type' => 'generic', 'confidence' => 0.0, 'alternatives' => [], 'signals' => []]);

    (new DetectAgentSiteTypeJob($agent->id))->handle($detector, $guzzle);

    $agent->refresh();
    expect($agent->site_type)->toBeNull();
    // We record the attempt so the UI can tell "never tried" from
    // "tried and gave up" — banner copy on the show page reads the
    // vertical_signals.auto flag to soften the nudge after a failed
    // detect.
    expect($agent->vertical_signals['auto'] ?? null)->toBeTrue();
    expect($agent->vertical_signals['type'] ?? null)->toBe('generic');
});
