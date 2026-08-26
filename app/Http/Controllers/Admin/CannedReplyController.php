<?php

namespace App\Http\Controllers\Admin;

use App\Models\CannedReply;
use App\Models\User;
use App\Models\Workspace;
use App\Support\CurrentWorkspace;
use App\Support\Tenancy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Unique;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Workspace canned replies — operator-side snippets surfaced in the
 * live-chat console reply textarea. The model uses `BelongsToWorkspace`
 * so every read + write is auto-scoped; this controller doesn't need
 * per-method workspace_id checks.
 *
 * Picker (used by the conversation thread page) is the JSON variant
 * served from index() with `?json=1`.
 */
class CannedReplyController
{
    private function gateManageReplies(User $user, Workspace $workspace): void
    {
        $role = Tenancy::roleFor($user, $workspace);
        if ($role === null || ! $role->canManageAgents()) {
            abort(403);
        }
    }

    public function index(Request $request): Response|JsonResponse
    {
        $rows = CannedReply::query()
            ->orderBy('position')
            ->orderBy('label')
            ->get(['id', 'label', 'content', 'position', 'created_by', 'created_at'])
            ->map(fn (CannedReply $r) => [
                'id' => $r->id,
                'label' => $r->label,
                'content' => $r->content,
                'position' => (int) $r->position,
                'created_at' => $r->created_at?->toIso8601String(),
            ])
            ->values();

        if ($request->boolean('json')) {
            return response()->json(['data' => $rows]);
        }

        return Inertia::render('app/settings/canned-replies', [
            'replies' => $rows,
        ]);
    }

    public function store(Request $request, CurrentWorkspace $current): RedirectResponse
    {
        $workspace = $current->get();
        abort_if($workspace === null, 404);
        $this->gateManageReplies($request->user(), $workspace);

        $data = $request->validate($this->rules());

        // Auto-position: append to the end. Operators reorder via the
        // settings page using the up/down handles.
        $maxPosition = (int) (CannedReply::query()->max('position') ?? 0);

        CannedReply::create([
            'label' => $data['label'],
            'content' => $data['content'],
            'position' => $maxPosition + 1,
            'created_by' => $request->user()->id,
            // workspace_id auto-filled by BelongsToWorkspace trait.
        ]);

        return back()->with('success', 'Canned reply added.');
    }

    public function update(Request $request, CannedReply $cannedReply, CurrentWorkspace $current): RedirectResponse
    {
        $this->authorize($request, $cannedReply);
        $this->gateManageReplies($request->user(), $current->get() ?? abort(404));

        $data = $request->validate($this->rules());

        $cannedReply->fill([
            'label' => $data['label'],
            'content' => $data['content'],
        ]);
        $cannedReply->save();

        return back()->with('success', 'Canned reply updated.');
    }

    /**
     * Bulk-rewrite the position column to a new ordering. Body shape:
     *   { ordered_ids: [<uuid>, <uuid>, …] }
     * Whichever UUIDs aren't in the list keep their existing positions.
     * Cheap one-by-one update; canned replies are typically <50 rows
     * per workspace.
     */
    public function reorder(Request $request, CurrentWorkspace $current): RedirectResponse
    {
        $workspace = $current->get();
        abort_if($workspace === null, 404);
        $this->gateManageReplies($request->user(), $workspace);

        $data = $request->validate([
            'ordered_ids' => ['required', 'array'],
            'ordered_ids.*' => ['string', 'uuid'],
        ]);

        foreach ($data['ordered_ids'] as $i => $id) {
            CannedReply::query()
                ->whereKey($id)
                ->update(['position' => $i + 1]);
        }

        return back()->with('success', 'Canned replies reordered.');
    }

    public function destroy(Request $request, CannedReply $cannedReply, CurrentWorkspace $current): RedirectResponse
    {
        $this->authorize($request, $cannedReply);
        $this->gateManageReplies($request->user(), $current->get() ?? abort(404));

        $cannedReply->delete();

        return back()->with('success', 'Canned reply removed.');
    }

    private function authorize(Request $request, CannedReply $reply): void
    {
        // BelongsToWorkspace already prevents cross-tenant resolution
        // via route-model binding (the row is invisible). Belt-and-
        // braces: the user must be a member of the active workspace.
        $workspaceId = app(CurrentWorkspace::class)->id();
        if ($workspaceId === null || $reply->workspace_id !== $workspaceId) {
            abort(404);
        }
    }

    /**
     * @return array<string, array<int, string|Unique>>
     */
    private function rules(): array
    {
        return [
            'label' => ['required', 'string', 'max:80'],
            'content' => ['required', 'string', 'max:4000'],
        ];
    }
}
