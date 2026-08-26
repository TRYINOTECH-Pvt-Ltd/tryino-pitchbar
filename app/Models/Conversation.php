<?php

namespace App\Models;

use App\Concerns\BelongsToAgent;
use App\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Conversation extends Model
{
    use BelongsToAgent;
    use HasFactory;
    use HasUuidV7;

    protected $fillable = [
        'agent_id', 'visitor_id', 'page_url', 'lang', 'started_at',
        'ended_at', 'cleared_at', 'message_count', 'is_lead', 'is_playground',
        'variant_id', 'attribution', 'claimed_by_user_id', 'claimed_at',
        'human_requested_at', 'operator_typing_until', 'visitor_typing_until',
        'satisfaction', 'satisfaction_at', 'satisfaction_comment',
        'lead_score', 'lead_score_bucket', 'lead_score_updated_at',
        'lead_score_reasons',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
        'cleared_at' => 'datetime',
        'message_count' => 'integer',
        'is_lead' => 'boolean',
        'is_playground' => 'boolean',
        'attribution' => 'array',
        // Explicit int cast — buyer-reported (Lucian, 2026-05-15):
        // after claim() the next reply() returned 'must_claim_first'
        // even though the DB row showed claimed_by_user_id matched the
        // authed user. Some PDO drivers (cPanel MySQL with emulation
        // on, MariaDB shared-host installs) hydrate BIGINT columns as
        // strings, while $request->user()->id is the int-cast primary
        // key. The strict !== comparison then mismatched int 5 vs "5".
        'claimed_by_user_id' => 'integer',
        'claimed_at' => 'datetime',
        'human_requested_at' => 'datetime',
        'operator_typing_until' => 'datetime',
        'visitor_typing_until' => 'datetime',
        'satisfaction_at' => 'datetime',
        'lead_score' => 'integer',
        'lead_score_updated_at' => 'datetime',
        'lead_score_reasons' => 'array',
    ];

    public function visitor(): BelongsTo
    {
        return $this->belongsTo(Visitor::class);
    }

    public function claimedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'claimed_by_user_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    public function lead(): HasOne
    {
        return $this->hasOne(Lead::class);
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(
            ConversationTag::class,
            'conversation_tag_pivot',
            'conversation_id',
            'tag_id',
        )->withPivot('applied_by', 'created_at');
    }

    public function pageViews(): HasMany
    {
        return $this->hasMany(VisitorPageView::class)->orderBy('viewed_at');
    }
}
