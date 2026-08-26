<?php

namespace App\Models;

use App\Concerns\BelongsToWorkspace;
use App\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DsrRequest extends Model
{
    use BelongsToWorkspace;
    use HasFactory;
    use HasUuidV7;

    public const ACTION_EXPORT = 'export';

    public const ACTION_DELETE = 'delete';

    public const SOURCE_ADMIN = 'admin';

    public const SOURCE_SELF_SERVE = 'visitor_self_serve';

    public const STATUS_PENDING = 'pending';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_REJECTED = 'rejected';

    protected $fillable = [
        'workspace_id', 'action',
        'lookup_email', 'lookup_visitor_id', 'lookup_anonymous_id',
        'source', 'requested_by_user_id', 'status',
        'matched_visitor_ids', 'result_payload', 'completed_at',
    ];

    protected $casts = [
        'matched_visitor_ids' => 'array',
        'result_payload' => 'encrypted:array',
        'completed_at' => 'datetime',
    ];

    protected $hidden = [
        'result_payload',
    ];

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }
}
