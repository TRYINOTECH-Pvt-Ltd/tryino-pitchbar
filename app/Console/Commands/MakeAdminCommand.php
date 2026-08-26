<?php

namespace App\Console\Commands;

use App\Enums\PlatformRole;
use App\Models\User;
use Illuminate\Console\Command;

class MakeAdminCommand extends Command
{
    protected $signature = 'pitchbar:make-admin {email : Email of the user to promote} {--demote : Demote back to customer instead}';

    protected $description = 'Promote a user to super_admin (or demote with --demote)';

    public function handle(): int
    {
        $email = (string) $this->argument('email');
        $user = User::where('email', $email)->first();

        if ($user === null) {
            $this->error("No user with email {$email}");

            return self::FAILURE;
        }

        $newRole = $this->option('demote') ? PlatformRole::Customer : PlatformRole::SuperAdmin;

        if ($user->role === $newRole) {
            $this->info("{$email} is already {$newRole->value}");

            return self::SUCCESS;
        }

        $user->forceFill(['role' => $newRole->value])->save();
        $this->info("{$email} → {$newRole->value}");

        return self::SUCCESS;
    }
}
