<?php

namespace App\Models;

use App\Concerns\BelongsToAgent;
use App\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BehaviorRule extends Model
{
    use BelongsToAgent;
    use HasFactory;
    use HasUuidV7;

    protected $fillable = [
        'agent_id', 'name', 'kind', 'conditions', 'action',
        'cta_rule_id', 'enabled', 'priority',
    ];

    protected $casts = [
        'conditions' => 'array',
        'action' => 'array',
        'enabled' => 'boolean',
        'priority' => 'integer',
    ];

    public function ctaRule(): BelongsTo
    {
        return $this->belongsTo(CtaRule::class);
    }
}
