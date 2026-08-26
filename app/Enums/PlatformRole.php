<?php

namespace App\Enums;

/**
 * System-level role on `users.role`. Distinct from workspace-level roles
 * (owner/admin/editor/viewer) which live on the workspace_users pivot.
 */
enum PlatformRole: string
{
    case Customer = 'customer';
    case SuperAdmin = 'super_admin';

    public function isSuperAdmin(): bool
    {
        return $this === self::SuperAdmin;
    }
}
