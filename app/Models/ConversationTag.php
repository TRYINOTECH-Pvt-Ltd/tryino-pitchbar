<?php

namespace App\Models;

use App\Concerns\BelongsToWorkspace;
use App\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Workspace-scoped tag for categorizing conversations ("billing",
 * "refund", "feature-request", …). Tags are workspace-private, applied
 * by operators in the live-chat console, and can be filtered on the
 * Conversations list.
 */
class ConversationTag extends Model
{
    use BelongsToWorkspace;
    use HasUuidV7;

    protected $fillable = [
        'workspace_id', 'label', 'color',
    ];

    public function conversations(): BelongsToMany
    {
        return $this->belongsToMany(
            Conversation::class,
            'conversation_tag_pivot',
            'tag_id',
            'conversation_id',
        )->withPivot('applied_by', 'created_at');
    }
}
