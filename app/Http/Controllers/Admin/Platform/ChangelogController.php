<?php

namespace App\Http\Controllers\Admin\Platform;

use App\Models\ChangelogEntry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Super-admin CRUD for the platform-wide changelog. Authoring sits
 * here; reading from buyers and customer workspaces is served by the
 * public ChangelogController at /changelog.
 *
 * Sidebar nav is intentionally NOT added — match the /admin/board
 * pattern of "internal-tool, no-nav-entry". Reach via /admin/changelog
 * directly or via the dashboard's What's-new banner.
 */
class ChangelogController
{
    public function index(): Response
    {
        return Inertia::render('admin/changelog/index', [
            'entries' => ChangelogEntry::allOrderedForAdmin()
                ->map(fn (ChangelogEntry $e) => $this->serialize($e)),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('admin/changelog/create');
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validatedFor($request);

        $entry = ChangelogEntry::create([
            'version' => $data['version'],
            'released_at' => $data['released_at'] ?? null,
            'status' => $data['status'] ?? ChangelogEntry::STATUS_DRAFT,
            'title' => $data['title'],
            'body' => $data['body'],
            'created_by_user_id' => $request->user()?->id,
            // Stamp the entry as admin-authored so the markdown
            // bootstrap won't overwrite it on a future deploy.
            'source' => 'admin',
        ]);

        return redirect()->route('admin.changelog.edit', ['entry' => $entry->id])
            ->with('success', "Entry {$entry->version} created.");
    }

    public function edit(ChangelogEntry $entry): Response
    {
        return Inertia::render('admin/changelog/edit', [
            'entry' => $this->serialize($entry),
        ]);
    }

    public function update(Request $request, ChangelogEntry $entry): RedirectResponse
    {
        $data = $this->validatedFor($request, $entry);

        // Once an entry is published, version is immutable. Buyers may
        // have linked to /changelog#v1.4.0; silently changing what
        // that anchor refers to would rewrite history.
        if (
            $entry->status === ChangelogEntry::STATUS_PUBLISHED
            && isset($data['version'])
            && $data['version'] !== $entry->version
        ) {
            return back()->withErrors([
                'version' => 'A published entry\'s version is immutable. Archive first if you need to renumber.',
            ]);
        }

        $entry->update([
            'version' => $data['version'] ?? $entry->version,
            'released_at' => $data['released_at'] ?? $entry->released_at,
            'status' => $data['status'] ?? $entry->status,
            'title' => $data['title'] ?? $entry->title,
            'body' => $data['body'] ?? $entry->body,
            // Mark as admin-authored — future markdown bootstraps
            // skip this version so the human edit sticks.
            'source' => 'admin',
        ]);

        return redirect()->route('admin.changelog.index')
            ->with('success', "Entry {$entry->version} updated.");
    }

    /**
     * Convenience action — flips draft → published and stamps
     * released_at if it was blank. Idempotent on already-published
     * entries.
     */
    public function publish(ChangelogEntry $entry): RedirectResponse
    {
        $entry->forceFill([
            'status' => ChangelogEntry::STATUS_PUBLISHED,
            'released_at' => $entry->released_at ?? now(),
        ])->save();

        return redirect()->route('admin.changelog.index')
            ->with('success', "Published {$entry->version}.");
    }

    /**
     * Soft-archive — keeps the row + version unique constraint so an
     * old buyer link to /changelog#v1.0 doesn't break, but drops the
     * row from the public listing.
     */
    public function destroy(ChangelogEntry $entry): RedirectResponse
    {
        $entry->forceFill(['status' => ChangelogEntry::STATUS_ARCHIVED])->save();

        return redirect()->route('admin.changelog.index')
            ->with('success', "Archived {$entry->version}.");
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(ChangelogEntry $entry): array
    {
        return [
            'id' => $entry->id,
            'version' => $entry->version,
            'released_at' => $entry->released_at?->toIso8601String(),
            'status' => $entry->status,
            'title' => $entry->title,
            'body' => $entry->body,
            'created_at' => $entry->created_at?->toIso8601String(),
            'updated_at' => $entry->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedFor(Request $request, ?ChangelogEntry $entry = null): array
    {
        // version uniqueness is enforced manually since the data
        // lives in a JSON file rather than a database table.
        $currentEntryId = $entry?->id;
        $versionUnique = function (string $attribute, mixed $value, \Closure $fail) use ($currentEntryId): void {
            if (! is_string($value) || $value === '') {
                return;
            }
            $existing = ChangelogEntry::findByVersion($value);
            if ($existing !== null && $existing->id !== $currentEntryId) {
                $fail('That version already exists.');
            }
        };

        return $request->validate([
            'version' => ['required', 'string', 'max:32', $versionUnique],
            'released_at' => ['nullable', 'date'],
            'status' => ['sometimes', Rule::in([
                ChangelogEntry::STATUS_DRAFT,
                ChangelogEntry::STATUS_PUBLISHED,
                ChangelogEntry::STATUS_ARCHIVED,
            ])],
            'title' => ['required', 'string', 'max:200'],
            'body' => ['required', 'string', 'max:20000'],
        ]);
    }
}
