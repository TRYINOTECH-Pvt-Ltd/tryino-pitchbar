<?php

namespace App\Models;

use App\Concerns\BelongsToWorkspace;
use App\Concerns\HasUuidV7;
use Database\Factories\WorkspaceApiTokenFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $workspace_id
 * @property string|null $created_by_user_id
 * @property string $name
 * @property string $token_hash
 * @property array<int, string> $abilities
 * @property Carbon|null $last_used_at
 * @property Carbon|null $revoked_at
 */
class WorkspaceApiToken extends Model
{
    /** @use HasFactory<WorkspaceApiTokenFactory> */
    use BelongsToWorkspace;

    use HasFactory;
    use HasUuidV7;

    protected $fillable = [
        'workspace_id',
        'created_by_user_id',
        'name',
        'token_hash',
        'abilities',
        'last_used_at',
        'revoked_at',
        'shopper_signing_secret',
    ];

    protected $casts = [
        'abilities' => 'array',
        'last_used_at' => 'datetime',
        'revoked_at' => 'datetime',
        'shopper_signing_secret' => 'encrypted',
    ];

    protected $hidden = [
        'token_hash',
        'shopper_signing_secret',
    ];

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('revoked_at');
    }

    public function hasAbility(string $ability): bool
    {
        return in_array($ability, $this->abilities ?? [], true);
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }
}
