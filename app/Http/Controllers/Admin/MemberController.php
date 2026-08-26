<?php

namespace App\Http\Controllers\Admin;

use App\Mail\WorkspaceInvitation;
use App\Models\Invitation;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceUser;
use App\Services\Billing\PlanLimits;
use App\Support\AuditLogger;
use App\Support\CurrentWorkspace;
use App\Support\Pagination;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class MemberController
{
    public function __construct(private CurrentWorkspace $current) {}

    public function index(Request $request)
    {
        $workspace = $this->resolveCurrent();
        $request->user()->can('manageMembers', $workspace) || abort(403);

        $q = trim((string) $request->query('q', ''));

        $membersQuery = $workspace->workspaceUsers()->with('user');

        if ($q !== '') {
            $like = "%{$q}%";
            $membersQuery->whereHas('user', function ($u) use ($like) {
                $u->where('name', 'like', $like)
                    ->orWhere('email', 'like', $like);
            });
        }

        $paginator = $membersQuery->paginate(25)->withQueryString();

        return inertia('app/settings/members', [
            'members' => $paginator->items(),
            'pagination' => Pagination::meta($paginator),
            'filters' => ['q' => $q],
            'pendingInvitations' => Invitation::query()
                ->where('workspace_id', $workspace->id)
                ->whereNull('accepted_at')
                ->where('expires_at', '>', now())
                ->get(),
        ]);
    }

    public function store(Request $request, PlanLimits $limits): RedirectResponse
    {
        $workspace = $this->resolveCurrent();
        $request->user()->can('manageMembers', $workspace) || abort(403);

        $check = $limits->check($workspace, PlanLimits::RESOURCE_MEMBER);
        if (! $check['allowed']) {
            return back()->with('error', $limits->reasonFor(PlanLimits::RESOURCE_MEMBER, (int) $check['limit']));
        }

        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'role' => ['required', 'in:admin,editor,viewer'],
        ]);

        $data['email'] = Str::lower($data['email']);

        // If the email already belongs to a member, reject.
        $existingMember = $workspace->workspaceUsers()
            ->whereHas('user', fn ($q) => $q->whereRaw('LOWER(email) = ?', [$data['email']]))
            ->exists();
        if ($existingMember) {
            throw ValidationException::withMessages(['email' => 'This user is already a member.']);
        }

        // If the email already belongs to a registered user, add directly.
        $existingUser = User::query()->whereRaw('LOWER(email) = ?', [$data['email']])->first();
        if ($existingUser !== null) {
            $row = WorkspaceUser::create([
                'workspace_id' => $workspace->id,
                'user_id' => $existingUser->id,
                'role' => $data['role'],
                'invited_at' => now(),
                'accepted_at' => now(),
            ]);

            AuditLogger::log(
                workspaceId: $workspace->id,
                action: 'member.added',
                entityType: 'workspace_user',
                entityId: $row->id,
                after: ['user_id' => $existingUser->id, 'email' => $data['email'], 'role' => $data['role']],
                request: $request,
            );

            return back()->with('success', 'Member added.');
        }

        $invitation = Invitation::create([
            'workspace_id' => $workspace->id,
            'email' => $data['email'],
            'role' => $data['role'],
            'token' => Str::random(48),
            'expires_at' => now()->addDays(7),
            'invited_by_user_id' => $request->user()->id,
        ]);

        AuditLogger::log(
            workspaceId: $workspace->id,
            action: 'member.invited',
            entityType: 'invitation',
            entityId: $invitation->id,
            after: ['email' => $data['email'], 'role' => $data['role']],
            request: $request,
        );

        Mail::to($data['email'])->queue(new WorkspaceInvitation($invitation));

        return back()->with('success', 'Invitation sent.');
    }

    public function destroy(Request $request, WorkspaceUser $member): RedirectResponse
    {
        $workspace = $this->resolveCurrent();
        $request->user()->can('manageMembers', $workspace) || abort(403);
        abort_if($member->workspace_id !== $workspace->id, 404);

        $snapshot = ['user_id' => $member->user_id, 'role' => $member->role];
        $member->delete();

        AuditLogger::log(
            workspaceId: $workspace->id,
            action: 'member.removed',
            entityType: 'workspace_user',
            entityId: $member->id,
            before: $snapshot,
            request: $request,
        );

        return back()->with('success', 'Member removed.');
    }

    private function resolveCurrent(): Workspace
    {
        $workspace = $this->current->get();
        abort_if($workspace === null, 404);

        return $workspace;
    }
}
