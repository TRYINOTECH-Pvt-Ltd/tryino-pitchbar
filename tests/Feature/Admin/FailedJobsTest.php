<?php

use App\Enums\PlatformRole;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

function seedFailedJob(string $job, string $exception): string
{
    $uuid = (string) Str::uuid();
    DB::table('failed_jobs')->insert([
        'uuid' => $uuid,
        'connection' => 'database',
        'queue' => 'crawl',
        'payload' => json_encode([
            'displayName' => $job,
            'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
            'data' => [],
        ]),
        'exception' => $exception,
        'failed_at' => now(),
    ]);

    return $uuid;
}

test('failed-jobs page renders and lists failed jobs for super_admin', function () {
    $admin = User::factory()->create(['role' => PlatformRole::SuperAdmin]);
    seedFailedJob('App\\Jobs\\Crawl\\CrawlPageJob', "RuntimeException: upstream 500\n#0 trace");

    $response = $this->actingAs($admin)->get('/admin/jobs/failed');

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('admin/jobs/failed')
        ->has('jobs', 1)
        ->where('jobs.0.job', 'App\\Jobs\\Crawl\\CrawlPageJob')
        ->where('total', 1)
    );
});

test('failed-jobs page is gated by super_admin middleware', function () {
    $customer = User::factory()->create(['role' => PlatformRole::Customer]);

    $this->actingAs($customer)->get('/admin/jobs/failed')->assertNotFound();
});

test('show returns the full exception trace as JSON', function () {
    $admin = User::factory()->create(['role' => PlatformRole::SuperAdmin]);
    $longTrace = "RuntimeException: upstream 500\n#0 /vendor/foo.php:123\n#1 /app/bar.php:45\n#2 /app/baz.php:67";
    $uuid = seedFailedJob('App\\Jobs\\Crawl\\CrawlPageJob', $longTrace);

    $response = $this->actingAs($admin)->getJson("/admin/jobs/failed/{$uuid}");

    $response->assertOk();
    $response->assertJsonPath('data.uuid', $uuid);
    $response->assertJsonPath('data.exception', $longTrace);
    $response->assertJsonPath('data.queue', 'crawl');
});

test('show 404s for unknown uuid', function () {
    $admin = User::factory()->create(['role' => PlatformRole::SuperAdmin]);

    $this->actingAs($admin)
        ->getJson('/admin/jobs/failed/never-existed')
        ->assertStatus(404);
});

test('show is gated by super_admin middleware', function () {
    $customer = User::factory()->create(['role' => PlatformRole::Customer]);
    $uuid = seedFailedJob('JobA', 'whatever');

    $this->actingAs($customer)
        ->getJson("/admin/jobs/failed/{$uuid}")
        ->assertNotFound();
});

test('forget removes a failed-job row', function () {
    $admin = User::factory()->create(['role' => PlatformRole::SuperAdmin]);
    $uuid = seedFailedJob('App\\Jobs\\Crawl\\CrawlPageJob', 'whatever');

    expect(DB::table('failed_jobs')->where('uuid', $uuid)->exists())->toBeTrue();

    $this->actingAs($admin)
        ->post("/admin/jobs/failed/{$uuid}/forget")
        ->assertRedirect();

    expect(DB::table('failed_jobs')->where('uuid', $uuid)->exists())->toBeFalse();
});

test('flush wipes all failed-job rows', function () {
    $admin = User::factory()->create(['role' => PlatformRole::SuperAdmin]);
    seedFailedJob('JobA', 'a');
    seedFailedJob('JobB', 'b');
    seedFailedJob('JobC', 'c');

    expect(DB::table('failed_jobs')->count())->toBe(3);

    $this->actingAs($admin)
        ->post('/admin/jobs/failed/flush')
        ->assertRedirect();

    expect(DB::table('failed_jobs')->count())->toBe(0);
});

test('customer cannot retry, forget, or flush', function () {
    $customer = User::factory()->create(['role' => PlatformRole::Customer]);
    $uuid = seedFailedJob('JobA', 'a');

    $this->actingAs($customer)->post("/admin/jobs/failed/{$uuid}/forget")->assertNotFound();
    $this->actingAs($customer)->post('/admin/jobs/failed/flush')->assertNotFound();

    expect(DB::table('failed_jobs')->where('uuid', $uuid)->exists())->toBeTrue();
});
