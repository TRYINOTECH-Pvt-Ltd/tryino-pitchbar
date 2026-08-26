<?php

use App\Models\AppSetting;
use App\Models\Workspace;
use App\Support\ByokResolver;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * Hard isolation guarantees for BYOK keys:
 *
 *   1. Keys are encrypted at rest (workspaces.byok_keys is an
 *      `encrypted:array` cast). The raw DB column is unreadable
 *      without APP_KEY.
 *   2. Workspace A's keys are NEVER returned for workspace B's
 *      resolver call.
 *   3. Updating workspace A's keys does NOT mutate workspace B.
 *   4. Clearing one provider on workspace A does NOT touch
 *      workspace B.
 *   5. ByokResolver::keysFor requires the caller to pass a Workspace
 *      model — there's no global lookup that could leak the wrong
 *      tenant.
 *
 * These tests pin the security boundary that lets multiple paying
 * customers store their own Cloudflare / OpenAI / Qdrant credentials
 * inside the same database without crossing.
 */
beforeEach(function () {
    AppSetting::singleton()->forceFill(['byok_enabled_globally' => true])->save();
});

test('workspace.byok_keys is encrypted at rest in the database', function () {
    $workspace = Workspace::factory()->create();
    $workspace->byok_keys = [
        'cloudflare_api_token' => 'cfat_supersecret_abc123',
    ];
    $workspace->save();

    // Read the raw DB column, bypassing the Eloquent cast. If
    // encryption is wired correctly we should NOT see the plaintext
    // token — we should see the Laravel Crypt envelope (a base64
    // blob starting with "eyJ...").
    $raw = (string) DB::table('workspaces')
        ->where('id', $workspace->id)
        ->value('byok_keys');

    expect($raw)
        ->not->toContain('cfat_supersecret_abc123')
        ->not->toBe('');

    // Round-trip via Crypt::decryptString must reveal the JSON map.
    $decrypted = json_decode(Crypt::decryptString($raw), true);
    expect($decrypted)->toHaveKey('cloudflare_api_token');
    expect($decrypted['cloudflare_api_token'])->toBe('cfat_supersecret_abc123');
});

test('keysFor(workspace A) never sees workspace B keys', function () {
    $a = Workspace::factory()->create();
    $a->byok_keys = ['cloudflare_api_token' => 'cfat_A_only'];
    $a->save();

    $b = Workspace::factory()->create();
    $b->byok_keys = ['cloudflare_api_token' => 'cfat_B_only'];
    $b->save();

    $resolver = app(ByokResolver::class);
    expect($resolver->keysFor($a->fresh()))->toMatchArray(['cloudflare_api_token' => 'cfat_A_only']);
    expect($resolver->keysFor($b->fresh()))->toMatchArray(['cloudflare_api_token' => 'cfat_B_only']);

    // Strict negative: A's resolved keys cannot contain B's secret.
    expect($resolver->keysFor($a->fresh())['cloudflare_api_token'])->not->toBe('cfat_B_only');
});

test('updating workspace A keys leaves workspace B untouched', function () {
    $a = Workspace::factory()->create();
    $a->byok_keys = ['cloudflare_api_token' => 'cfat_A_old'];
    $a->save();

    $b = Workspace::factory()->create();
    $b->byok_keys = ['cloudflare_api_token' => 'cfat_B_initial'];
    $b->save();

    // Mutate A.
    $a->byok_keys = ['cloudflare_api_token' => 'cfat_A_new'];
    $a->save();

    // Both rows independently retain their own values — no shared
    // state on the encrypted cast, no global mutation.
    expect($a->fresh()->byok_keys)->toMatchArray(['cloudflare_api_token' => 'cfat_A_new']);
    expect($b->fresh()->byok_keys)->toMatchArray(['cloudflare_api_token' => 'cfat_B_initial']);
});

test('clearing one workspace via the API does not touch another workspace', function () {
    ['user' => $userA, 'workspace' => $wsA] = workspaceMember();
    $wsA->byok_keys = ['cloudflare_api_token' => 'cfat_A_keep'];
    $wsA->save();

    $wsB = Workspace::factory()->create();
    $wsB->byok_keys = ['cloudflare_api_token' => 'cfat_B_keep'];
    $wsB->save();

    // User A clears their CF keys. Their own row is wiped of CF.
    $this->actingAs($userA)
        ->delete('/settings/byok-keys/cloudflare')
        ->assertRedirect();

    expect($wsA->fresh()->byok_keys ?? [])->toBe([]);
    // Workspace B remains intact — the request had no agency to
    // mutate it (no workspace_id in the request body; the controller
    // resolves CurrentWorkspace from the auth session).
    expect($wsB->fresh()->byok_keys)->toMatchArray(['cloudflare_api_token' => 'cfat_B_keep']);
});

test('keysFor() returns null when no keys configured (no fallback bleed)', function () {
    $empty = Workspace::factory()->create();

    expect(app(ByokResolver::class)->keysFor($empty))->toBeNull();
});
