<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Support\CurrentWorkspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Customer-facing workspace profile editor. Buyer-reported (Lucian,
 * 2026-05-15): owners couldn't rename their own workspace from the
 * Settings sidebar, even though the platform-admin view had had the
 * field for months. Owners + Admins can edit; Editors / Members are
 * denied via WorkspacePolicy::update (Admin tier or higher).
 */
class WorkspaceSettingsController extends Controller
{
    public function __construct(
        private readonly CurrentWorkspace $current,
    ) {}

    public function edit(Request $request): Response
    {
        $workspace = $this->current->get();
        abort_if($workspace === null, 404);
        $request->user()->can('update', $workspace) || abort(403);

        return Inertia::render('settings/workspace', [
            'workspace' => [
                'id' => $workspace->id,
                'name' => $workspace->name,
                'slug' => $workspace->slug,
                // Absolute public KB URL — buyer asked for a copy-able
                // "workspace URL" in the customer settings page so they
                // know what to share with their team. The slug alone
                // isn't a route (the marketing site owns the root
                // namespace), so we surface the canonical public
                // surface that does resolve: `/kb/{slug}`.
                'public_kb_url' => $workspace->slug !== null
                    ? route('kb.index', ['workspace' => $workspace->slug])
                    : null,
            ],
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $workspace = $this->current->get();
        abort_if($workspace === null, 404);
        $request->user()->can('update', $workspace) || abort(403);

        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:80'],
        ]);

        $workspace->forceFill(['name' => $data['name']])->save();

        return back()->with('success', __('Workspace updated.'));
    }
}
