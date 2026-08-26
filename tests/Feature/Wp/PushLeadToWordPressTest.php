<?php

use App\Events\Leads\LeadCapturedEvent;
use App\Listeners\Leads\PushLeadToWordPress;
use App\Models\Conversation;
use App\Models\Lead;
use App\Models\Source;
use App\Models\Visitor;
use App\Models\WorkspaceApiToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function seedLeadWith(string $sourceType): array
{
    ['workspace' => $workspace, 'agent' => $agent] = workspaceMemberWithAgent();
    WorkspaceApiToken::factory()->create([
        'workspace_id' => $workspace->id,
        'shopper_signing_secret' => Str::random(48),
    ]);
    Source::create([
        'agent_id' => $agent->id,
        'type' => $sourceType,
        'status' => 'indexed',
        'config' => ['site_url' => 'https://shop.example.com'],
        'last_synced_at' => now(),
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
        'name' => 'V Isitor',
        'phone' => '+15551234567',
        'fields' => ['vertical' => 'apparel'],
        'status' => 'new',
    ]);

    return ['workspace' => $workspace, 'lead' => $lead];
}

test('lead push fires HTTP POST to plugin REST when a WooCommerce source is attached', function () {
    Http::fake([
        'https://shop.example.com/wp-json/pitchbar/v1/leads' => Http::response([
            'data' => ['user_id' => 42],
        ], 200),
    ]);

    ['workspace' => $workspace, 'lead' => $lead] = seedLeadWith('woocommerce_products');

    $event = LeadCapturedEvent::fromLead($lead, (string) $workspace->id, 'Agent');
    (new PushLeadToWordPress)->handle($event);

    Http::assertSent(function ($request) {
        return str_contains($request->url(), '/wp-json/pitchbar/v1/leads')
            && $request->hasHeader('X-Pitchbar-Signature')
            && str_contains($request->body(), '"email":"visitor@example.com"')
            && str_contains($request->body(), '"name":"V Isitor"');
    });
});

test('lead push silently skips when no wordpress / woocommerce source is attached', function () {
    Http::fake();
    ['workspace' => $workspace, 'agent' => $agent] = workspaceMemberWithAgent();
    WorkspaceApiToken::factory()->create(['workspace_id' => $workspace->id]);
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

    $event = LeadCapturedEvent::fromLead($lead, (string) $workspace->id, 'Agent');
    (new PushLeadToWordPress)->handle($event);

    Http::assertNothingSent();
});

test('lead push swallows transport errors silently', function () {
    Http::fake([
        '*' => Http::response('boom', 500),
    ]);

    ['workspace' => $workspace, 'lead' => $lead] = seedLeadWith('wordpress');

    $event = LeadCapturedEvent::fromLead($lead, (string) $workspace->id, 'Agent');

    // No exception bubbles up.
    expect(fn () => (new PushLeadToWordPress)->handle($event))->not()->toThrow(Throwable::class);
});
