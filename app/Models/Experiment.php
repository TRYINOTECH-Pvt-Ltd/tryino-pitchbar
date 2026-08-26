<?php

namespace App\Models;

use App\Concerns\BelongsToAgent;
use App\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Experiment extends Model
{
    use BelongsToAgent;
    use HasFactory;
    use HasUuidV7;

    protected $fillable = [
        'agent_id', 'name', 'kind', 'status',
        'traffic_split', 'started_at', 'stopped_at',
    ];

    protected $casts = [
        'traffic_split' => 'array',
        'started_at' => 'datetime',
        'stopped_at' => 'datetime',
    ];

    public function variants(): HasMany
    {
        return $this->hasMany(Variant::class);
    }
}
