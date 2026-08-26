<?php

use App\Enums\PlatformRole;
use App\Models\AppSetting;
use App\Models\User;
use App\Services\Cloudflare\WorkerDeployer;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;

function asPlatformSuperAdmin(): User
{
    /** @var User $user */
    $user = User::factory()->create(['role' => PlatformRole::SuperAdmin]);

    return $user;
}

function bindCfMockClient(array $responses): MockHandler
{
    $mock = new MockHandler($responses);
    $stack = HandlerStack::create($mock);
    app()->instance(WorkerDeployer::class, new WorkerDeployer(new Client(['handler' => $stack])));

    return $mock;
}

beforeEach(function () {
    $s = AppSetting::singleton();
    $s->cloudflare_account_id = 'cf-acct';
    $s->cloudflare_api_token = 'cf-token';
    $s->internal_queue_token = null;
    $s->cron_worker_name = null;
    $s->cron_worker_deployed_at = null;
    $s->save();
    AppSetting::flushSingleton();
});

test('admin without super_admin cannot deploy', function () {
    $user = User::factory()->create(['role' => PlatformRole::Customer]);

    $this->actingAs($user)
        ->post('/settings/system/cron-worker/deploy')
        ->assertNotFound(); // super_admin middleware uses existence-hide
});

test('super-admin can deploy when CF credentials are present', function () {
    bindCfMockClient([
        new Response(200, [], '{"success":true,"result":{}}'),
        new Response(200, [], '{"success":true}'),
        new Response(200, [], '{"success":true}'),
    ]);

    $this->actingAs(asPlatformSuperAdmin())
        ->postJson('/settings/system/cron-worker/deploy')
        ->assertOk()
        ->assertJsonPath('data.ok', true)
        ->assertJsonPath('data.cron_schedule', '* * * * *');

    $s = AppSetting::singleton();
    expect($s->cron_worker_name)->not->toBeNull();
    expect($s->cron_worker_deployed_at)->not->toBeNull();
    expect((string) $s->internal_queue_token)->not->toBe('');
});

test('deploy generates a fresh token when rotate_token=true is passed', function () {
    bindCfMockClient([
        new Response(200, [], '{}'),
        new Response(200, [], '{}'),
        new Response(200, [], '{}'),
    ]);

    // First save a token
    $s = AppSetting::singleton();
    $s->internal_queue_token = 'old-token';
    $s->save();
    AppSetting::flushSingleton();

    bindCfMockClient([
        new Response(200, [], '{}'),
        new Response(200, [], '{}'),
        new Response(200, [], '{}'),
    ]);

    $this->actingAs(asPlatformSuperAdmin())
        ->postJson('/settings/system/cron-worker/deploy', ['rotate_token' => true])
        ->assertOk();

    $s = AppSetting::singleton();
    expect((string) $s->internal_queue_token)->not->toBe('old-token');
});

test('deploy returns 422 when Cloudflare credentials are missing', function () {
    $s = AppSetting::singleton();
    $s->cloudflare_account_id = null;
    $s->cloudflare_api_token = null;
    $s->save();
    AppSetting::flushSingleton();
    // Controller now reads from config() only (no env() fallback).
    // Clear both the DB and the runtime config so the missing-creds
    // branch actually fires under tests.
    config()->set('services.cloudflare.account_id', '');
    config()->set('services.cloudflare.api_token', '');

    $this->actingAs(asPlatformSuperAdmin())
        ->postJson('/settings/system/cron-worker/deploy')
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'cloudflare_missing');
});

test('deploy returns 502 when Cloudflare API rejects', function () {
    bindCfMockClient([
        new Response(403, [], '{"success":false,"errors":[{"message":"forbidden"}]}'),
    ]);

    $this->actingAs(asPlatformSuperAdmin())
        ->postJson('/settings/system/cron-worker/deploy')
        ->assertStatus(502)
        ->assertJsonPath('error.code', 'deploy_failed');
});

test('destroy removes the worker and clears local state', function () {
    // First deploy.
    bindCfMockClient([
        new Response(200, [], '{}'),
        new Response(200, [], '{}'),
        new Response(200, [], '{}'),
    ]);

    $this->actingAs(asPlatformSuperAdmin())
        ->postJson('/settings/system/cron-worker/deploy')
        ->assertOk();

    // Then destroy.
    bindCfMockClient([new Response(200, [], '{}')]);
    $this->actingAs(asPlatformSuperAdmin())
        ->deleteJson('/settings/system/cron-worker')
        ->assertOk()
        ->assertJsonPath('data.ok', true);

    $s = AppSetting::singleton();
    expect($s->cron_worker_name)->toBeNull();
    expect($s->cron_worker_deployed_at)->toBeNull();
});

test('status returns deployed=false when never deployed', function () {
    $this->actingAs(asPlatformSuperAdmin())
        ->getJson('/settings/system/cron-worker/status')
        ->assertOk()
        ->assertJsonPath('data.deployed', false);
});
