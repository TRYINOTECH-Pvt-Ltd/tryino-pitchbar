<?php

namespace App\Models;

use App\Concerns\BelongsToWorkspace;
use App\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row per workflow execution against a conversation. The runtime
 * walks the workflow's steps array linearly and pauses at the first
 * `question` step until the visitor's next message satisfies it.
 *
 * @property string $id
 * @property string $workflow_id
 * @property string $conversation_id
 * @property string $workspace_id
 * @property string $status running | completed | failed | abandoned
 * @property int $current_step_index
 * @property ?array<string, mixed> $vars
 */
class WorkflowRun extends Model
{
    use BelongsToWorkspace;
    use HasUuidV7;

    protected $fillable = [
        'workflow_id', 'conversation_id', 'workspace_id',
        'status', 'current_step_index', 'vars',
        'started_at', 'finished_at',
    ];

    protected $casts = [
        'vars' => 'array',
        'current_step_index' => 'integer',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function workflow(): BelongsTo
    {
        return $this->belongsTo(Workflow::class);
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }
}
