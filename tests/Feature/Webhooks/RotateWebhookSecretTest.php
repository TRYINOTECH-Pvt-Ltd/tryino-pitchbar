<?php

use App\Models\AuditLog;
use App\Models\WebhookSubscription;

function makeWebhookSub(string $workspaceId, string $secret = 'whsec_old'): WebhookSubscription
{
    return WebhookSubscription::create([
        'workspace_id' => $workspaceId,
        'url' => 'https://example.test/hook',
        'secret' => $secret,
        'events' => ['lead.captured'],
        'enabled' => true,
    ]);
}

test('admin can rotate webhook secret', function () {
    ['user' => $user, 'workspace' => $workspace] = workspaceMember(['role' => 'admin']);
    $sub = makeWebhookSub($workspace->id, 'whsec_old');

    $response = $this->actingAs($user)
        ->from('/app/integrations')
        ->post("/app/integrations/webhooks/{$sub->id}/rotate-secret");

    $response->assertRedirect('/app/integrations');
    $response->assertSessionHas('rotated_secret');
    $response->assertSessionHas('rotated_subscription_id', $sub->id);

    $newSecret = session('rotated_secret');
    expect($newSecret)->toStartWith('whsec_');
    expect($newSecret)->not->toBe('whsec_old');

    $reloaded = WebhookSubscription::query()->withoutWorkspaceScope()->find($sub->id);
    expect($reloaded->secret)->toBe($newSecret);

    expect(AuditLog::query()
        ->where('workspace_id', $workspace->id)
        ->where('action', 'integration.webhook_secret_rotated')
        ->count())->toBe(1);
});

test('editor cannot rotate secret', function () {
    ['user' => $user, 'workspace' => $workspace] = workspaceMember(['role' => 'editor']);
    $sub = makeWebhookSub($workspace->id);

    $this->actingAs($user)
        ->post("/app/integrations/webhooks/{$sub->id}/rotate-secret")
        ->assertForbidden();

    $reloaded = WebhookSubscription::query()->withoutWorkspaceScope()->find($sub->id);
    expect($reloaded->secret)->toBe('whsec_old');
});

test('cannot rotate webhook from another workspace', function () {
    ['user' => $user] = workspaceMember(['role' => 'admin']);
    $foreign = workspaceMember(['role' => 'admin']);
    $sub = makeWebhookSub($foreign['workspace']->id, 'whsec_foreign');

    $this->actingAs($user)
        ->post("/app/integrations/webhooks/{$sub->id}/rotate-secret")
        ->assertNotFound();

    $reloaded = WebhookSubscription::query()->withoutWorkspaceScope()->find($sub->id);
    expect($reloaded->secret)->toBe('whsec_foreign');
});
