<?php

namespace App\Services\Board;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;

/**
 * File-backed persistence for the internal Kanban board.
 *
 * Stored as a single JSON file (default: `storage/app/kanban-tasks.json`)
 * instead of a DB column so `migrate:fresh` during development doesn't
 * blow the board away. The file is on the local disk and survives
 * schema resets, factory tear-downs, and test transactions.
 *
 * Reads return [] on first access (lazy create on next write). Writes
 * fully replace the file contents — no append, no row-level edits;
 * the controller passes the full task list every time.
 *
 * Each card has a sequential `number` int (1, 2, 3, ...) the user can
 * reference instead of the UUID. Numbers never reuse — even after a
 * card is archived, the next new card gets max(historical)+1, not the
 * freed slot. Read-time backfill assigns numbers to legacy cards in
 * `created_at` order so older boards upgrade transparently.
 *
 * Tests fake this via `Storage::fake('local')` and the file path stays
 * the same — Laravel's fake disk is rooted at a temp dir but the
 * relative path is identical.
 */
class BoardStore
{
    public const FILENAME = 'kanban-tasks.json';

    public function __construct(private readonly string $disk = 'local') {}

    /**
     * @return array<int, array<string, mixed>>
     */
    public function all(): array
    {
        $fs = $this->fs();
        if (! $fs->exists(self::FILENAME)) {
            return [];
        }

        $raw = (string) $fs->get(self::FILENAME);
        if ($raw === '') {
            return [];
        }

        try {
            $decoded = json_decode($raw, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            // File is corrupt — treat as empty rather than 500ing the
            // admin board. Operator can re-seed via board:seed.
            return [];
        }

        if (! is_array($decoded)) {
            return [];
        }

        $tasks = array_values($decoded);
        [$tasks, $changed] = $this->backfillNumbers($tasks);

        // Persist the backfill so subsequent reads don't re-do the work
        // and so the on-disk shape converges to the canonical one.
        if ($changed) {
            $this->writeRaw($tasks);
        }

        return $tasks;
    }

    /**
     * @param  array<int, array<string, mixed>>  $tasks
     */
    public function replace(array $tasks): void
    {
        $this->writeRaw(array_values($tasks));
    }

    /**
     * Find a card by either its sequential number ('42' or '#42') or
     * its exact title. Returns the array index in the list returned
     * by all(), or null if nothing matches. Used by the CLI commands
     * (board:start, board:done) so users can reference cards the
     * shortest way: a number.
     *
     * @param  array<int, array<string, mixed>>  $tasks
     */
    public function findByRef(array $tasks, string $ref): ?int
    {
        $ref = trim($ref);
        if ($ref === '') {
            return null;
        }

        // '#42' or '42' → numeric lookup first.
        $numeric = ltrim($ref, '#');
        if ($numeric !== '' && ctype_digit($numeric)) {
            $needle = (int) $numeric;
            foreach ($tasks as $i => $task) {
                if ((int) ($task['number'] ?? 0) === $needle) {
                    return $i;
                }
            }
        }

        // Fall through to exact title match — preserves the prior
        // CLI ergonomics for cards captured before numbers existed.
        foreach ($tasks as $i => $task) {
            if ((string) ($task['title'] ?? '') === $ref) {
                return $i;
            }
        }

        return null;
    }

    /**
     * Returns the next ticket number to assign — max existing
     * `number` (across every card, every status) plus one. Starts at
     * 1 on an empty board. Numbers never reuse, so an archived #14
     * doesn't reopen its slot for the next add.
     */
    public function nextNumber(): int
    {
        $max = 0;
        foreach ($this->all() as $task) {
            $candidate = (int) ($task['number'] ?? 0);
            if ($candidate > $max) {
                $max = $candidate;
            }
        }

        return $max + 1;
    }

    /**
     * @param  array<int, array<string, mixed>>  $tasks
     * @return array{0: array<int, array<string, mixed>>, 1: bool} [tasks, changed]
     */
    private function backfillNumbers(array $tasks): array
    {
        $missing = [];
        $maxAssigned = 0;
        foreach ($tasks as $i => $task) {
            $n = (int) ($task['number'] ?? 0);
            if ($n <= 0) {
                $missing[] = $i;
            } elseif ($n > $maxAssigned) {
                $maxAssigned = $n;
            }
        }

        if ($missing === []) {
            return [$tasks, false];
        }

        // Order missing cards by created_at so older cards get smaller
        // numbers — the natural human reading. Falls back to file
        // order for cards lacking created_at.
        usort($missing, function (int $a, int $b) use ($tasks): int {
            $aAt = (string) ($tasks[$a]['created_at'] ?? '');
            $bAt = (string) ($tasks[$b]['created_at'] ?? '');
            if ($aAt === $bAt) {
                return $a <=> $b;
            }

            return strcmp($aAt, $bAt);
        });

        $next = $maxAssigned + 1;
        foreach ($missing as $index) {
            $tasks[$index]['number'] = $next++;
        }

        return [$tasks, true];
    }

    /**
     * @param  array<int, array<string, mixed>>  $tasks
     */
    private function writeRaw(array $tasks): void
    {
        $payload = json_encode(
            array_values($tasks),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );

        $this->fs()->put(self::FILENAME, $payload === false ? '[]' : $payload);
    }

    private function fs(): Filesystem
    {
        return Storage::disk($this->disk);
    }
}
