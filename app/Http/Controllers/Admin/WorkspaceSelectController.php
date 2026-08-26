<?php

namespace App\Http\Controllers\Admin;

use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class WorkspaceSelectController
{
    public function store(Request $request, Workspace $workspace): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user !== null, 403);

        // user must be a member of this workspace
        $isMember = $user->workspaces()
            ->where('workspaces.id', $workspace->id)
            ->exists();
        abort_unless($isMember, 403);

        $user->forceFill(['default_workspace_id' => $workspace->id])->save();

        return back();
    }
}
