<?php

use App\Events\Conversations\HumanRequestedEvent;
use App\Listeners\LiveChat\LiveChatNotifier;
use App\Models\Agent;
use App\Models\Conversation;
use App\Models\Lead;
use App\Models\Visitor;
use App\Models\Workspace;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

function notifierFactory(array $workspaceOverrides = []): array
{
    $workspace = Workspace::factory()->create($workspaceOverrides);
    $agent = Agent::factory()->create([
        'workspace_id' => $workspace->id,
        'name' => 'Pitchbar Demo',
    ]);
    $visitor = Visitor::factory()->create(['agent_id' => $agent->id]);
    $conv = Conversation::factory()->create([
        'agent_id' => $agent->id,
        'visitor_id' => $visitor->id,
        'page_url' => 'https://shop.example.com/products/red-pen',
    ]);

    return ['workspace' => $workspace, 'agent' => $agent, 'conv' => $conv];
}

test('does nothing when no webhooks are configured', function () {
    Http::fake();
    ['conv' => $conv, 'agent' => $agent] = notifierFactory();

    (new LiveChatNotifier)->handle(new HumanRequestedEvent($conv->id, $agent->id));

    Http::assertNothingSent();
});

test('posts to slack when slack_webhook_url is set', function () {
    Http::fake();
    ['conv' => $conv, 'agent' => $agent] = notifierFactory([
        'slack_webhook_url' => 'https://hooks.slack.com/services/T0/B0/abc',
    ]);

    (new LiveChatNotifier)->handle(new HumanRequestedEvent($conv->id, $agent->id));

    Http::assertSent(function ($request) use ($conv) {
        return $request->url() === 'https://hooks.slack.com/services/T0/B0/abc'
            && str_contains((string) $request['text'], 'Pitchbar Demo')
            && str_contains((string) $request['text'], $conv->id);
    });
});

test('posts to teams as a MessageCard when teams_webhook_url is set', function () {
    Http::fake();
    ['conv' => $conv, 'agent' => $agent] = notifierFactory([
        'teams_webhook_url' => 'https://outlook.office.com/webhook/abc',
    ]);

    (new LiveChatNotifier)->handle(new HumanRequestedEvent($conv->id, $agent->id));

    Http::assertSent(function ($request) {
        $body = $request->data();

        return $request->url() === 'https://outlook.office.com/webhook/abc'
            && ($body['@type'] ?? null) === 'MessageCard'
            && is_array($body['potentialAction'] ?? null);
    });
});

test('includes the captured email in the payload when a Lead exists', function () {
    Http::fake();
    ['workspace' => $workspace, 'conv' => $conv, 'agent' => $agent] = notifierFactory([
        'slack_webhook_url' => 'https://hooks.slack.com/services/X/Y/Z',
    ]);
    Lead::create([
        'id' => (string) Str::uuid7(),
        'workspace_id' => $workspace->id,
        'agent_id' => $agent->id,
        'conversation_id' => $conv->id,
        'email' => 'visitor@example.com',
        'captured_at' => now(),
    ]);

    (new LiveChatNotifier)->handle(new HumanRequestedEvent($conv->id, $agent->id));

    Http::assertSent(function ($request) {
        return str_contains((string) $request['text'], 'visitor@example.com');
    });
});

test('webhook failures are swallowed and never throw', function () {
    Http::fake([
        'hooks.slack.com/*' => Http::response('boom', 500),
    ]);
    ['conv' => $conv, 'agent' => $agent] = notifierFactory([
        'slack_webhook_url' => 'https://hooks.slack.com/services/X/Y/Z',
    ]);

    // Should not throw.
    (new LiveChatNotifier)->handle(new HumanRequestedEvent($conv->id, $agent->id));

    Http::assertSentCount(1);
});
