<?php

namespace App\Console\Commands\Board;

use App\Services\Board\BoardStore;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Date;

/**
 * Pull a card off Backlog into the Doing column. Companion to
 * board:done — keeps the board reflecting reality so a glance at
 * /admin/board answers "what's actively being worked on right now?".
 *
 * Accepts either the card number (`14` or `#14`) or its exact title:
 *
 *   php artisan board:start 14
 *   php artisan board:start "#14"
 *   php artisan board:start "Add Paddle gateway"
 *
 * Idempotent. If the card is already in Doing the command is a no-op
 * (it does NOT bump position, so a card pulled and then bounced back
 * to Doing keeps its original spot).
 */
#[Signature('board:start
    {ref : Card number (`14` / `#14`) or exact title}
')]
#[Description('Move a card to the In Progress column.')]
class MarkInProgressCommand extends Command
{
    public function handle(BoardStore $store): int
    {
        $ref = trim((string) $this->argument('ref'));
        if ($ref === '') {
            $this->error('Reference cannot be empty.');

            return self::FAILURE;
        }

        $tasks = $store->all();
        $foundIndex = $store->findByRef($tasks, $ref);

        if ($foundIndex === null) {
            $this->error("No card found matching: {$ref}");

            return self::FAILURE;
        }

        $task = $tasks[$foundIndex];
        $label = '#'.($task['number'] ?? '?').' '.($task['title'] ?? '');

        if (($task['status'] ?? null) === 'doing') {
            $this->info("Already in progress: {$label}");

            return self::SUCCESS;
        }

        $maxPosition = 0;
        foreach ($tasks as $t) {
            if (($t['status'] ?? null) === 'doing') {
                $maxPosition = max($maxPosition, (int) ($t['position'] ?? 0));
            }
        }

        $tasks[$foundIndex]['status'] = 'doing';
        $tasks[$foundIndex]['position'] = $maxPosition + 1;
        $tasks[$foundIndex]['updated_at'] = Date::now()->toIso8601String();

        $store->replace($tasks);

        $this->info("Moved to Doing: {$label}");

        return self::SUCCESS;
    }
}
