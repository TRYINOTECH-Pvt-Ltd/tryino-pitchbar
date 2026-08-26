<?php

namespace App\Http\Controllers\Admin;

use App\Models\Workspace;
use App\Services\LiveChat\BusinessHours;
use App\Support\CurrentWorkspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Workspace-level live-chat configuration: personalization toggle,
 * Slack / Teams webhooks, business hours.
 *
 * Mounted at /app/settings/live-chat. Requires the user to be a member
 * of a workspace and to have admin role within it (workspace owners
 * + admins; regular members see a read-only view in a future PR).
 */
class LiveChatSettingsController
{
    public function __construct(private BusinessHours $businessHours) {}

    public function show(Request $request): Response
    {
        $workspace = $this->workspaceFor($request);

        return Inertia::render('app/settings/live-chat', [
            'workspace' => [
                'id' => $workspace->id,
                'live_chat_personalize' => (bool) ($workspace->live_chat_personalize ?? true),
                'slack_webhook_url' => $workspace->slack_webhook_url,
                'teams_webhook_url' => $workspace->teams_webhook_url,
                'business_hours' => $workspace->business_hours,
            ],
            'preview' => [
                'is_open_now' => $this->businessHours->isOpen($workspace),
                'next_open_at' => $this->businessHours->nextOpenAt($workspace),
                'server_now' => now()->toIso8601String(),
            ],
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $workspace = $this->workspaceFor($request);

        $data = $request->validate([
            'live_chat_personalize' => ['sometimes', 'boolean'],
            'slack_webhook_url' => ['nullable', 'url:https', 'max:1024'],
            'teams_webhook_url' => ['nullable', 'url:https', 'max:1024'],
            'business_hours' => ['nullable', 'array'],
            'business_hours.enabled' => ['sometimes', 'boolean'],
            'business_hours.timezone' => ['sometimes', 'string', 'max:64'],
            'business_hours.schedule' => ['sometimes', 'array'],
        ]);

        // Defensive normalization for the schedule payload — the
        // textarea editor can submit arbitrary JSON. Validate timezone
        // is one PHP recognizes; bad values fall back to UTC instead
        // of breaking BusinessHours::isOpen() at runtime.
        if (isset($data['business_hours']['timezone'])) {
            $tz = (string) $data['business_hours']['timezone'];
            if (! in_array($tz, \DateTimeZone::listIdentifiers(), true)) {
                $data['business_hours']['timezone'] = 'UTC';
            }
        }

        // Coerce unchecked checkboxes the same way profile does.
        $data['live_chat_personalize'] = $request->boolean('live_chat_personalize');

        $workspace->fill($data);
        $workspace->save();

        return back()->with('success', 'Live chat settings saved.');
    }

    private function workspaceFor(Request $request): Workspace
    {
        $current = app(CurrentWorkspace::class);
        $workspaceId = $current->id();
        if ($workspaceId === null) {
            abort(404);
        }
        $workspace = Workspace::query()->find($workspaceId);
        if ($workspace === null) {
            abort(404);
        }

        // Owner + admin roles can edit live-chat settings.
        $role = $workspace->members()
            ->where('users.id', $request->user()->id)
            ->first()?->pivot?->role;
        if (! in_array($role, ['owner', 'admin'], true)) {
            abort(403);
        }

        return $workspace;
    }
}
