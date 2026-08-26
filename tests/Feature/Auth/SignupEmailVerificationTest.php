<?php

use App\Models\AppSetting;
use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\Notification;

test('signup auto-verifies when require_email_verification is off (default)', function () {
    Notification::fake();

    AppSetting::singleton()->forceFill(['require_email_verification' => false])->save();

    $this->post('/register', [
        'name' => 'Alice',
        'email' => 'alice@example.com',
        'password' => 'StrongPass123!',
        'password_confirmation' => 'StrongPass123!',
    ])->assertRedirect();

    $user = User::query()->where('email', 'alice@example.com')->firstOrFail();
    expect($user->email_verified_at)->not->toBeNull();
    Notification::assertNothingSent();
});

test('fresh AppSetting row defaults require_email_verification to off', function () {
    // Wipe + re-resolve the singleton so we exercise the schema-level
    // default. Belt-and-braces guarantee that a fresh install never
    // strands the very first signup behind an unconfigured mailer.
    // CreateNewUser reads via `?? false` so both null and false land
    // on the auto-verify branch — we assert the boolean-coerced value
    // here so a regression that flips the default to true is caught.
    AppSetting::query()->where('id', AppSetting::SINGLETON_ID)->delete();
    AppSetting::flushSingleton();

    $fresh = AppSetting::singleton();

    expect((bool) ($fresh->require_email_verification ?? false))->toBeFalse();
});

test('user with email_verified_at=null still has hasVerifiedEmail=true when toggle is off', function () {
    // Buyer-reported (2026-05-21): existing users created before the
    // require_email_verification toggle existed have email_verified_at
    // = NULL. On login Fortify's verified middleware was redirecting
    // them to /email/verify even though the platform toggle was OFF.
    // User::hasVerifiedEmail() now short-circuits to true when the
    // toggle is OFF, so legacy NULL rows sign in without friction.
    AppSetting::singleton()->forceFill(['require_email_verification' => false])->save();
    AppSetting::flushSingleton();

    $user = User::factory()->create(['email_verified_at' => null]);

    expect($user->hasVerifiedEmail())->toBeTrue();
});

test('user with email_verified_at=null is NOT considered verified when toggle is on', function () {
    AppSetting::singleton()->forceFill(['require_email_verification' => true])->save();
    AppSetting::flushSingleton();

    $user = User::factory()->create(['email_verified_at' => null]);

    expect($user->hasVerifiedEmail())->toBeFalse();
});

test('user with email_verified_at set is verified regardless of toggle', function () {
    AppSetting::singleton()->forceFill(['require_email_verification' => true])->save();
    AppSetting::flushSingleton();

    $user = User::factory()->create(['email_verified_at' => now()]);

    expect($user->hasVerifiedEmail())->toBeTrue();
});

test('fresh install signup auto-verifies without any AppSetting row touched', function () {
    Notification::fake();

    // No forceFill — relies entirely on the schema-level default
    // being OFF. This is the "user wipes .env + boots fresh" path.
    AppSetting::query()->where('id', AppSetting::SINGLETON_ID)->delete();
    AppSetting::flushSingleton();

    $this->post('/register', [
        'name' => 'Carol',
        'email' => 'carol@example.com',
        'password' => 'StrongPass123!',
        'password_confirmation' => 'StrongPass123!',
    ])->assertRedirect();

    $user = User::query()->where('email', 'carol@example.com')->firstOrFail();
    expect($user->email_verified_at)->not->toBeNull();
    Notification::assertNothingSent();
});

test('signup leaves email_verified_at null + sends VerifyEmail when toggle is on', function () {
    Notification::fake();

    AppSetting::singleton()->forceFill(['require_email_verification' => true])->save();

    $this->post('/register', [
        'name' => 'Bob',
        'email' => 'bob@example.com',
        'password' => 'StrongPass123!',
        'password_confirmation' => 'StrongPass123!',
    ])->assertRedirect();

    $user = User::query()->where('email', 'bob@example.com')->firstOrFail();
    expect($user->email_verified_at)->toBeNull();
    Notification::assertSentTo($user, VerifyEmail::class);
});

// Buyer-reported (blengi 2026-06-27): a new customer received TWO
// "Verify your email address" mails. CreateNewUser called
// sendEmailVerificationNotification() manually AND Fortify fired the
// Registered event, whose framework listener SendEmailVerification
// Notification sends a second one. assertSentTo() above only checks
// "at least one", so the duplicate slipped through. The visitor must
// get EXACTLY ONE.
test('signup sends exactly one VerifyEmail (no duplicate) when toggle is on', function () {
    Notification::fake();

    AppSetting::singleton()->forceFill(['require_email_verification' => true])->save();

    $this->post('/register', [
        'name' => 'Dave',
        'email' => 'dave@example.com',
        'password' => 'StrongPass123!',
        'password_confirmation' => 'StrongPass123!',
    ])->assertRedirect();

    $user = User::query()->where('email', 'dave@example.com')->firstOrFail();
    Notification::assertSentToTimes($user, VerifyEmail::class, 1);
});
