<?php

use App\Jobs\Leads\RouteLeadJob;
use App\Models\Agent;
use App\Models\Conversation;
use App\Models\IntegrationConnection;
use App\Models\Lead;
use App\Models\Source;
use App\Models\User;
use App\Models\Visitor;
use App\Models\WebhookSubscription;
use App\Models\Workspace;
use App\Models\WorkspaceUser;
use App\Services\Integrations\SlackPusher;
use App\Services\Webhooks\SignedDispatcher;

function makeSlackUser(): array
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

    return ['user' => $user, 'workspace' => $workspace];
}

test('integrations page surfaces a per-provider indexed summary', function () {
    ['user' => $user, 'workspace' => $workspace] = makeSlackUser();
    $agent = Agent::factory()->create(['workspace_id' => $workspace->id]);

    foreach (range(1, 3) as $i) {
        Source::factory()->create([
            'agent_id' => $agent->id,
            'type' => 'notion',
            'status' => 'indexed',
        ]);
    }
    Source::factory()->create([
        'agent_id' => $agent->id,
        'type' => 'google_doc',
        'status' => 'indexed',
    ]);

    IntegrationConnection::create([
        'workspace_id' => $workspace->id,
        'kind' => 'notion',
        'credentials_encrypted' => ['access_token' => 'x', 'workspace_name' => 'Acme'],
        'status' => 'active',
    ]);
    IntegrationConnection::create([
        'workspace_id' => $workspace->id,
        'kind' => 'google',
        'credentials_encrypted' => ['access_token' => 'y'],
        'status' => 'active',
    ]);

    $response = $this->actingAs($user)->get('/app/integrations');

    $response->assertOk();
    $response->assertInertia(fn ($p) => $p
        ->where('integrations', fn ($all) => collect($all)->contains(fn ($i) => $i['kind'] === 'notion' && $i['summary'] === '3 Notion pages indexed'))
        ->where('integrations', fn ($all) => collect($all)->contains(fn ($i) => $i['kind'] === 'google' && $i['summary'] === '1 Google Doc indexed'))
    );
});

test('integrations page renders with the current Slack state', function () {
    ['user' => $user, 'workspace' => $workspace] = makeSlackUser();
    IntegrationConnection::create([
        'workspace_id' => $workspace->id,
        'kind' => 'slack',
        'credentials_encrypted' => ['webhook_url' => 'https://hooks.slack.com/services/T1/B2/secret'],
        'status' => 'active',
    ]);

    $response = $this->actingAs($user)->get('/app/integrations');

    $response->assertOk();
    $response->assertInertia(fn ($p) => $p
        ->component('app/integrations/index')
        ->has('integrations', 1)
        ->where('integrations.0.kind', 'slack')
        ->where('integrations.0.is_configured', true)
        ->where('integrations.0.webhook_hint', 'T1/B2')
    );
});

test('integrations page also exposes webhook subscriptions and supported events', function () {
    ['user' => $user, 'workspace' => $workspace] = makeSlackUser();

    WebhookSubscription::create([
        'workspace_id' => $workspace->id,
        'url' => 'https://hooks.example.com/pitchbar',
        'secret' => 'pitchbar-secret-1234',
        'events' => ['lead.captured'],
        'enabled' => true,
    ]);

    $response = $this->actingAs($user)->get('/app/integrations');

    $response->assertOk();
    $response->assertInertia(fn ($p) => $p
        ->has('webhookSubscriptions', 1)
        ->where('webhookSubscriptions.0.url', 'https://hooks.example.com/pitchbar')
        ->where('webhookSubscriptions.0.event_labels.0', 'Lead captured')
        ->where('webhookSubscriptions.0.secret_hint', fn ($value) => is_string($value)
            && str_ends_with((string) $value, '1234'))
        ->where('webhookEventOptions.0.value', 'lead.captured')
    );
});

test('storeSlack persists an encrypted webhook url and replaces any prior one', function () {
    ['user' => $user, 'workspace' => $workspace] = makeSlackUser();

    // First setup
    $this->actingAs($user)->post('/app/integrations/slack', [
        'webhook_url' => 'https://hooks.slack.com/services/T1/B2/secretA',
        'send_test' => false,
    ])->assertRedirect();

    expect(IntegrationConnection::query()->where('workspace_id', $workspace->id)->count())->toBe(1);

    // Replace
    $this->actingAs($user)->post('/app/integrations/slack', [
        'webhook_url' => 'https://hooks.slack.com/services/T1/B2/secretB',
        'send_test' => false,
    ])->assertRedirect();

    expect(IntegrationConnection::query()->where('workspace_id', $workspace->id)->count())->toBe(1);

    $stored = IntegrationConnection::query()->where('workspace_id', $workspace->id)->first();
    expect($stored->credentials_encrypted['webhook_url'])->toBe('https://hooks.slack.com/services/T1/B2/secretB');
});

