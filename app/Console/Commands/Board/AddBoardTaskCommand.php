<?php

namespace App\Console\Commands\Board;

use App\Services\Board\BoardStore;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Str;

/**
 * Append a single card to the internal Kanban board from the CLI.
 * Used by Claude (and humans) to drop newly-surfaced work onto the
 * board without opening the admin UI — keeps "things we should do"
 * out of chat history and onto a durable surface.
 *
 * Examples:
 *
 *   php artisan board:add "Add Paddle gateway" --body="EU/UK markets..."
 *
 *   php artisan board:add "Pre-chat lead gate" \
 *     --status=backlog \
 *     --label=widget --label=leads --label=pagenet \
 *     --body="Buyer pagenet asks for ..."
 *
 * Idempotency: if a task with the same exact title already exists,
 * the command bails with exit 0 and a friendly notice rather than
 * adding a duplicate.
 */
#[Signature('board:add
    {title : Card title, e.g. "Add Paddle gateway"}
    {--body= : Optional body / notes}
    {--status=backlog : One of backlog|doing|review|done}
    {--label=* : Repeatable label tag, e.g. --label=billing}
')]
#[Description('Append a single card to the internal Kanban board.')]
class AddBoardTaskCommand extends Command
{
    public function handle(BoardStore $store): int
    {
        $title = trim((string) $this->argument('title'));
        if ($title === '') {
            $this->error('Title cannot be empty.');

            return self::FAILURE;
        }

        $status = (string) $this->option('status');
        if (! in_array($status, ['backlog', 'doing', 'review', 'done'], true)) {
            $this->error("Unknown status '{$status}'. Use one of: backlog, doing, review, done.");

            return self::FAILURE;
        }

        $body = $this->option('body');
        $body = is_string($body) && trim($body) !== '' ? $body : null;

        $labels = array_values(array_filter(
            array_map(static fn ($l) => is_string($l) ? trim($l) : '', (array) $this->option('label')),
            static fn (string $v): bool => $v !== '',
        ));

        $tasks = $store->all();
        foreach ($tasks as $existing) {
            if ((string) ($existing['title'] ?? '') === $title) {
                $this->info("Task already on the board: {$title}");

                return self::SUCCESS;
            }
        }

        $position = 0;
        foreach ($tasks as $t) {
            if (($t['status'] ?? null) === $status) {
                $position = max($position, (int) ($t['position'] ?? 0));
            }
        }

        $now = Date::now()->toIso8601String();
        $number = $store->nextNumber();
        $tasks[] = [
            'id' => (string) Str::uuid7(),
            'number' => $number,
            'title' => $title,
            'body' => $body,
            'status' => $status,
            'labels' => $labels,
            'position' => $position + 1,
            'created_at' => $now,
            'updated_at' => $now,
        ];

        $store->replace($tasks);

        $this->info("Added #{$number} to {$status}: {$title}");

        return self::SUCCESS;
    }
}
