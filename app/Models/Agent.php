<?php

namespace App\Models;

use App\Concerns\BelongsToWorkspace;
use App\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Agent extends Model
{
    use BelongsToWorkspace;
    use HasFactory;
    use HasUuidV7;
    use SoftDeletes;

    protected $fillable = [
        'workspace_id', 'name', 'language_default', 'persona', 'theme',
        'allowed_origins', 'restricted_paths',
        'system_prompt', 'guardrails', 'starter_prompts',
        'confidence_threshold', 'is_published', 'published_version_id',
        'auto_index_visited_pages',
        'site_type', 'vertical_signals', 'vertical_overrides',
        'require_lead_before_chat',
        'lead_form_fields',
        'lead_prompt_strategy',
        'wp_integration',
    ];

    protected $casts = [
        'persona' => 'array',
        'theme' => 'array',
        'allowed_origins' => 'array',
        'restricted_paths' => 'array',
        'guardrails' => 'array',
        'starter_prompts' => 'array',
        'confidence_threshold' => 'float',
        'is_published' => 'boolean',
        'auto_index_visited_pages' => 'boolean',
        'vertical_signals' => 'array',
        'vertical_overrides' => 'array',
        'require_lead_before_chat' => 'boolean',
        'lead_form_fields' => 'array',
        'wp_integration' => 'array',
    ];

    public function publishedVersion(): BelongsTo
    {
        return $this->belongsTo(AgentVersion::class, 'published_version_id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(AgentVersion::class);
    }

    public function sources(): HasMany
    {
        return $this->hasMany(Source::class);
    }

    public function curatedAnswers(): HasMany
    {
        return $this->hasMany(CuratedAnswer::class);
    }

    public function behaviorRules(): HasMany
    {
        return $this->hasMany(BehaviorRule::class);
    }

    public function ctaRules(): HasMany
    {
        return $this->hasMany(CtaRule::class);
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }

    public function leads(): HasMany
    {
        return $this->hasMany(Lead::class);
    }

    public function experiments(): HasMany
    {
        return $this->hasMany(Experiment::class);
    }
}
