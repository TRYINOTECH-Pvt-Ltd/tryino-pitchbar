<?php

use App\Enums\PlatformRole;
use App\Models\AppSetting;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('system settings page exposes cron_worker section data', function () {
    $admin = User::factory()->create(['role' => PlatformRole::SuperAdmin]);

    $s = AppSetting::singleton();
    $s->cloudflare_account_id = 'acct';
    $s->cloudflare_api_token = 'token';
    $s->save();
    AppSetting::flushSingleton();

    $this->actingAs($admin)
        ->get('/settings/system')
        ->assertOk()
        ->assertInertia(fn (Assert $p) => $p
            ->component('settings/system')
            ->has('sections.cron_worker')
            ->where('sections.cron_worker.deployed', false)
            ->where('sections.cron_worker.cloudflare_configured', true)
            ->has('sections.cron_worker.callback_url')
        );
});

test('cron_worker.deployed is true after a successful deploy', function () {
    $admin = User::factory()->create(['role' => PlatformRole::SuperAdmin]);
    $s = AppSetting::singleton();
    $s->cloudflare_account_id = 'acct';
    $s->cloudflare_api_token = 'token';
    $s->cron_worker_name = 'pitchbar-tick-test';
    $s->cron_worker_deployed_at = now();
    $s->save();
    AppSetting::flushSingleton();

    $this->actingAs($admin)
        ->get('/settings/system')
        ->assertOk()
        ->assertInertia(fn (Assert $p) => $p
            ->component('settings/system')
            ->where('sections.cron_worker.deployed', true)
            ->where('sections.cron_worker.worker_name', 'pitchbar-tick-test')
        );
});
