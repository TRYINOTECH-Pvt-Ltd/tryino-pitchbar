<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\Agent;
use App\Models\AppSetting;
use App\Models\Workspace;
use App\Services\Widget\WidgetDefaultsResolver;
use App\Support\CurrentWorkspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Workspace-level widget defaults. Lives at /settings/widget so a
 * workspace owner can set the colours, persona, starter prompts, and
 * reply-length cap that EVERY new agent in the workspace inherits at
 * creation. Existing agents are unaffected unless the owner clicks
 * "Apply to all my agents" — that explicit broadcast pushes the
 * defaults onto every agent in the workspace.
 *
 * The actual fallback chain (workspace → platform → hardcoded) lives
 * in WidgetDefaultsResolver; this controller is just CRUD over the
 * `workspaces.widget_defaults` JSON column plus the broadcast action.
 */
class WidgetController extends Controller
{
    public function __construct(
        private readonly CurrentWorkspace $current,
        private readonly WidgetDefaultsResolver $resolver,
    ) {}

    public function edit(): Response
    {
        $workspace = $this->workspace();
        $effective = $this->resolver->for($workspace);

        return Inertia::render('settings/widget', [
            'scope' => 'workspace',
            'effective' => $effective,
            'workspace_overrides' => (array) ($workspace->widget_defaults ?? []),
            'platform_defaults' => $this->resolver->platform(),
            'agents_count' => Agent::query()->where('workspace_id', $workspace->id)->count(),
            'endpoints' => [
                'update' => '/settings/widget',
                'apply_to_all' => '/settings/widget/apply-to-all',
            ],
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $workspace = $this->workspace();
        $data = $this->validate($request);

        $workspace->forceFill(['widget_defaults' => $data])->save();

        return back()->with('success', 'Widget defaults saved.');
    }

    /**
     * Super-admin platform-wide widget defaults page. Same React
     * component, different scope prop — writes to app_settings instead
     * of the current workspace.
     */
    public function platformEdit(): Response
    {
        $effective = $this->resolver->platform();

        return Inertia::render('settings/widget', [
            'scope' => 'platform',
            'effective' => $effective,
            'workspace_overrides' => [],
            'platform_defaults' => $effective,
            'agents_count' => 0,
            'endpoints' => [
                'update' => '/settings/widget-defaults',
                'apply_to_all' => null,
            ],
        ]);
    }

    public function platformUpdate(Request $request): RedirectResponse
    {
        $data = $this->validate($request);

        $row = AppSetting::singleton();
        $row->forceFill(['widget_defaults' => $data])->save();
        AppSetting::flushSingleton();

        return back()->with('success', 'Platform widget defaults saved.');
    }

    /**
     * Push the workspace's saved defaults onto every existing agent in
     * the workspace. Replaces theme / persona / guardrails /
     * starter_prompts wholesale — admins are warned in the UI.
     */
    public function applyToAll(Request $request): RedirectResponse
    {
        $workspace = $this->workspace();
        $effective = $this->resolver->for($workspace);

        $touched = Agent::query()
            ->where('workspace_id', $workspace->id)
            ->update([
                'theme' => json_encode($effective['theme']),
                'persona' => json_encode($effective['persona']),
                'guardrails' => json_encode($effective['guardrails']),
                'starter_prompts' => $effective['starter_prompts'] === []
                    ? null
                    : json_encode($effective['starter_prompts']),
            ]);

        return back()->with(
            'success',
            $touched === 1
                ? 'Applied widget defaults to 1 agent.'
                : "Applied widget defaults to {$touched} agents.",
        );
    }

    /**
     * Validate the form payload. Each block is optional so a workspace
     * can override only the parts it cares about (theme without
     * persona, persona without starter prompts, etc.).
     *
     * @return array<string, mixed>
     */
    private function validate(Request $request): array
    {
        return $request->validate([
            'theme' => ['nullable', 'array'],
            'theme.primary' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{3,8}$/'],
            'theme.accent' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{3,8}$/'],
            'theme.radius' => ['nullable', 'integer', 'min:0', 'max:32'],
            'theme.launcher_label' => ['nullable', 'string', 'max:48'],

            'persona' => ['nullable', 'array'],
            'persona.name' => ['nullable', 'string', 'max:64'],
            'persona.tone' => ['nullable', 'string', 'in:friendly,expert,concise'],

            'guardrails' => ['nullable', 'array'],
            'guardrails.max_chars' => ['nullable', 'integer', 'min:200', 'max:8000'],

            'starter_prompts' => ['nullable', 'array', 'max:6'],
            'starter_prompts.*' => ['string', 'max:80'],
        ]);
    }

    private function workspace(): Workspace
    {
        $workspace = $this->current->get();
        abort_if($workspace === null, 404);

        return $workspace;
    }
}
