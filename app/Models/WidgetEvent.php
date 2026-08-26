<?php

namespace App\Models;

use Database\Factories\WidgetEventFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A single widget-reliability failure/anomaly, backing the super-admin
 * Widget Monitor dashboard. See the create_widget_events_table migration.
 *
 * TENANCY NOTE (rule #2 deliberate bypass): this model does NOT use
 * `BelongsToWorkspace`. It is a platform observability table read ONLY by
 * super-admins, who need the cross-tenant feed to answer "is the widget
 * breaking anywhere?". A workspace global scope would hide every other
 * tenant's failures from the operator and defeat the dashboard's purpose.
 * The `workspace_id` column is carried for FILTERING, not isolation; every
 * read route is gated behind the `super_admin` middleware.
 *
 * @property int $id
 * @property string|null $workspace_id
 * @property string|null $agent_id
 * @property string|null $conversation_id
 * @property string $type
 * @property string $severity
 * @property string|null $provider
 * @property string|null $message
 * @property array<string, mixed>|null $context
 * @property Carbon $occurred_at
 * @property Carbon|null $resolved_at
 * @property int|null $resolved_by
 */
class WidgetEvent extends Model
{
    /** @use HasFactory<WidgetEventFactory> */
    use HasFactory;

    /** Severity levels, most → least urgent. */
    public const SEVERITY_ERROR = 'error';

    public const SEVERITY_WARNING = 'warning';

    public const SEVERITY_INFO = 'info';

    protected $fillable = [
        'workspace_id',
        'agent_id',
        'conversation_id',
        'type',
        'severity',
        'provider',
        'message',
        'context',
        'occurred_at',
        'resolved_at',
        'resolved_by',
    ];

    protected $casts = [
        'context' => 'array',
        'occurred_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];
}
