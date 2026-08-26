<?php

use App\Enums\PlatformRole;
use App\Models\PlanSubscription;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceUser;
use Database\Seeders\PlanSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Support\Facades\Hash;

test('UserSeeder creates admin and customer with workspaces and free subscriptions', function () {
    $this->seed(PlanSeeder::class);
    $this->seed(UserSeeder::class);

    $admin = User::where('email', 'admin@mail.com')->firstOrFail();
    expect($admin->name)->toBe('Platform Admin');
    expect($admin->isSuperAdmin())->toBeTrue();
    expect(Hash::check('password', $admin->password))->toBeTrue();
    expect($admin->default_workspace_id)->not->toBeNull();

    $customer = User::where('email', 'customer@mail.com')->firstOrFail();
    expect($customer->isSuperAdmin())->toBeFalse();
    expect($customer->role)->toBe(PlatformRole::Customer);
    expect(Hash::check('password', $customer->password))->toBeTrue();
    expect($customer->default_workspace_id)->not->toBeNull();

    // Each gets a workspace, owner membership, and a free subscription
    expect(Workspace::query()->withoutGlobalScopes()->count())->toBe(2);
    expect(WorkspaceUser::where('user_id', $admin->id)->where('role', 'owner')->exists())->toBeTrue();
    expect(WorkspaceUser::where('user_id', $customer->id)->where('role', 'owner')->exists())->toBeTrue();
    expect(PlanSubscription::query()->withoutGlobalScopes()->count())->toBe(2);
});

test('UserSeeder is idempotent — re-running does not create duplicates', function () {
    $this->seed(PlanSeeder::class);
    $this->seed(UserSeeder::class);
    $this->seed(UserSeeder::class);

    expect(User::count())->toBe(2);
    expect(Workspace::query()->withoutGlobalScopes()->count())->toBe(2);
});

test('seeded admin can sign in with admin@mail.com / password and reach /admin', function () {
    $this->seed(PlanSeeder::class);
    $this->seed(UserSeeder::class);

    $this->post('/login', [
        'email' => 'admin@mail.com',
        'password' => 'password',
    ])->assertRedirect();

    $this->get('/admin')->assertOk();
});

test('seeded customer cannot reach /admin (404)', function () {
    $this->seed(PlanSeeder::class);
    $this->seed(UserSeeder::class);

    $this->post('/login', [
        'email' => 'customer@mail.com',
        'password' => 'password',
    ])->assertRedirect();

    $this->get('/admin')->assertNotFound();
});
