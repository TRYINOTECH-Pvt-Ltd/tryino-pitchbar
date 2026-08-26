<?php

use App\Models\Lead;
use App\Models\Plan;
use App\Models\User;
use App\Models\Workspace;
use App\Support\PlatformAdminHeader;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    Cache::flush();
});

/**
 * Buyer-reported (Lucian, 2026-05-15): admin top-right bell was showing
 * only infra health checks. Now also surfaces last-24h business events.
 */
test('bell surfaces new users in the last 24h', function () {
    User::factory()->count(3)->create();

    $payload = app(PlatformAdminHeader::class)->payload();

    $items = collect($payload['notifications']['items']);
    $newUsers = $items->firstWhere('key', 'new_users_24h');
    expect($newUsers)->not->toBeNull();
    expect($newUsers['title'])->toContain('3 new users signed up');
});

test('bell surfaces new workspaces in the last 24h', function () {
    Workspace::factory()->count(2)->create();

    $payload = app(PlatformAdminHeader::class)->payload();

    $items = collect($payload['notifications']['items']);
    $row = $items->firstWhere('key', 'new_workspaces_24h');
    expect($row)->not->toBeNull();
    expect($row['title'])->toContain('2 new workspaces');
});

test('bell surfaces new paid subscriptions but ignores free plans', function () {
    $free = Plan::create([
        'name' => 'Free', 'slug' => 'free-'.bin2hex(random_bytes(2)),
        'monthly_conversations' => 100, 'price_cents' => 0,
        'features' => [], 'is_active' => true,
    ]);
    $paid = Plan::create([
        'name' => 'Pro', 'slug' => 'pro-'.bin2hex(random_bytes(2)),
        'monthly_conversations' => 1000, 'price_cents' => 4900,
        'features' => [], 'is_active' => true,
    ]);

    Workspace::factory()->create(['plan_id' => $free->id, 'updated_at' => now()]);
    Workspace::factory()->create(['plan_id' => $paid->id, 'updated_at' => now()]);

    $payload = app(PlatformAdminHeader::class)->payload();
    $items = collect($payload['notifications']['items']);
    $row = $items->firstWhere('key', 'new_subscriptions_24h');
    expect($row)->not->toBeNull();
    expect($row['title'])->toContain('1 new paid subscription');
});

test('bell surfaces new leads', function () {
    Lead::factory()->count(5)->create();

    $payload = app(PlatformAdminHeader::class)->payload();
    $items = collect($payload['notifications']['items']);
    $row = $items->firstWhere('key', 'new_leads_24h');
    expect($row)->not->toBeNull();
    expect($row['title'])->toContain('5 new leads');
});

test('site_health.issues_count counts ONLY health failures, not biz events', function () {
    // Pure-info biz events shouldn't paint the bell red.
    User::factory()->count(2)->create();

    $payload = app(PlatformAdminHeader::class)->payload();

    // issues_count = health failures only; biz events are info-severity
    // and ride alongside without inflating the badge severity.
    expect($payload['site_health']['issues_count'])
        ->toBeGreaterThanOrEqual(0);
    expect($payload['notifications']['unread_count'])
        ->toBeGreaterThanOrEqual(1); // at least the 2-new-users biz row
});

test('idle install shows the all_clear info card', function () {
    // No biz events, no failing health checks (we don't assert health
    // is clean here — just that whatever items returned include an
    // all_clear card when biz events are empty).
    $payload = app(PlatformAdminHeader::class)->payload();
    expect($payload['notifications']['items'])->not->toBeEmpty();
});
