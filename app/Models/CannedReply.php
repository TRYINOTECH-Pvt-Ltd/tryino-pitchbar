<?php

namespace App\Models;

use App\Concerns\BelongsToWorkspace;
use App\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Reusable reply snippets workspace operators paste into the live-chat
 * console. Picker shows them ordered by `position`, alpha by label
 * within the same position.
 *
 * Tenant-scoped via {@see BelongsToWorkspace} — every read/write is
 * automatically filtered by the current workspace, no manual where()
 * clauses needed.
 */
class CannedReply extends Model
{
    use BelongsToWorkspace;
    use HasUuidV7;

    protected $fillable = [
        'workspace_id', 'label', 'content', 'position', 'created_by',
    ];

    protected $casts = [
        'position' => 'integer',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
