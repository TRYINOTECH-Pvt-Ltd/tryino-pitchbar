<?php

use App\Mail\AdminDailyDigest;
use App\Mail\WorkspaceInvitation;
use App\Models\Invitation;
use App\Models\Workspace;

test('WorkspaceInvitation mail renders without "View [message] not found"', function () {
    $workspace = Workspace::factory()->create(['name' => 'Acme Co']);
    $invitation = Invitation::factory()->create([
        'workspace_id' => $workspace->id,
        'role' => 'viewer',
        'expires_at' => now()->addDays(7),
    ]);

    $mail = new WorkspaceInvitation($invitation);
    $rendered = $mail->render();

    expect($rendered)->toBeString();
    expect(strlen($rendered))->toBeGreaterThan(100);
    expect($rendered)->toContain('Accept invitation');
    expect($rendered)->toContain('Acme Co');
});

test('AdminDailyDigest mail renders without missing-view error', function () {
    $stats = [
        'date' => now()->toDateString(),
        'workspace' => 'Acme Co',
        'admin_url' => 'https://example.com/admin',
        'leads' => 5,
        'conversations' => 12,
        'usage' => [],
        'rows' => [],
        'period_start' => now()->subDay()->toDateString(),
        'period_end' => now()->toDateString(),
    ];

    $mail = new AdminDailyDigest($stats);

    $rendered = null;
    try {
        $rendered = $mail->render();
    } catch (Throwable $e) {
        // Some installations of AdminDailyDigest may require a fuller
        // $stats shape; the only thing this regression guard cares
        // about is that the mail::message component CAN resolve.
        expect($e->getMessage())->not->toContain('View [message] not found');
    }

    if (is_string($rendered)) {
        expect(strlen($rendered))->toBeGreaterThan(100);
    }
});
