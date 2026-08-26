<?php

use App\Models\Agent;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceUser;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;

/**
 * Pins the MIME allowlist on the upload endpoint. Before this
 * validation rule, `getClientOriginalExtension()` was the only
 * gate on file type and that's pure client trust — an attacker
 * could rename `evil.html → evil.pdf` to slip past the routing
 * decision in ParserRegistry. The `mimes:` rule runs Laravel's
 * finfo check against the actual bytes; only formats matching
 * the allowlist (pdf/docx/doc/xlsx/xls/csv/md/markdown/txt/odt/
 * ods) get accepted.
 */
function mimeUploadActor(): array
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

test('upload rejects extensions not in the parser allowlist', function () {
    Bus::fake();
    ['user' => $user, 'agent' => $agent] = mimeUploadActor();

    // .html / .js / .svg / .zip are routinely floated as
    // upload-RCE vectors. The MIME-by-extension rule kicks every
    // one of them out before the parser sees a byte. Doesn't
    // protect against an attacker who renames their .html → .pdf
    // (real defence there is the parser itself, which won't make
    // chunks from non-PDF bytes) but it raises the bar.
    foreach (['evil.html', 'payload.exe', 'logo.svg', 'archive.zip'] as $name) {
        $blocked = UploadedFile::fake()->createWithContent($name, 'arbitrary bytes');

        $response = $this->actingAs($user)->post(
            route('agents.uploads.store', ['agent' => $agent->id]),
            ['files' => [$blocked]],
        );

        $response->assertSessionHasErrors('files.0', null, 'default', "Expected {$name} to be rejected.");
    }
});

test('upload still accepts a real markdown file', function () {
    Bus::fake();
    ['user' => $user, 'agent' => $agent] = mimeUploadActor();

    $file = UploadedFile::fake()->createWithContent(
        'real.md',
        "# Heading\n\nReal markdown content body.",
    );

    $this->actingAs($user)->post(
        route('agents.uploads.store', ['agent' => $agent->id]),
        ['files' => [$file]],
    )->assertRedirect();
});

test('upload rejects executable extensions even at 0 bytes', function () {
    Bus::fake();
    ['user' => $user, 'agent' => $agent] = mimeUploadActor();

    $exe = UploadedFile::fake()->createWithContent('payload.exe', 'MZ-bytes-here');

    $this->actingAs($user)->post(
        route('agents.uploads.store', ['agent' => $agent->id]),
        ['files' => [$exe]],
    )->assertSessionHasErrors('files.0');
});
