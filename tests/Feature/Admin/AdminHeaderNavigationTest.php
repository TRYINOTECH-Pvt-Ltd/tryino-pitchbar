<?php

use App\Enums\PlatformRole;
use App\Models\Agent;
use App\Models\Conversation;
use App\Models\Lead;
use App\Models\User;
use App\Models\Visitor;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

function seedHeaderFailedJob(string $job, string $exception): string
{
    $uuid = (string) Str::uuid();

    DB::table('failed_jobs')->insert([
        'uuid' => $uuid,
        'connection' => 'database',
        'queue' => 'default',
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

test('admin pages receive dynamic header health and notifications', function () {
    $admin = User::factory()->create(['role' => PlatformRole::SuperAdmin]);
    seedHeaderFailedJob('App\\Jobs\\Crawl\\CrawlPageJob', 'RuntimeException: boom');

    $this->actingAs($admin)
        ->get('/admin')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('adminHeader.site_health.score')
            ->where('adminHeader.notifications.items.0.key', 'failed_jobs')
            ->where('adminHeader.notifications.items.0.href', '/admin/jobs/failed')
            ->where('adminHeader.notifications.unread_count', fn (int $count) => $count >= 1));
});

test('customer pages do not receive admin header payload', function () {
    ['user' => $user] = workspaceMember();

    $this->actingAs($user)
        ->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('adminHeader', null));
});

test('platform search returns matching admin resources', function () {
    $admin = User::factory()->create(['role' => PlatformRole::SuperAdmin]);
    $owner = User::factory()->create(['name' => 'Acme Owner', 'email' => 'owner@acme.test']);
    $workspace = Workspace::factory()->create([
        'name' => 'Acme Workspace',
        'slug' => 'acme-workspace',
        'owner_user_id' => $owner->id,
    ]);
    $agent = Agent::factory()->create([
        'workspace_id' => $workspace->id,
        'name' => 'Acme Pricing Bot',
        'is_published' => true,
    ]);
    $visitor = Visitor::factory()->create(['agent_id' => $agent->id]);
    $conversation = Conversation::factory()->create([
        'agent_id' => $agent->id,
        'visitor_id' => $visitor->id,
    ]);
    $lead = Lead::create([
        'id' => (string) Str::uuid7(),
        'conversation_id' => $conversation->id,
        'agent_id' => $agent->id,
        'email' => 'acme-lead@example.com',
        'name' => 'Acme Lead',
        'status' => 'new',
    ]);

    $response = $this->actingAs($admin)->getJson('/admin/search?q=acme');

    $response->assertOk();
    $response->assertJsonPath('data.workspaces.0.id', $workspace->id);
    $response->assertJsonPath('data.users.0.email', 'owner@acme.test');
    $response->assertJsonPath('data.agents.0.id', $agent->id);
    $response->assertJsonPath('data.leads.0.id', $lead->id);
});

test('platform search is gated by super_admin middleware', function () {
    $customer = User::factory()->create(['role' => PlatformRole::Customer]);

    $this->actingAs($customer)
        ->getJson('/admin/search?q=acme')
        ->assertNotFound();
});
