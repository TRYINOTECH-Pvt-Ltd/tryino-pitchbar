<?php

namespace App\Http\Resources;

use App\Models\Agent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Agent
 */
class AgentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'workspace_id' => $this->workspace_id,
            'name' => $this->name,
            'language_default' => $this->language_default,
            'persona' => $this->persona,
            'theme' => $this->theme,
            'allowed_origins' => $this->allowed_origins,
            'restricted_paths' => $this->restricted_paths,
            'system_prompt' => $this->system_prompt,
            'guardrails' => $this->guardrails,
            'starter_prompts' => $this->starter_prompts,
            'confidence_threshold' => $this->confidence_threshold,
            'is_published' => $this->is_published,
            'auto_index_visited_pages' => $this->auto_index_visited_pages,
            'require_lead_before_chat' => $this->require_lead_before_chat,
            'lead_form_fields' => $this->lead_form_fields,
            'lead_prompt_strategy' => $this->lead_prompt_strategy ?? 'engagement',
            'site_type' => $this->site_type,
            'vertical_signals' => $this->vertical_signals,
            'vertical_overrides' => $this->vertical_overrides,
            'wp_integration' => $this->wp_integration,
            'published_version_id' => $this->published_version_id,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
