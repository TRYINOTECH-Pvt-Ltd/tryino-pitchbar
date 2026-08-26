<?php

namespace App\Models;

use App\Concerns\BelongsToAgent;
use App\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CtaRule extends Model
{
    use BelongsToAgent;
    use HasFactory;
    use HasUuidV7;

    protected $fillable = [
        'agent_id', 'name', 'label', 'kind', 'conditions',
        'target', 'enabled', 'priority',
    ];

    protected $casts = [
        'conditions' => 'array',
        'target' => 'array',
        'enabled' => 'boolean',
        'priority' => 'integer',
    ];
}
