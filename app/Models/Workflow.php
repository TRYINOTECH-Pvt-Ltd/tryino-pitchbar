<?php

namespace App\Models;

use App\Concerns\BelongsToWorkspace;
use App\Concerns\HasUuidV7;
use Database\Factories\WorkflowFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Workflow row. Phase 1 shipped:
 *   - one trigger (on_keyword)
 *   - linear sequence of steps
 *   - three step types: message | question | escalate
 *
 * Status:
 *   draft    — admin is editing; runtime ignores.
 *   active   — runtime can match against this row.
 *   disabled — never matched but kept for history.
 *
 * The `definition` shape is forwards-compatible with Phase 2's
 * branching nodes — the runtime skips step types it does not know.
 *
 * @property string $id
 * @property string $workspace_id
 * @property ?string $agent_id
 * @property string $name
 * @property string $status
 * @property string $trigger_kind
 * @property ?array<string, mixed> $trigger_config
 * @property array<string, mixed> $definition
 */
class Workflow extends Model
{
    use BelongsToWorkspace;

    /** @use HasFactory<WorkflowFactory> */
    use HasFactory;

    use HasUuidV7;

    protected $fillable = [
        'workspace_id', 'agent_id', 'name', 'status', 'priority',
        'trigger_kind', 'trigger_config', 'definition',
        'created_by_user_id',
    ];

    protected $casts = [
        'trigger_config' => 'array',
        'definition' => 'array',
        'priority' => 'integer',
    ];

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    public function runs(): HasMany
    {
        return $this->hasMany(WorkflowRun::class);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function steps(): array
    {
        return (array) ($this->definition['steps'] ?? []);
    }

    /**
     * @return array<int, string>
     */
    public function keywords(): array
    {
        return array_values(array_filter(
            array_map(static fn ($v) => is_string($v) ? trim($v) : '', (array) ($this->trigger_config['keywords'] ?? [])),
            static fn (string $v): bool => $v !== '',
        ));
    }
}
