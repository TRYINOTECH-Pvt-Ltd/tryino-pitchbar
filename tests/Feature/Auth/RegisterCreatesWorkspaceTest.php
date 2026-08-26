<?php

use App\Actions\Workspaces\CreateWorkspaceForUser;
use App\Models\PlanSubscription;
use App\Models\User;
use App\Models\Workspace;

test('registering a user also creates a default workspace, owner membership, and a free subscription', function () {
    $response = $this->post('/register', [
        'name' => 'Ada Lovelace',
        'email' => 'ada@example.com',
        'password' => 'password-1234',
        'password_confirmation' => 'password-1234',
    ]);

    $user = User::where('email', 'ada@example.com')->firstOrFail();
    expect($user->default_workspace_id)->not->toBeNull();

    $workspace = Workspace::query()
        ->withoutGlobalScopes()
        ->whereKey($user->default_workspace_id)
        ->firstOrFail();

    expect($workspace->owner_user_id)->toBe($user->id);
    expect($workspace->name)->toContain('Ada');

    expect($user->workspaces()->count())->toBe(1);
    expect($user->workspaces()->first()->pivot->role)->toBe('owner');

    $sub = PlanSubscription::query()
        ->withoutGlobalScopes()
        ->where('workspace_id', $workspace->id)
        ->firstOrFail();
    expect($sub->status)->toBe('active');

    $response->assertRedirect();
});

test('register transaction rolls back if anything in the workspace bootstrap fails', function () {
    // Force the workspace creation to fail by binding a broken action.
    $this->mock(CreateWorkspaceForUser::class, function ($mock) {
        $mock->shouldReceive('handle')->andThrow(new RuntimeException('boom'));
    });

    $this->withoutExceptionHandling();
    try {
        $this->post('/register', [
            'name' => 'Charles Babbage',
            'email' => 'charles@example.com',
            'password' => 'password-1234',
            'password_confirmation' => 'password-1234',
        ]);
    } catch (RuntimeException $e) {
        // expected — verify the transaction rolled back
    }

    expect(User::where('email', 'charles@example.com')->exists())->toBeFalse();
});
