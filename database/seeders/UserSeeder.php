<?php

namespace Database\Seeders;

use App\Actions\Workspaces\CreateWorkspaceForUser;
use App\Enums\PlatformRole;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Seeds two known accounts for local dev / smoke testing.
 *
 *   admin@mail.com    / password  → super_admin (platform admin area)
 *   customer@mail.com / password  → customer    (regular workspace owner)
 *
 * Both records are upserted by email, so re-running this seeder is safe.
 */
class UserSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function () {
            // Super admin
            $admin = User::query()->updateOrCreate(
                ['email' => 'admin@mail.com'],
                [
                    'name' => 'Platform Admin',
                    'password' => Hash::make('password'),
                    'role' => PlatformRole::SuperAdmin->value,
                    'email_verified_at' => now(),
                ],
            );

            // Give the admin a workspace too, so they can flip between admin
            // view and a normal workspace view freely.
            if ($admin->wasRecentlyCreated || $admin->default_workspace_id === null) {
                app(CreateWorkspaceForUser::class)->handle($admin, "Platform Admin's Workspace");
            }

            // Customer
            $customer = User::query()->updateOrCreate(
                ['email' => 'customer@mail.com'],
                [
                    'name' => 'Demo Customer',
                    'password' => Hash::make('password'),
                    'role' => PlatformRole::Customer->value,
                    'email_verified_at' => now(),
                ],
            );

            if ($customer->wasRecentlyCreated || $customer->default_workspace_id === null) {
                app(CreateWorkspaceForUser::class)->handle($customer, "Demo Customer's Workspace");
            }

            $this->command?->info('  Seeded admin@mail.com (super_admin) + customer@mail.com (customer) — password "password"');
        });
    }
}
