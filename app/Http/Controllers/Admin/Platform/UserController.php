<?php

namespace App\Http\Controllers\Admin\Platform;

use App\Enums\PlatformRole;
use App\Models\AppSetting;
use App\Models\User;
use App\Models\WorkspaceUser;
use App\Support\AuditLogger;
use App\Support\Pagination;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class UserController
{
    public function index(Request $request): Response
    {
        $q = trim((string) $request->query('q', ''));

        $query = User::query()
            ->withCount(['workspaces', 'ownedWorkspaces'])
            ->latest();

        if ($q !== '') {
            $query->where(function ($w) use ($q) {
                $w->where('name', 'like', "%{$q}%")
                    ->orWhere('email', 'like', "%{$q}%");
            });
        }

        $paginator = $query->paginate(25)->withQueryString();

        $rows = collect($paginator->items())
            ->map(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'role' => $u->role?->value ?? PlatformRole::Customer->value,
                'workspaces_count' => $u->workspaces_count,
                'owned_count' => $u->owned_workspaces_count,
                'created_at' => $u->created_at?->toIso8601String(),
                'byok_mode' => $this->byokModeFor($u->byok_enabled),
            ]);

        return Inertia::render('admin/users/index', [
            'users' => $rows,
            'pagination' => Pagination::meta($paginator),
            'filters' => ['q' => $q],
            'byok_globally_enabled' => (bool) AppSetting::singleton()->byok_enabled_globally,
            // Drives client-side guards on the Delete button — server is
            // still the source of truth, but hiding the button on the
            // acting admin's own row is clearer UX than letting them
            // click + see the error toast.
            'current_user_id' => $request->user()?->id,
        ]);
    }

    /**
     * Map nullable `users.byok_enabled` column → tri-state UI string.
     */
    private function byokModeFor(?bool $value): string
    {
        return match ($value) {
            true => 'enabled',
            false => 'disabled',
            null => 'inherit',
        };
    }

    /**
     * Admin-side edit of a user's name + email. Lets the platform admin
     * fix typos / handle support tickets without having to impersonate
     * + open the user's own profile page. Returns a JSON {ok} response
     * so the index page can flash inline without re-rendering.
     */
    public function update(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($user->id),
            ],
        ]);

        $user->forceFill($data)->save();

        return redirect()
            ->route('admin.users.index')
            ->with('success', "{$user->email} updated.");
    }

    public function updateRole(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate([
            'role' => ['required', 'in:customer,super_admin'],
        ]);

        // Don't let an admin demote themselves accidentally — refuse if they're
        // the only super_admin left.
        if ($user->id === $request->user()->id && $data['role'] === PlatformRole::Customer->value) {
            $remaining = User::query()
                ->where('role', PlatformRole::SuperAdmin->value)
                ->where('id', '!=', $user->id)
                ->count();
            if ($remaining === 0) {
                return back()->with('error', 'You are the last super_admin — promote someone else first.');
            }
        }

        $user->forceFill(['role' => $data['role']])->save();

        // Buyer report: Promote button "opens a blank black page".
        // `back()` resolves to the Referer which Inertia sometimes
        // omits on PATCH requests, leaving the router redirecting to
        // the workspace root unauthenticated for the just-promoted
        // user. Explicit redirect to the admin users list always
        // re-renders a valid Inertia page.
        return redirect()
            ->route('admin.users.index')
            ->with('success', "{$user->email} is now {$data['role']}.");
    }

    /**
     * C1: BYOK per-user tri-state override.
     *   'inherit' → byok_enabled = null
     *   'enabled' → byok_enabled = true   (force-allow)
     *   'disabled' → byok_enabled = false (force-deny, beats global ON)
     */
    public function updateByok(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate([
            'mode' => ['required', 'in:inherit,enabled,disabled'],
        ]);

        $value = match ($data['mode']) {
            'enabled' => true,
            'disabled' => false,
            default => null,
        };

        $user->forceFill(['byok_enabled' => $value])->save();

        return back()->with('success', "BYOK access for {$user->email}: {$data['mode']}.");
    }

    /**
     * Soft-delete a user. Refuses four cases that would brick the
     * platform or orphan workspaces:
     *   1. Self-delete — would log the actor out of their own admin.
     *   2. Last super_admin — would leave no one with platform access.
     *   3. User owns one or more workspaces — admin must transfer
     *      ownership first; otherwise the workspaces lose their owner
     *      reference and break Cashier billing + invitation flows.
     *   4. (Implicit) Non-super_admin route gate — middleware already
     *      enforces this; defensive `abort_if` repeats the check.
     *
     * Pivot rows in `workspace_users` are detached so an invited
     * customer can re-accept a workspace invite later under the same
     * email without a duplicate-key conflict. Foreign-key columns in
     * other tables (conversations, leads, audit_logs) keep their
     * `user_id` reference intact — soft-delete preserves the row for
     * those joins.
     */
    public function destroy(Request $request, User $user): RedirectResponse
    {
        abort_unless($request->user()?->isSuperAdmin(), 403);

        if ($user->id === $request->user()->id) {
            return back()->with('error', 'You cannot delete your own account from the platform admin.');
        }

        if ($user->role === PlatformRole::SuperAdmin) {
            $remaining = User::query()
                ->where('role', PlatformRole::SuperAdmin->value)
                ->where('id', '!=', $user->id)
                ->count();
            if ($remaining === 0) {
                return back()->with('error', 'Cannot delete the last super_admin. Promote another user first.');
            }
        }

        $ownedCount = $user->ownedWorkspaces()->count();
        if ($ownedCount > 0) {
            $noun = $ownedCount === 1 ? 'workspace' : 'workspaces';

            return back()->with('error', "Cannot delete {$user->email}: still owns {$ownedCount} {$noun}. Transfer ownership first.");
        }

        $auditWorkspace = $request->user()->default_workspace_id;

        DB::transaction(function () use ($user) {
            WorkspaceUser::query()
                ->where('user_id', $user->id)
                ->delete();

            $user->delete();
        });

        if ($auditWorkspace !== null) {
            AuditLogger::log(
                workspaceId: $auditWorkspace,
                action: 'platform_user.deleted',
                entityType: 'user',
                entityId: (string) $user->id,
                after: [
                    'email' => $user->email,
                    'role' => $user->role?->value,
                    'soft_delete' => true,
                ],
                request: $request,
            );
        }

        return redirect()
            ->route('admin.users.index')
            ->with('success', "{$user->email} deleted.");
    }

    /**
     * Bulk-delete users from /admin/users. Each id runs through the
     * SAME safety guards as the single-row destroy() (self, last
     * super_admin, owns workspaces). Skipped users are NOT errored out
     * — the bar reports how many actually got deleted vs skipped.
     *
     * Card #62 (bulk-selection wiring rollout).
     */
    public function bulkDestroy(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->isSuperAdmin(), 403);

        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:100'],
            'ids.*' => ['integer'],
        ]);

        $actor = $request->user();
        $deleted = 0;
        $skipped = 0;
        $auditWorkspace = $actor->default_workspace_id;

        // Pre-count current super_admins so we can guard against
        // wiping all of them in a single bulk action. Each successful
        // delete in this loop decrements the count.
        $superAdminsRemaining = User::query()
            ->where('role', PlatformRole::SuperAdmin->value)
            ->count();

        User::query()
            ->whereIn('id', $data['ids'])
            ->get()
            ->each(function (User $user) use (
                $actor,
                $request,
                $auditWorkspace,
                &$deleted,
                &$skipped,
                &$superAdminsRemaining,
            ) {
                if ($user->id === $actor->id) {
                    $skipped++;

                    return;
                }

                if ($user->role === PlatformRole::SuperAdmin && $superAdminsRemaining <= 1) {
                    $skipped++;

                    return;
                }

                if ($user->ownedWorkspaces()->count() > 0) {
                    $skipped++;

                    return;
                }

                DB::transaction(function () use ($user) {
                    WorkspaceUser::query()
                        ->where('user_id', $user->id)
                        ->delete();

                    $user->delete();
                });

                if ($user->role === PlatformRole::SuperAdmin) {
                    $superAdminsRemaining--;
                }

                $deleted++;

                if ($auditWorkspace !== null) {
                    AuditLogger::log(
                        workspaceId: $auditWorkspace,
                        action: 'platform_user.bulk_deleted',
                        entityType: 'user',
                        entityId: (string) $user->id,
                        after: [
                            'email' => $user->email,
                            'role' => $user->role?->value,
                            'soft_delete' => true,
                        ],
                        request: $request,
                    );
                }
            });

        $message = $deleted.' user(s) deleted.';
        if ($skipped > 0) {
            $message .= ' '.$skipped.' skipped (self, last super_admin, or workspace owner — transfer ownership first).';
        }

        return redirect()
            ->route('admin.users.index')
            ->with($skipped > 0 && $deleted === 0 ? 'error' : 'success', $message);
    }
}
