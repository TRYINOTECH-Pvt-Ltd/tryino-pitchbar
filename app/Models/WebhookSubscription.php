<?php

namespace App\Models;

use App\Concerns\BelongsToWorkspace;
use App\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WebhookSubscription extends Model
{
    use BelongsToWorkspace;
    use HasFactory;
    use HasUuidV7;

    protected $fillable = [
        'workspace_id', 'url', 'secret', 'events', 'enabled',
    ];

    protected $casts = [
        'events' => 'array',
        'enabled' => 'boolean',
        'secret' => 'encrypted',
    ];

    protected $hidden = [
        'secret',
    ];
}
