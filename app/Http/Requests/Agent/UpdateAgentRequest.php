<?php

namespace App\Http\Requests\Agent;

use App\Services\Vertical\VerticalPresets;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAgentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // gated by AgentPolicy::update at the controller
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'language_default' => ['sometimes', 'string', 'in:en,nl,es,fr,de,pt,ja,ar,zh'],
            'allowed_origins' => ['sometimes', 'array', 'max:32'],
            'allowed_origins.*' => ['string', 'max:255'],
            'restricted_paths' => ['sometimes', 'nullable', 'array', 'max:32'],
            'restricted_paths.*' => ['string', 'max:200'],
            'system_prompt' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'confidence_threshold' => ['sometimes', 'numeric', 'min:0', 'max:1'],
            'persona' => ['sometimes', 'array', 'max:16'],
            'theme' => ['sometimes', 'array', 'max:24'],
            'theme.primary' => ['sometimes', 'nullable', 'string', 'max:32'],
            'theme.accent' => ['sometimes', 'nullable', 'string', 'max:32'],
            'theme.radius' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:48'],
            'theme.font' => ['sometimes', 'nullable', 'string', 'max:64'],
            'theme.position' => ['sometimes', 'nullable', 'string', Rule::in(['bottom-left', 'bottom-center', 'bottom-right'])],
            'theme.launcher_label' => ['sometimes', 'nullable', 'string', 'max:80'],
            'theme.launcher_icon_url' => ['sometimes', 'nullable', 'string', 'url', 'max:500'],
            'theme.default_open' => ['sometimes', 'boolean'],
            'theme.launcher_size' => ['sometimes', 'nullable', 'string', Rule::in(['sm', 'md', 'lg'])],
            'theme.color_scheme' => ['sometimes', 'nullable', 'string', Rule::in(['light', 'dark', 'auto'])],
            'theme.header_logo_url' => ['sometimes', 'nullable', 'string', 'url', 'max:500'],
            // Operator-controlled max width of the launcher pill and
            // answer panel. Constrained so a typo can't blow the bar
            // off-screen or shrink it below the input minimum.
            'theme.bar_width' => ['sometimes', 'nullable', 'integer', 'min:320', 'max:800'],
            // Where the starter-prompt chips render relative to the
            // composer / message thread.
            'theme.starter_prompts_position' => ['sometimes', 'nullable', 'string', Rule::in(['top', 'bottom'])],
            'guardrails' => ['sometimes', 'array', 'max:16'],
            'starter_prompts' => ['sometimes', 'nullable', 'array', 'max:6'],
            'starter_prompts.*' => ['string', 'max:80'],
            'auto_index_visited_pages' => ['sometimes', 'boolean'],
            'require_lead_before_chat' => ['sometimes', 'boolean'],
            'lead_prompt_strategy' => ['sometimes', 'string', Rule::in([
                'engagement', 'first_turn', 'keyword_only', 'never',
            ])],
            'lead_form_fields' => ['sometimes', 'nullable', 'array', 'max:12'],
            'lead_form_fields.*.key' => ['required_with:lead_form_fields.*', 'string', 'max:64', 'regex:/^[a-z][a-z0-9_]*$/'],
            'lead_form_fields.*.label' => ['required_with:lead_form_fields.*', 'string', 'max:120'],
            'lead_form_fields.*.type' => ['required_with:lead_form_fields.*', 'string', 'in:text,email,tel,textarea,select,checkbox'],
            'lead_form_fields.*.required' => ['sometimes', 'boolean'],
            'lead_form_fields.*.placeholder' => ['sometimes', 'nullable', 'string', 'max:120'],
            'lead_form_fields.*.maxlength' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:4000'],
            'lead_form_fields.*.options' => ['sometimes', 'array', 'max:24'],
            'lead_form_fields.*.options.*' => ['string', 'max:120'],
            'site_type' => ['sometimes', 'nullable', 'string', Rule::in(VerticalPresets::SLUGS)],
            'vertical_overrides' => ['sometimes', 'nullable', 'array'],
            'vertical_overrides.capabilities' => ['sometimes', 'array'],
            'vertical_overrides.capabilities.*' => ['string', 'max:64'],
            'vertical_overrides.starter_prompts' => ['sometimes', 'array', 'max:6'],
            'vertical_overrides.starter_prompts.*' => ['string', 'max:80'],
        ];
    }
}
