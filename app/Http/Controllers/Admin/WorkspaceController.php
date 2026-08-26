<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Workspaces\CreateWorkspaceForUser;
use App\Models\Workspace;
use App\Services\Billing\PlanLimits;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Buyer-facing create-another-workspace endpoint. Honours the
 * `plans.workspaces_limit` per-owner cap before delegating to
 * CreateWorkspaceForUser. First workspace is always allowed
 * (registration cannot block); subsequent workspaces are capped to
 * the owner's primary plan's workspaces_limit.
 */
class WorkspaceController
{
    public function __construct(
        private readonly PlanLimits $limits,
        private readonly CreateWorkspaceForUser $create,
    ) {}

    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_if($user === null, 401);

        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:80'],
        ]);

        $check = $this->limits->checkWorkspaceCreation($user);
        if (! $check['allowed']) {
            throw ValidationException::withMessages([
                'name' => $check['limit'] === 0
                    ? 'Creating additional workspaces is not included on your current plan. Upgrade to enable this feature.'
                    : "You've reached your plan's limit of {$check['limit']} workspaces. Upgrade to add more.",
            ]);
        }

        // Inherit the plan from the user's oldest workspace so a
        // paid user's second workspace isn't silently downgraded to
        // Free. The "primary" workspace is whichever one they own
        // longest — same one PlanLimits::checkWorkspaceCreation()
        // already uses to gate the per-owner workspaces_limit. Null
        // when the user owns no other workspaces yet (registration
        // path), in which case the action falls back to the default
        // signup plan.
        $primary = Workspace::query()
            ->withoutGlobalScopes()
            ->where('owner_user_id', $user->id)
            ->oldest()
            ->first();

        $workspace = DB::transaction(
            fn () => $this->create->handle($user, $data['name'], $primary),
        );

        return redirect()->route('dashboard')
            ->with('success', "Workspace '{$workspace->name}' created.");
    }
}
