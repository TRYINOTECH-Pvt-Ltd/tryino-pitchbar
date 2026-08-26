<?php

use App\Models\WebhookSubscription;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

test('rotate command re-encrypts every encrypted column', function () {
    ['workspace' => $workspace] = workspaceMember(['role' => 'admin']);

    $sub = WebhookSubscription::create([
        'workspace_id' => $workspace->id,
        'url' => 'https://example.test/hook',
        'secret' => 'whsec_initial',
        'events' => ['lead.captured'],
        'enabled' => true,
    ]);

    $beforeRaw = DB::table('webhook_subscriptions')->where('id', $sub->id)->value('secret');
    expect(Crypt::decryptString($beforeRaw))->toBe('whsec_initial');

    $this->artisan('security:rotate-app-key')->assertOk();

    $afterRaw = DB::table('webhook_subscriptions')->where('id', $sub->id)->value('secret');
    expect($afterRaw)->not->toBe($beforeRaw);
    expect(Crypt::decryptString($afterRaw))->toBe('whsec_initial');
});

test('rotate refuses in production without flag', function () {
    app()->detectEnvironment(fn () => 'production');

    $this->artisan('security:rotate-app-key')
        ->expectsOutput('Refusing to rotate in production without --confirm-production.')
        ->assertFailed();
});

test('dry-run does not change ciphertext', function () {
    ['workspace' => $workspace] = workspaceMember(['role' => 'admin']);
    $sub = WebhookSubscription::create([
        'workspace_id' => $workspace->id,
        'url' => 'https://example.test/hook',
        'secret' => 'whsec_keep',
        'events' => ['lead.captured'],
        'enabled' => true,
    ]);

    $before = DB::table('webhook_subscriptions')->where('id', $sub->id)->value('secret');

    $this->artisan('security:rotate-app-key', ['--dry-run' => true])->assertOk();

    $after = DB::table('webhook_subscriptions')->where('id', $sub->id)->value('secret');
    expect($after)->toBe($before);
});

test('rotate is idempotent', function () {
    ['workspace' => $workspace] = workspaceMember(['role' => 'admin']);
    WebhookSubscription::create([
        'workspace_id' => $workspace->id,
        'url' => 'https://example.test/hook',
        'secret' => 'whsec_idempotent',
        'events' => ['lead.captured'],
        'enabled' => true,
    ]);

    $this->artisan('security:rotate-app-key')->assertOk();
    $this->artisan('security:rotate-app-key')->assertOk();

    $raw = DB::table('webhook_subscriptions')->value('secret');
    expect(Crypt::decryptString($raw))->toBe('whsec_idempotent');
});
