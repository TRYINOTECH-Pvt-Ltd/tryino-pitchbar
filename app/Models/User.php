<?php

namespace App\Models;

use App\Enums\PlatformRole;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Fortify\TwoFactorAuthenticatable;

// `byok_enabled` and `default_workspace_id` are NOT in Fillable —
// both are privilege-bearing fields. BYOK access overrides global
// platform policy; default_workspace_id changes which tenant the
// user sees on next request. They flip ONLY through dedicated
// controllers using `forceFill` after explicit authorisation
// (Admin\Platform\UserController::updateByok for byok, the
// workspace-switch controllers for the default). Keeping them
// fillable would let any future `->fill($request->all())` slip a
// silent privilege change past validation.
#[Fillable(['name', 'email', 'password', 'avatar_url', 'live_chat_available', 'last_active_at', 'locale'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, SoftDeletes, TwoFactorAuthenticatable;

    /**
     * Email-verification gating is controlled by the platform-level
     * AppSetting toggle (super-admin: /settings/system, mail tab).
     * When OFF (default for fresh installs), every authenticated user
     * is treated as verified — Fortify's `verified` middleware
     * stops redirecting to /email/verify, and existing users with
     * email_verified_at = null can sign in without a verify step.
     * When ON, fall through to Laravel's default check so the
     * verified middleware + Fortify gate work normally.
     *
     * Boolean cast safety: AppSetting cast coerces null|0|''|false →
     * false, so legacy rows with NULL never accidentally turn the
     * gate on.
     */
    public function hasVerifiedEmail(): bool
    {
        try {
            $required = (bool) (AppSetting::singleton()->require_email_verification ?? false);
        } catch (\Throwable) {
            $required = false;
        }

        if (! $required) {
            return true;
        }

        return $this->email_verified_at !== null;
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
            'last_changelog_seen_at' => 'datetime',
            'last_active_at' => 'datetime',
            'live_chat_available' => 'boolean',
            'role' => PlatformRole::class,
            // C1: tri-state override. NULL = inherit global flag,
            // TRUE = force-enable BYOK, FALSE = explicit deny.
            'byok_enabled' => 'boolean',
        ];
    }

    public function isSuperAdmin(): bool
    {
        return $this->role === PlatformRole::SuperAdmin;
    }

    public function defaultWorkspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class, 'default_workspace_id');
    }

    public function workspaces(): BelongsToMany
    {
        return $this->belongsToMany(Workspace::class, 'workspace_users')
            ->withPivot('role', 'invited_at', 'accepted_at')
            ->withTimestamps();
    }

    public function ownedWorkspaces(): HasMany
    {
        return $this->hasMany(Workspace::class, 'owner_user_id');
    }
}
