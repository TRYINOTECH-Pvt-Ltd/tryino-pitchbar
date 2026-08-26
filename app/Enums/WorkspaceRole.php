<?php

namespace App\Enums;

enum WorkspaceRole: string
{
    case Owner = 'owner';
    case Admin = 'admin';
    case Editor = 'editor';
    case Viewer = 'viewer';

    public function canManageAgents(): bool
    {
        return in_array($this, [self::Owner, self::Admin, self::Editor], true);
    }

    public function canManageBilling(): bool
    {
        return $this === self::Owner;
    }

    public function canManageMembers(): bool
    {
        return in_array($this, [self::Owner, self::Admin], true);
    }

    public function canViewAnalytics(): bool
    {
        return true; // all roles can view
    }

    public function isAtLeast(self $other): bool
    {
        $rank = [
            self::Viewer->value => 1,
            self::Editor->value => 2,
            self::Admin->value => 3,
            self::Owner->value => 4,
        ];

        return $rank[$this->value] >= $rank[$other->value];
    }
}