test('storeSlack rejects URLs that are not Slack webhooks', function () {
    ['user' => $user] = makeSlackUser();

    $this->actingAs($user)
        ->postJson('/app/integrations/slack', ['webhook_url' => 'https://evil.example.com/x'])
        ->assertStatus(422);

    expect(IntegrationConnection::query()->count())->toBe(0);
});

test('storeWebhook persists an outbound webhook subscription', function () {
    ['user' => $user, 'workspace' => $workspace] = makeSlackUser();

    $this->actingAs($user)->post('/app/integrations/webhooks', [
        'url' => 'https://hooks.example.com/pitchbar',
        'secret' => 'pitchbar-secret-1234',
        'events' => ['lead.captured'],
        'enabled' => true,
    ])->assertRedirect();

    $stored = WebhookSubscription::query()->where('workspace_id', $workspace->id)->first();

    expect($stored)->not->toBeNull();
    expect($stored?->url)->toBe('https://hooks.example.com/pitchbar');
    expect($stored?->secret)->toBe('pitchbar-secret-1234');
    expect($stored?->events)->toBe(['lead.captured']);
    expect($stored?->enabled)->toBeTrue();
});

test('updateWebhook keeps the current secret when the replacement field is blank', function () {
    ['user' => $user, 'workspace' => $workspace] = makeSlackUser();

    $subscription = WebhookSubscription::create([
        'workspace_id' => $workspace->id,
        'url' => 'https://hooks.example.com/original',
        'secret' => 'pitchbar-secret-1234',
        'events' => ['lead.captured'],
        'enabled' => true,
    ]);

    $this->actingAs($user)->patch("/app/integrations/webhooks/{$subscription->id}", [
        'url' => 'https://hooks.example.com/updated',
        'secret' => '',
        'events' => ['lead.captured'],
        'enabled' => false,
    ])->assertRedirect();

    $subscription->refresh();

    expect($subscription->url)->toBe('https://hooks.example.com/updated');
    expect($subscription->secret)->toBe('pitchbar-secret-1234');
    expect($subscription->enabled)->toBeFalse();
});

test('storeSlack with send_test calls SlackPusher once', function () {
    ['user' => $user] = makeSlackUser();

    $mock = Mockery::mock(SlackPusher::class);
    $mock->shouldReceive('pushLead')->once()->andReturn(true);
    app()->instance(SlackPusher::class, $mock);

    $this->actingAs($user)->post('/app/integrations/slack', [
        'webhook_url' => 'https://hooks.slack.com/services/T1/B2/secret',
        'send_test' => true,
    ])->assertRedirect();
});

test('viewer cannot configure Slack', function () {
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

    $this->actingAs($viewer)
        ->postJson('/app/integrations/slack', [
            'webhook_url' => 'https://hooks.slack.com/services/T1/B2/x',
        ])
        ->assertForbidden();
});

test('viewer cannot configure outbound webhooks', function () {
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

    $this->actingAs($viewer)
        ->postJson('/app/integrations/webhooks', [
            'url' => 'https://hooks.example.com/pitchbar',
            'secret' => 'pitchbar-secret-1234',
            'events' => ['lead.captured'],
            'enabled' => true,
        ])
        ->assertForbidden();
});

test('destroy removes the integration', function () {
    ['user' => $user, 'workspace' => $workspace] = makeSlackUser();
    $row = IntegrationConnection::create([
        'workspace_id' => $workspace->id,
        'kind' => 'slack',
        'credentials_encrypted' => ['webhook_url' => 'https://hooks.slack.com/services/T1/B2/x'],
        'status' => 'active',
    ]);

    $this->actingAs($user)->delete("/app/integrations/{$row->id}")->assertRedirect();

    expect(IntegrationConnection::query()->where('workspace_id', $workspace->id)->count())->toBe(0);
});

