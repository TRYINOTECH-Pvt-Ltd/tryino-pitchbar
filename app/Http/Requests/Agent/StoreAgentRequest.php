<?php

namespace App\Http\Requests\Agent;

use App\Services\Vertical\VerticalPresets;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAgentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // gated by AgentPolicy::create at the controller
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'language_default' => ['required', 'string', 'in:en,nl,es,fr,de,pt,ja,ar,zh'],
            'allowed_origins' => ['nullable', 'array', 'max:32'],
            'allowed_origins.*' => ['string', 'max:255'],
            'restricted_paths' => ['nullable', 'array', 'max:32'],
            'restricted_paths.*' => ['string', 'max:200'],
            'system_prompt' => ['nullable', 'string', 'max:2000'],
            'confidence_threshold' => ['nullable', 'numeric', 'min:0', 'max:1'],
            'persona' => ['nullable', 'array', 'max:16'],
            'theme' => ['nullable', 'array', 'max:24'],
            'guardrails' => ['nullable', 'array', 'max:16'],
            'require_lead_before_chat' => ['sometimes', 'boolean'],
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
