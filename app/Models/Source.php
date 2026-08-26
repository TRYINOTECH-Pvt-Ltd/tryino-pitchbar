<?php

namespace App\Models;

use App\Concerns\BelongsToAgent;
use App\Concerns\HasUuidV7;
use App\Observers\SourceObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Cache;

#[ObservedBy(SourceObserver::class)]
class Source extends Model
{
    use BelongsToAgent;
    use HasFactory;
    use HasUuidV7;

    /**
     * How long the "does this agent have any global sources?" answer is
     * cached. The gate keeps the hot path free for agents that never use
     * the feature — see Retriever's global-source handling.
     */
    private const HAS_GLOBAL_TTL_SECONDS = 600;

    protected $fillable = [
        'agent_id', 'type', 'status', 'config', 'credentials_encrypted',
        'last_synced_at', 'error', 'is_global',
    ];

    protected $casts = [
        'config' => 'array',
        'credentials_encrypted' => 'encrypted:array',
        'last_synced_at' => 'datetime',
        'is_global' => 'boolean',
    ];

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }

    /**
     * Whether this agent has ANY source flagged "answer on every page".
     * Cached so the hot-path retrieve() can skip the global-source join
     * entirely for the common case (no global sources). Bumping the
     * version (see forgetGlobalCache) also expires this key's cohort.
     *
     * Scope bypass safe: filtered by agent_id, which is the tenancy key —
     * the retrieve() caller is already agent-scoped.
     */
    public static function hasGlobalFor(string $agentId): bool
    {
        return (bool) Cache::remember(
            'src:hasglobal:'.$agentId,
            self::HAS_GLOBAL_TTL_SECONDS,
            fn (): bool => self::query()->withoutGlobalScopes()
                ->where('agent_id', $agentId)
                ->where('is_global', true)
                ->exists(),
        );
    }

    /**
     * A monotonically-increasing marker for the agent's global-source
     * configuration. Folded into the retrieve() cache key ONLY when the
     * agent has global sources, so toggling "answer on every page"
     * invalidates stale cached answers (e.g. a cached "I can't confirm"
     * for the homepage address) instantly, while agents that never touch
     * the feature keep the exact legacy cache hash.
     */
    public static function globalVersionFor(string $agentId): int
    {
        return (int) Cache::get('src:globalver:'.$agentId, 0);
    }

    /**
     * Invalidate both global-source caches for an agent. Called when a
     * source's is_global flag flips.
     */
    public static function forgetGlobalCache(string $agentId): void
    {
        Cache::forget('src:hasglobal:'.$agentId);
        // Read-then-put instead of increment(): increment on a missing key
        // is a no-op on some cache drivers, which would skip the bump.
        Cache::forever('src:globalver:'.$agentId, self::globalVersionFor($agentId) + 1);
    }
}
