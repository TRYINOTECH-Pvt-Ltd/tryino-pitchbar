<?php

use App\Jobs\Crawl\CrawlSourceJob;
use App\Models\Agent;
use App\Models\Source;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceUser;
use App\Services\Crawl\SiteDiscoverer;
use Illuminate\Support\Facades\Bus;

function asWorkspaceAdmin(): array
{
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['owner_user_id' => $user->id]);
    WorkspaceUser::create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'role' => 'admin',
        'invited_at' => now(),
        'accepted_at' => now(),
    ]);
    $user->forceFill(['default_workspace_id' => $workspace->id])->save();
    $agent = Agent::factory()->create(['workspace_id' => $workspace->id]);

    return ['user' => $user, 'workspace' => $workspace, 'agent' => $agent];
}

test('discover endpoint returns sitemap and probed URLs from the service', function () {
    ['user' => $user, 'agent' => $agent] = asWorkspaceAdmin();

    app()->bind(SiteDiscoverer::class, fn () => new class extends SiteDiscoverer
    {
        public function __construct() {}

        public function discover(string $rootUrl, int $max = 50): array
        {
            return [
                'root' => 'https://acme.test',
                'sitemap_urls' => ['https://acme.test/a', 'https://acme.test/b'],
                'probed_urls' => ['https://acme.test/about'],
            ];
        }
    });

    $response = $this->actingAs($user)
        ->postJson("/app/agents/{$agent->id}/sources/discover", ['url' => 'https://acme.test']);

    $response->assertOk();
    $response->assertJsonPath('data.root', 'https://acme.test');
    $response->assertJsonPath('data.total', 3);
    $response->assertJsonPath('data.sitemap_urls.0', 'https://acme.test/a');
    $response->assertJsonPath('data.probed_urls.0', 'https://acme.test/about');
});

test('discover requires a valid URL', function () {
    ['user' => $user, 'agent' => $agent] = asWorkspaceAdmin();

    $this->actingAs($user)
        ->postJson("/app/agents/{$agent->id}/sources/discover", ['url' => 'not-a-url'])
        ->assertStatus(422);
});

test('bulkStore creates one source per URL and dispatches a crawl', function () {
    Bus::fake();
    ['user' => $user, 'agent' => $agent] = asWorkspaceAdmin();

    $urls = [
        'https://acme.test/about',
        'https://acme.test/pricing',
        'https://acme.test/docs',
    ];

    $this->actingAs($user)
        ->post("/app/agents/{$agent->id}/sources/bulk", ['urls' => $urls])
        ->assertRedirect();

    expect(Source::query()->where('agent_id', $agent->id)->count())->toBe(3);
    Bus::assertDispatchedTimes(CrawlSourceJob::class, 3);
});

test('bulkStore caps at 200 urls', function () {
    ['user' => $user, 'agent' => $agent] = asWorkspaceAdmin();

    $urls = array_map(fn ($i) => "https://acme.test/p/{$i}", range(1, 250));

    $this->actingAs($user)
        ->postJson("/app/agents/{$agent->id}/sources/bulk", ['urls' => $urls])
        ->assertStatus(422);
});

test('viewer cannot discover or bulk-add', function () {
    $viewer = User::factory()->create();
    $ws = Workspace::factory()->create();
    WorkspaceUser::create([
        'workspace_id' => $ws->id,
        'user_id' => $viewer->id,
        'role' => 'viewer',
        'invited_at' => now(),
        'accepted_at' => now(),
    ]);
    $viewer->forceFill(['default_workspace_id' => $ws->id])->save();
    $agent = Agent::factory()->create(['workspace_id' => $ws->id]);

    $this->actingAs($viewer)
        ->postJson("/app/agents/{$agent->id}/sources/discover", ['url' => 'https://acme.test'])
        ->assertForbidden();

    $this->actingAs($viewer)
        ->post("/app/agents/{$agent->id}/sources/bulk", ['urls' => ['https://acme.test/a']])
        ->assertForbidden();
});
