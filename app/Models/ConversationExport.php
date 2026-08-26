<?php

namespace App\Models;

use App\Concerns\BelongsToWorkspace;
use App\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConversationExport extends Model
{
    use BelongsToWorkspace;
    use HasFactory;
    use HasUuidV7;

    public const STATUS_PENDING = 'pending';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_READY = 'ready';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'workspace_id', 'requested_by_user_id', 'format', 'filters',
        'status', 'file_path', 'file_size', 'row_count', 'error',
        'expires_at', 'completed_at',
    ];

    protected $casts = [
        'filters' => 'array',
        'file_size' => 'integer',
        'row_count' => 'integer',
        'expires_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }
}
