<?php

use App\Enums\PlatformRole;
use App\Mail\AdminDailyDigest;
use App\Models\AppSetting;
use App\Models\Lead;
use App\Models\Plan;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Mail;

/**
 * Buyer-reported (Lucian, 2026-05-15): "have a checkbox on settings to
 * send email to admin at least with new subscriptions". Opt-in daily
 * digest at 09:00 UTC, computed from the last 24 hours.
 */
test('digest command is a no-op when the flag is disabled', function () {
    Mail::fake();
    AppSetting::singleton()->forceFill(['admin_daily_digest_enabled' => false])->save();

    $this->artisan('admin:send-daily-digest')->assertSuccessful();

    Mail::assertNothingSent();
});

test('digest command emails every super_admin when the flag is enabled', function () {
    Mail::fake();
    AppSetting::singleton()->forceFill(['admin_daily_digest_enabled' => true])->save();

    $admin1 = User::factory()->create(['role' => PlatformRole::SuperAdmin, 'email' => 'admin1@example.com']);
    $admin2 = User::factory()->create(['role' => PlatformRole::SuperAdmin, 'email' => 'admin2@example.com']);
    // Non-admin shouldn't receive the digest
    User::factory()->create(['role' => PlatformRole::Customer, 'email' => 'noise@example.com']);

    $this->artisan('admin:send-daily-digest')->assertSuccessful();

    Mail::assertSent(AdminDailyDigest::class, function ($mail) use ($admin1) {
        return $mail->hasTo($admin1->email);
    });
    Mail::assertSent(AdminDailyDigest::class, function ($mail) use ($admin2) {
        return $mail->hasTo($admin2->email);
    });
    Mail::assertNotSent(AdminDailyDigest::class, function ($mail) {
        return $mail->hasTo('noise@example.com');
    });
});

test('--force overrides the disabled flag for one-off previews', function () {
    Mail::fake();
    AppSetting::singleton()->forceFill(['admin_daily_digest_enabled' => false])->save();
    $admin = User::factory()->create(['role' => PlatformRole::SuperAdmin, 'email' => 'a@example.com']);

    $this->artisan('admin:send-daily-digest', ['--force' => true])->assertSuccessful();

    Mail::assertSent(AdminDailyDigest::class, fn ($m) => $m->hasTo($admin->email));
});

test('--dry-run computes stats but sends no mail', function () {
    Mail::fake();
    AppSetting::singleton()->forceFill(['admin_daily_digest_enabled' => true])->save();
    User::factory()->create(['role' => PlatformRole::SuperAdmin]);

    $this->artisan('admin:send-daily-digest', ['--dry-run' => true])
        ->expectsOutputToContain('Computed digest:')
        ->expectsOutputToContain('Dry run')
        ->assertSuccessful();

    Mail::assertNothingSent();
});

test('digest stats count the right rows for the last 24h', function () {
    Mail::fake();
    AppSetting::singleton()->forceFill(['admin_daily_digest_enabled' => true])->save();

    // Recipient
    User::factory()->create(['role' => PlatformRole::SuperAdmin, 'email' => 'admin@example.com']);

    // New user inside window
    User::factory()->create(['created_at' => now()->subHour()]);

    // New paid subscription = workspace with paid plan_id, updated today
    $plan = Plan::create([
        'name' => 'Pro', 'slug' => 'pro-'.bin2hex(random_bytes(2)),
        'monthly_conversations' => 1000, 'price_cents' => 4900,
        'features' => [], 'is_active' => true,
    ]);
    Workspace::factory()->create([
        'plan_id' => $plan->id,
        'updated_at' => now()->subMinutes(10),
    ]);

    // New lead
    Lead::factory()->create(['created_at' => now()->subMinutes(5)]);

    $this->artisan('admin:send-daily-digest')->assertSuccessful();

    Mail::assertSent(AdminDailyDigest::class, function ($mail) {
        $stats = $mail->stats;

        return $stats['new_users'] >= 2 // recipient + factory + maybe more
            && $stats['new_subscriptions'] >= 1
            && $stats['new_leads'] >= 1;
    });
});
