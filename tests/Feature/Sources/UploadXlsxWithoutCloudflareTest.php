<?php

use App\Models\Agent;
use App\Models\Source;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceUser;
use App\Services\Cloudflare\ToMarkdownClient;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;

/**
 * Regression for the buyer report: an installation without Cloudflare
 * Workers AI bound (the toMarkdown endpoint provides our xlsx/ods
 * parser) used to accept a .xlsx upload at the MIME layer, then drop
 * it silently because the parser registry returned null. The owner saw
 * a Source row appear in 'failed' status with the generic "Unsupported
 * file type" message and no hint about Cloudflare being the missing
 * piece.
 *
 * After the fix:
 * - Frontend accept attr still lets the file picker include xlsx (so
 *   the buyer can attempt the upload on a CF-enabled tenant).
 * - Backend response includes an actionable hint that Cloudflare creds
 *   are required for spreadsheet parsing.
 */
function xlsxUploadActor(): array
{
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();
    WorkspaceUser::create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'role' => 'admin',
        'invited_at' => now(),
        'accepted_at' => now(),
    ]);
    $user->forceFill(['default_workspace_id' => $workspace->id])->save();
    $agent = Agent::factory()->create(['workspace_id' => $workspace->id]);

    return ['user' => $user, 'agent' => $agent];
}

test('xlsx upload without Cloudflare creds returns the actionable CF hint', function () {
    Bus::fake();
    // Force the parser registry to skip Cloudflare (production fallback
    // path for BYOK-OpenAI installs).
    app()->forgetInstance(ToMarkdownClient::class);
    app()->bind(ToMarkdownClient::class, fn () => null);

    ['user' => $user, 'agent' => $agent] = xlsxUploadActor();

    // Use a real-looking .xlsx ZIP signature so Laravel's `mimes:` check
    // (which runs finfo on bytes) accepts it through. The parser then
    // returns null because Cloudflare isn't bound.
    $bytes = "PK\x03\x04".str_repeat("\x00", 200);
    $file = UploadedFile::fake()->createWithContent('report.xlsx', $bytes);

    $response = $this->actingAs($user)->post(
        route('agents.uploads.store', ['agent' => $agent->id]),
        ['files' => [$file]],
    );

    $response->assertRedirect();
    $session = $response->getSession();

    // Either error (zero docs created) or warning copy must mention CF.
    $flash = (string) ($session->get('error') ?? $session->get('warning') ?? '');
    expect($flash)->toContain('Cloudflare');
});

test('Source row is still persisted even when every file is unsupported', function () {
    Bus::fake();
    app()->forgetInstance(ToMarkdownClient::class);
    app()->bind(ToMarkdownClient::class, fn () => null);

    ['user' => $user, 'agent' => $agent] = xlsxUploadActor();

    $bytes = "PK\x03\x04".str_repeat("\x00", 200);
    $file = UploadedFile::fake()->createWithContent('quarterly.xlsx', $bytes);

    $this->actingAs($user)->post(
        route('agents.uploads.store', ['agent' => $agent->id]),
        ['files' => [$file]],
    )->assertRedirect();

    $source = Source::query()->where('agent_id', $agent->id)->first();
    expect($source)->not->toBeNull();
    expect($source->status)->toBe('failed');
    expect($source->error)->toContain('Cloudflare');
});