test('destroyWebhook removes the subscription', function () {
    ['user' => $user, 'workspace' => $workspace] = makeSlackUser();

    $subscription = WebhookSubscription::create([
        'workspace_id' => $workspace->id,
        'url' => 'https://hooks.example.com/pitchbar',
        'secret' => 'pitchbar-secret-1234',
        'events' => ['lead.captured'],
        'enabled' => true,
    ]);

    $this->actingAs($user)
        ->delete("/app/integrations/webhooks/{$subscription->id}")
        ->assertRedirect();

    expect(WebhookSubscription::query()->where('workspace_id', $workspace->id)->count())->toBe(0);
});

test('RouteLeadJob pushes to Slack when a workspace has a Slack integration', function () {
    ['workspace' => $workspace] = makeSlackUser();
    $agent = Agent::factory()->create(['workspace_id' => $workspace->id]);
    IntegrationConnection::create([
        'workspace_id' => $workspace->id,
        'kind' => 'slack',
        'credentials_encrypted' => ['webhook_url' => 'https://hooks.slack.com/services/T1/B2/x'],
        'status' => 'active',
    ]);

    $visitor = Visitor::factory()->create(['agent_id' => $agent->id]);
    $conversation = Conversation::factory()->create([
        'agent_id' => $agent->id,
        'visitor_id' => $visitor->id,
    ]);
    $lead = Lead::create([
        'conversation_id' => $conversation->id,
        'agent_id' => $agent->id,
        'email' => 'visitor@example.com',
        'status' => 'new',
        'fields' => [],
    ]);

    $slack = Mockery::mock(SlackPusher::class);
    $slack->shouldReceive('pushLead')->once()
        ->withArgs(fn (string $url, Lead $sent) => $url === 'https://hooks.slack.com/services/T1/B2/x'
            && $sent->id === $lead->id)
        ->andReturn(true);

    (new RouteLeadJob($lead->id))->handle(
        app(SignedDispatcher::class),
        $slack
    );
});

test('RouteLeadJob is a no-op for workspaces without Slack', function () {
    ['workspace' => $workspace] = makeSlackUser();
    $agent = Agent::factory()->create(['workspace_id' => $workspace->id]);

    $visitor = Visitor::factory()->create(['agent_id' => $agent->id]);
    $conversation = Conversation::factory()->create([
        'agent_id' => $agent->id,
        'visitor_id' => $visitor->id,
    ]);
    $lead = Lead::create([
        'conversation_id' => $conversation->id,
        'agent_id' => $agent->id,
        'email' => 'visitor@example.com',
        'status' => 'new',
        'fields' => [],
    ]);

    $slack = Mockery::mock(SlackPusher::class);
    $slack->shouldNotReceive('pushLead');

    (new RouteLeadJob($lead->id))->handle(
        app(SignedDispatcher::class),
        $slack
    );
});

test('RouteLeadJob pushes to configured outbound webhooks', function () {
    ['workspace' => $workspace] = makeSlackUser();
    $agent = Agent::factory()->create(['workspace_id' => $workspace->id]);

    WebhookSubscription::create([
        'workspace_id' => $workspace->id,
        'url' => 'https://hooks.example.com/pitchbar',
        'secret' => 'pitchbar-secret-1234',
        'events' => ['lead.captured'],
        'enabled' => true,
    ]);

    $visitor = Visitor::factory()->create(['agent_id' => $agent->id]);
    $conversation = Conversation::factory()->create([
        'agent_id' => $agent->id,
        'visitor_id' => $visitor->id,
    ]);
    $lead = Lead::create([
        'conversation_id' => $conversation->id,
        'agent_id' => $agent->id,
        'email' => 'visitor@example.com',
        'status' => 'new',
        'fields' => [],
    ]);

    $dispatcher = Mockery::mock(SignedDispatcher::class);
    $dispatcher->shouldReceive('send')->once()
        ->withArgs(fn (string $url, string $secret, array $payload) => $url === 'https://hooks.example.com/pitchbar'
            && $secret === 'pitchbar-secret-1234'
            && data_get($payload, 'event') === 'lead.captured'
            && data_get($payload, 'lead.id') === $lead->id
            && data_get($payload, 'agent_id') === $agent->id)
        ->andReturn(true);

    $slack = Mockery::mock(SlackPusher::class);
    $slack->shouldNotReceive('pushLead');

    (new RouteLeadJob($lead->id))->handle($dispatcher, $slack);
});
