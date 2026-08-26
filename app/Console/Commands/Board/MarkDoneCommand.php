<?php

namespace App\Console\Commands\Board;

use App\Services\Board\BoardStore;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Date;

/**
 * Move a card to the Done column with a "How to test from UI" plan
 * appended to its body. This is the closing step every shipped card
 * must go through — see CLAUDE.md "Hard rules" #9.
 *
 * Usage (Claude runs this after `git commit`). Accepts either a card
 * number (`14` / `#14`) or the exact title:
 *
 *   php artisan board:done 14 \
 *     --test="1. Sign in as super_admin\n2. Open /admin/plans\n3. ..."
 *
 *   php artisan board:done "#14" --test="..."
 *   php artisan board:done "Add Paddle gateway" --test="..."
 *
 * The body grows a "## How to test from UI" section so anyone reading
 * the Done card later can verify the work without re-reading commits.
 * Idempotent — re-running on an already-Done card with the same test
 * plan is a no-op; with a new plan it replaces the prior section.
 */
#[Signature('board:done
    {ref : Card number (`14` / `#14`) or exact title}
    {--test= : UI test plan, multi-line. Appended to the card body under "## How to test from UI"}
    {--release= : Application version this card shipped in (e.g. v1.1.0). Stamped on the card so the board shows which release each Done card belongs to.}
')]
#[Description('Move a card to Done with a "How to test from UI" plan in the body.')]
class MarkDoneCommand extends Command
{
    private const TEST_HEADING = '## How to test from UI';

    public function handle(BoardStore $store): int
    {
        $ref = trim((string) $this->argument('ref'));
        if ($ref === '') {
            $this->error('Reference cannot be empty.');

            return self::FAILURE;
        }

        $test = $this->option('test');
        $test = is_string($test) ? trim($test) : '';
        if ($test === '') {
            $this->error('--test is required. The card must carry a "How to test from UI" plan.');

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

        // Append (or replace) the "How to test from UI" section in the
        // card body. Anything before the heading stays as-is — the
        // motivation/context written when the card was added must
        // survive intact.
        $existingBody = (string) ($task['body'] ?? '');
        $newSection = "\n\n".self::TEST_HEADING."\n\n".$test;

        if (str_contains($existingBody, self::TEST_HEADING)) {
            // Replace the prior section (everything from the heading on).
            $existingBody = (string) preg_replace(
                '/\n*'.preg_quote(self::TEST_HEADING, '/').'.*$/s',
                '',
                $existingBody,
            );
        }

        $tasks[$foundIndex]['body'] = trim($existingBody).$newSection;
        $tasks[$foundIndex]['status'] = 'done';
        $tasks[$foundIndex]['updated_at'] = Date::now()->toIso8601String();

        // Optional version stamp — shows up as a pill on the board so
        // anyone glancing at Done can see which release a card shipped
        // in. Falls back to a workspace-wide default the first time
        // through; subsequent re-runs of board:done preserve whatever
        // was there unless the user passes --version explicitly.
        $release = $this->option('release');
        if (is_string($release) && trim($release) !== '') {
            $tasks[$foundIndex]['version'] = trim($release);
        }

        // New position = bottom of Done so newly-shipped cards land at
        // the end of the column (chronological by completion).
        $maxDonePosition = 0;
        foreach ($tasks as $t) {
            if (($t['status'] ?? null) === 'done') {
                $maxDonePosition = max($maxDonePosition, (int) ($t['position'] ?? 0));
            }
        }
        $tasks[$foundIndex]['position'] = $maxDonePosition + 1;

        $store->replace($tasks);

        $this->info("Moved to Done: {$label}");

        return self::SUCCESS;
    }
}
