<?php

namespace App\Http\Controllers\Admin\Vertical;

use App\Http\Resources\AgentResource;
use App\Models\Agent;
use App\Services\Vertical\VerticalPresetRegistry;
use App\Services\Vertical\VerticalPresets;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Applies a vertical preset to an agent. Non-destructive by default —
 * empty fields get preset defaults, populated fields stay. Pass
 * `force=true` (UI uses a confirmation dialog) to overwrite.
 *
 * `agent.system_prompt` is NEVER baked from a preset. The vertical
 * fragment is rendered at runtime by PromptBuilder so changing
 * site_type instantly changes the prompt — no stale baked text.
 */
class ApplyController
{
    public function __construct(private VerticalPresetRegistry $registry) {}

    public function __invoke(Request $request, Agent $agent): JsonResponse
    {
        $request->user()->can('update', $agent) || abort(403);

        $data = $request->validate([
            'site_type' => ['required', 'string', Rule::in(VerticalPresets::SLUGS)],
            'force' => ['sometimes', 'boolean'],
        ]);

        $preset = $this->registry->for($data['site_type']);
        $force = (bool) ($data['force'] ?? false);

        $update = ['site_type' => $data['site_type']];

        // starter_prompts: fill if empty, replace if force.
        $existingStarters = (array) ($agent->starter_prompts ?? []);
        if ($force || $existingStarters === []) {
            $starters = $preset->starterPrompts();
            if ($starters !== []) {
                $update['starter_prompts'] = $starters;
            }
        }

        // guardrails.max_chars: fill if missing, replace if force.
        $guard = (array) ($agent->guardrails ?? []);
        if ($force || ! isset($guard['max_chars'])) {
            $guard['max_chars'] = $preset->maxChars();
            $update['guardrails'] = $guard;
        }

        // theme.launcher_label: only when preset declares one and the
        // existing label is empty (or force). Generic + internal_kb
        // return null — no change for them.
        $launcherLabel = $preset->launcherLabel();
        if ($launcherLabel !== null) {
            $theme = (array) ($agent->theme ?? []);
            $existingLabel = trim((string) ($theme['launcher_label'] ?? ''));
            if ($force || $existingLabel === '') {
                $theme['launcher_label'] = $launcherLabel;
                $update['theme'] = $theme;
            }
        }

        $agent->forceFill($update)->save();

        return response()->json([
            'data' => (new AgentResource($agent->fresh()))->resolve($request),
        ]);
    }
}
