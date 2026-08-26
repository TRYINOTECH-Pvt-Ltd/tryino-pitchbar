<?php

namespace App\Http\Controllers\Admin;

use App\Models\Invitation;
use App\Models\Workspace;
use App\Models\WorkspaceUser;
use App\Support\AuditLogger;
use App\Support\CurrentWorkspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class InvitationController
{
    public function show(Request $request, string $token)
    {
        $invitation = Invitation::query()
            ->where('token', $token)
            ->whereNull('accepted_at')
            ->where('expires_at', '>', now())
            ->firstOrFail();

        return inertia('auth/accept-invitation', [
            'invitation' => [
                'email' => $invitation->email,
                'role' => $invitation->role,
                'workspace' => $invitation->workspace->only('id', 'name'),
                'token' => $invitation->token,
            ],
        ]);
    }

    public function accept(Request $request, string $token): RedirectResponse
    {
        $invitation = Invitation::query()
            ->where('token', $token)
            ->whereNull('accepted_at')
            ->where('expires_at', '>', now())
            ->firstOrFail();

        $user = $request->user();
        if ($user === null) {
            // Stash the GET show URL as `intended` so Fortify routes the
            // visitor back to /invitations/{token} after login (not
            // /dashboard). Without this the invitee logs in, lands on
            // dashboard, and never accepts — buyer report 2026-05-21.
            $request->session()->put('url.intended', route('invitations.show', ['token' => $token]));

            return redirect()->route('login', ['email' => $invitation->email]);
        }

        $invitedEmail = Str::lower((string) $invitation->email);
        $userEmail = Str::lower((string) $user->email);
        if ($userEmail !== $invitedEmail) {
            abort(403, 'This invitation is for a different email.');
        }

        // Atomic claim — the UPDATE only succeeds when accepted_at is
        // still NULL, so two concurrent POSTs from the same token can
        // only have one row affected. A second concurrent request sees
        // 0 affected rows and exits with 409 without writing
        // WorkspaceUser.
        $claimed = Invitation::query()
            ->where('id', $invitation->id)
            ->whereNull('accepted_at')
            ->update([
                'accepted_at' => now(),
            ]);

        if ($claimed !== 1) {
            abort(409, 'This invitation has already been accepted.');
        }

        $wasFreshlyCreated = false;
        DB::transaction(function () use ($invitation, $user, &$wasFreshlyCreated): void {
            $existingMember = WorkspaceUser::query()
                ->where('workspace_id', $invitation->workspace_id)
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->first();

            if ($existingMember === null) {
                WorkspaceUser::create([
                    'workspace_id' => $invitation->workspace_id,
                    'user_id' => $user->id,
                    'role' => $invitation->role,
                    'invited_at' => $invitation->created_at,
                    'accepted_at' => now(),
                ]);
                $wasFreshlyCreated = true;
            }
            // Existing member: do not silently elevate or downgrade. Workspace
            // admins must change roles explicitly via /app/members, not by
            // reissuing invitations.
        });

        // Switch the visitor's working context to the invited workspace
        // when (a) they have no default yet, OR (b) their current default
        // is a workspace they themselves own — i.e. the personal
        // workspace auto-created at signup. We do NOT auto-switch when
        // the user's current default is a workspace they were INVITED
        // into earlier — that's an active membership they're working in
        // and yanking them away from it would be jarring (covered by
        // `accepting does not silently flip default workspace away from
        // active one`). Buyer report 2026-05-21: invitees signed up via
        // the invite link, landed in their auto-created personal
        // workspace, and never realised the invited workspace was a
        // click away on the switcher.
        $currentDefault = $user->default_workspace_id
            ? Workspace::query()
                ->withoutGlobalScopes()
                ->whereKey($user->default_workspace_id)
                ->first()
            : null;
        $currentDefaultIsAutoCreated = $currentDefault !== null
            && $currentDefault->owner_user_id === $user->id;
        if ($user->default_workspace_id === null || $currentDefaultIsAutoCreated) {
            $user->forceFill(['default_workspace_id' => $invitation->workspace_id])->save();
        }

        $workspaceName = $invitation->workspace?->name ?? 'workspace';
        $message = $wasFreshlyCreated
            ? "Joined {$workspaceName}."
            : "You're already a member of {$workspaceName}.";

        return redirect()->route('dashboard')->with('success', $message);
    }

    public function destroy(Request $request, Invitation $invitation, CurrentWorkspace $current): RedirectResponse
    {
        $workspace = $current->get();
        abort_if($workspace === null, 404);
        abort_if($invitation->workspace_id !== $workspace->id, 404);
        $request->user()->can('manageMembers', $workspace) || abort(403);

        if ($invitation->accepted_at !== null) {
            return back()->with('error', 'Invitation already accepted.');
        }

        $snapshot = ['email' => $invitation->email, 'role' => $invitation->role];
        $invitation->delete();

        AuditLogger::log(
            workspaceId: $workspace->id,
            action: 'invitation.revoked',
            entityType: 'invitation',
            entityId: $invitation->id,
            before: $snapshot,
            request: $request,
        );

        return back()->with('success', 'Invitation revoked.');
    }
}
