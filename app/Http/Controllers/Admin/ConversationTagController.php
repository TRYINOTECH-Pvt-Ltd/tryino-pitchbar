<?php

namespace App\Http\Controllers\Admin;

use App\Models\ConversationTag;
use App\Support\CurrentWorkspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Workspace-scoped tag management. Tags are shared across all
 * conversations in the workspace; operators apply them per-conversation
 * via the right-pane tag picker.
 *
 * Settings page lives at /app/settings/tags. Surface is intentionally
 * small — label + colour + reorder, no bulk import / export until a
 * buyer asks.
 */
class ConversationTagController
{
    public function index(): Response
    {
        $tags = ConversationTag::query()
            ->orderBy('label')
            ->get(['id', 'label', 'color', 'created_at'])
            ->map(fn (ConversationTag $t) => [
                'id' => $t->id,
                'label' => $t->label,
                'color' => $t->color,
                'created_at' => $t->created_at?->toIso8601String(),
            ])
            ->values();

        return Inertia::render('app/settings/tags', [
            'tags' => $tags,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate($this->rules());

        ConversationTag::create([
            'label' => $data['label'],
            'color' => $this->normalizeColor($data['color']),
            // workspace_id auto-filled by BelongsToWorkspace trait.
        ]);

        return back()->with('success', 'Tag added.');
    }

    public function update(Request $request, ConversationTag $tag): RedirectResponse
    {
        $this->authorizeMembership($tag);

        $data = $request->validate($this->rules());

        $tag->fill([
            'label' => $data['label'],
            'color' => $this->normalizeColor($data['color']),
        ]);
        $tag->save();

        return back()->with('success', 'Tag updated.');
    }

    public function destroy(Request $request, ConversationTag $tag): RedirectResponse
    {
        $this->authorizeMembership($tag);

        $tag->delete();

        return back()->with('success', 'Tag removed.');
    }

    private function authorizeMembership(ConversationTag $tag): void
    {
        $workspaceId = app(CurrentWorkspace::class)->id();
        if ($workspaceId === null || $tag->workspace_id !== $workspaceId) {
            abort(404);
        }
    }

    private function normalizeColor(string $value): string
    {
        $value = strtolower(trim($value));
        if (! preg_match('/^#[0-9a-f]{6}$/', $value)) {
            return '#64748b';
        }

        return $value;
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function rules(): array
    {
        return [
            'label' => ['required', 'string', 'max:60'],
            'color' => ['required', 'string', 'max:7'],
        ];
    }
}
