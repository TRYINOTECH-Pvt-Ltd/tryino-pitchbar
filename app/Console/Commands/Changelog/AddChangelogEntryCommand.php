<?php

namespace App\Console\Commands\Changelog;

use App\Models\ChangelogEntry;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * CLI shortcut for adding a changelog entry — companion to
 * `php artisan board:add` so future Claude sessions can drop
 * release notes alongside their commits without opening the admin.
 *
 * Idempotent: re-running with the same `version` is a no-op (the
 * existing row is left untouched). To edit a published entry, use
 * the admin form at /admin/changelog/{id}/edit.
 *
 *   php artisan changelog:add v1.5.0 --title="…" --body="…"
 *   php artisan changelog:add v1.5.0 --status=published --released-at=2026-05-09 \
 *     --title="…" --body="$(cat <<'EOT'
 *   ## Added
 *   - …
 *   EOT
 *   )"
 */
#[Signature('changelog:add
    {version : Semver string, e.g. v1.5.0}
    {--title= : Headline title (required)}
    {--body= : Markdown body (required, multi-line)}
    {--status=draft : draft | published | archived}
    {--released-at= : ISO date for the release; defaults to today on publish}
')]
#[Description('Add a changelog entry from the CLI.')]
class AddChangelogEntryCommand extends Command
{
    public function handle(): int
    {
        $version = trim((string) $this->argument('version'));
        if ($version === '') {
            $this->error('Version cannot be empty.');

            return self::FAILURE;
        }

        $title = trim((string) $this->option('title'));
        $body = trim((string) $this->option('body'));
        if ($title === '' || $body === '') {
            $this->error('Both --title and --body are required.');

            return self::FAILURE;
        }

        $status = (string) $this->option('status');
        if (! in_array($status, [
            ChangelogEntry::STATUS_DRAFT,
            ChangelogEntry::STATUS_PUBLISHED,
            ChangelogEntry::STATUS_ARCHIVED,
        ], true)) {
            $this->error("Unknown status '{$status}'.");

            return self::FAILURE;
        }

        if (ChangelogEntry::findByVersion($version) !== null) {
            $this->info("Entry {$version} already exists — left untouched.");

            return self::SUCCESS;
        }

        $releasedAt = (string) $this->option('released-at');
        if ($releasedAt === '' && $status === ChangelogEntry::STATUS_PUBLISHED) {
            $releasedAt = now()->toDateString();
        }

        ChangelogEntry::create([
            'version' => $version,
            'released_at' => $releasedAt !== '' ? $releasedAt : null,
            'status' => $status,
            'title' => $title,
            'body' => $body,
        ]);

        $this->info("Added {$version} ({$status}).");

        return self::SUCCESS;
    }
}
