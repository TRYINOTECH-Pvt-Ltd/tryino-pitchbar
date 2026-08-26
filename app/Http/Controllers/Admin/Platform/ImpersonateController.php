<?php

namespace App\Http\Controllers\Admin\Platform;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Lets a super_admin temporarily log in as a customer for support.
 * Original admin id is stashed in session so they can /stop and return.
 *
 * Every start/stop is recorded to audit_logs with the actor and target.
 */
class ImpersonateController
{
    public function start(Request $request, User $user): RedirectResponse
    {
        $admin = $request->user();

        // Defensive: middleware should have already gated this, but reaffirm.
        if ($admin === null || ! $admin->isSuperAdmin()) {
            abort(404);
        }

        if ($admin->id === $user->id) {
            return back()->with('error', 'Cannot impersonate yourself.');
        }
        if ($user->isSuperAdmin()) {
            return back()->with('error', 'Cannot impersonate another super_admin.');
        }
        // The whole point of impersonation is to debug a customer in their
        // own workspace. A user with no workspace has nothing to debug —
        // every /app/* page would 404 because there's no tenant context.
        if ($user->workspaces()->count() === 0) {
            return back()->with('error', "{$user->email} has no workspaces — nothing to impersonate into.");
        }

        $request->session()->put('impersonator_id', $admin->id);
        Auth::login($user);

        AuditLog::create([
            'user_id' => $admin->id,
            'workspace_id' => null,
            'action' => 'impersonate.start',
            'entity_type' => User::class,
            'entity_id' => (string) $user->id,
            'before' => null,
            'after' => ['target_email' => $user->email],
            'ip' => $request->ip(),
            'ua' => substr((string) $request->userAgent(), 0, 500),
            'created_at' => now(),
        ]);

        return redirect('/dashboard')->with('success', "Now impersonating {$user->email}.");
    }

    public function stop(Request $request): RedirectResponse
    {
        $impersonatorId = $request->session()->pull('impersonator_id');
        if ($impersonatorId === null) {
            return redirect('/dashboard');
        }

        $original = User::find($impersonatorId);
        $impersonated = $request->user();

        if ($original !== null) {
            Auth::login($original);
        }

        AuditLog::create([
            'user_id' => $original?->id,
            'workspace_id' => null,
            'action' => 'impersonate.stop',
            'entity_type' => User::class,
            'entity_id' => (string) $impersonated?->id,
            'before' => null,
            'after' => ['target_email' => $impersonated?->email],
            'ip' => $request->ip(),
            'ua' => substr((string) $request->userAgent(), 0, 500),
            'created_at' => now(),
        ]);

        return redirect('/admin')->with('success', 'Returned to your admin account.');
    }
}
