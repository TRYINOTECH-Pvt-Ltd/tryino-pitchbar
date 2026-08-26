<?php

namespace App\Http\Controllers\Admin\Platform;

use App\Services\Board\BoardStore;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Internal-only Kanban board for the platform team. Persists to a
 * JSON file (storage/app/kanban-tasks.json) instead of a DB column,
 * so `migrate:fresh` during development doesn't wipe the board.
 *
 * Four fixed columns: backlog → doing → review → done. Status moves
 * via dropdown (no drag-drop in v1). Cards carry a title, an optional
 * body, a label list (free-form short strings), and a position int
 * that orders cards within their column.
 */
class KanbanBoardController
{
    public const STATUSES = ['backlog', 'doing', 'review', 'done'];

    public function __construct(private readonly BoardStore $store) {}

    public function index(): Response
    {
        return Inertia::render('admin/board', [
            'tasks' => $this->sortedTasks(),
            'statuses' => self::STATUSES,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validatedFor($request);

        $tasks = $this->store->all();
        $now = Date::now()->toIso8601String();

        $tasks[] = [
            'id' => (string) Str::uuid7(),
            'number' => $this->store->nextNumber(),
            'title' => $data['title'],
            'body' => $data['body'] ?? null,
            'status' => $data['status'] ?? 'backlog',
            'labels' => $this->normalizeLabels($data['labels'] ?? []),
            'position' => $this->nextPosition($tasks, $data['status'] ?? 'backlog'),
            'created_at' => $now,
            'updated_at' => $now,
        ];

        $this->store->replace($tasks);

        return redirect()->route('admin.board.index')
            ->with('success', 'Task added.');
    }

    public function update(Request $request, string $taskId): RedirectResponse
    {
        $data = $this->validatedFor($request);

        $tasks = $this->store->all();
        $found = false;
        foreach ($tasks as $i => $task) {
            if (($task['id'] ?? null) === $taskId) {
                $tasks[$i]['title'] = $data['title'];
                $tasks[$i]['body'] = $data['body'] ?? null;
                $tasks[$i]['labels'] = $this->normalizeLabels($data['labels'] ?? []);
                // Status change moves the card to the bottom of the new column.
                $newStatus = $data['status'] ?? $task['status'] ?? 'backlog';
                if ($newStatus !== ($task['status'] ?? null)) {
                    $tasks[$i]['status'] = $newStatus;
                    $tasks[$i]['position'] = $this->nextPosition($tasks, $newStatus);
                }
                $tasks[$i]['updated_at'] = Date::now()->toIso8601String();
                $found = true;
                break;
            }
        }

        abort_if(! $found, 404);

        $this->store->replace($tasks);

        return redirect()->route('admin.board.index')
            ->with('success', 'Task updated.');
    }

    public function destroy(string $taskId): RedirectResponse
    {
        $tasks = array_values(array_filter(
            $this->store->all(),
            fn (array $t): bool => ($t['id'] ?? null) !== $taskId,
        ));

        $this->store->replace($tasks);

        return redirect()->route('admin.board.index')
            ->with('success', 'Task archived.');
    }

    /**
     * Sort the board for the page render. Per-column order:
     *
     *   backlog       — created_at ASC (oldest first; FIFO queue feel,
     *                   so the items that have been waiting longest
     *                   sit at the top).
     *   doing/review  — position ASC (manual order; admins drag-drop
     *                   within these columns and we honour it).
     *   done          — updated_at DESC (newest-completed first; the
     *                   most useful Done card to glance at is the one
     *                   you just shipped).
     *
     * The primary sort is still status (so columns stay grouped) — the
     * per-column tie-breaker varies by status.
     *
     * @return array<int, array<string, mixed>>
     */
    private function sortedTasks(): array
    {
        $tasks = $this->store->all();

        usort($tasks, function (array $a, array $b): int {
            $aStatus = (string) ($a['status'] ?? 'backlog');
            $bStatus = (string) ($b['status'] ?? 'backlog');
            $aIdx = array_search($aStatus, self::STATUSES, true);
            $bIdx = array_search($bStatus, self::STATUSES, true);

            if ($aIdx !== $bIdx) {
                return ((int) $aIdx) <=> ((int) $bIdx);
            }

            // Same column → use the per-column rule.
            return match ($aStatus) {
                'backlog' => strcmp(
                    (string) ($a['created_at'] ?? ''),
                    (string) ($b['created_at'] ?? ''),
                ),
                'done' => strcmp(
                    (string) ($b['updated_at'] ?? ''),
                    (string) ($a['updated_at'] ?? ''),
                ),
                default => ((int) ($a['position'] ?? 0)) <=> ((int) ($b['position'] ?? 0)),
            };
        });

        return $tasks;
    }

    /**
     * @param  array<int, array<string, mixed>>  $tasks
     */
    private function nextPosition(array $tasks, string $status): int
    {
        $max = 0;
        foreach ($tasks as $t) {
            if (($t['status'] ?? null) === $status) {
                $max = max($max, (int) ($t['position'] ?? 0));
            }
        }

        return $max + 1;
    }

    /**
     * @param  array<int, mixed>  $labels
     * @return array<int, string>
     */
    private function normalizeLabels(array $labels): array
    {
        return array_values(array_filter(
            array_map(static fn ($l) => is_string($l) ? trim($l) : '', $labels),
            static fn (string $v): bool => $v !== '',
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedFor(Request $request): array
    {
        return $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'body' => ['nullable', 'string', 'max:4000'],
            'status' => ['sometimes', Rule::in(self::STATUSES)],
            'labels' => ['nullable', 'array', 'max:8'],
            'labels.*' => ['string', 'max:24'],
        ]);
    }
}
