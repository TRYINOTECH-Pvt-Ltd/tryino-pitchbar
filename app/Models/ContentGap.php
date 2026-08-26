<?php

namespace App\Models;

use App\Concerns\BelongsToAgent;
use App\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ContentGap extends Model
{
    use BelongsToAgent;
    use HasFactory;
    use HasUuidV7;

    protected $fillable = [
        'agent_id', 'question', 'question_hash', 'occurrences',
        'last_seen_at', 'status',
    ];

    protected $casts = [
        'occurrences' => 'integer',
        'last_seen_at' => 'datetime',
    ];
}
