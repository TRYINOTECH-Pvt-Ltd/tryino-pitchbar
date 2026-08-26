<?php

namespace App\Models;

use App\Concerns\BelongsToAgent;
use App\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Lead extends Model
{
    use BelongsToAgent;
    use HasFactory;
    use HasUuidV7;

    protected $fillable = [
        'conversation_id', 'agent_id', 'email', 'phone', 'name',
        'fields', 'status', 'owner_user_id', 'routed_to',
    ];

    protected $casts = [
        'fields' => 'array',
    ];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }
}
