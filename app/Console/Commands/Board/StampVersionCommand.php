<?php

namespace App\Console\Commands\Board;

use App\Services\Board\BoardStore;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Stamp a release version onto board cards. Two shapes:
 *
 *   php artisan board:stamp-version v1.1.0 --status=done
 *     -- stamp every card in the Done column that doesn't already
 *        have a version with v1.1.0. Use this once per release to
 *        retroactively label all the cards that shipped in it.
 *
 *   php artisan board:stamp-version v1.1.0 --ref=23 --ref=34
 *     -- stamp specific cards by number / title.
 *
 *   php artisan board:stamp-version v1.1.0 --status=done --force
 *     -- overwrite existing version stamps too.
 *
 * Idempotent on (status, version) without --force; safe to run from
 * a deploy hook so freshly merged Done cards always get the running
 * version even if board:done was called without --version.
 */
#[Signature('board:stamp-version
    {version : The release version to stamp, e.g. v1.1.0}
    {--status= : Stamp every card in this status column (e.g. done)}
    {--ref=* : Stamp specific cards by number / title (repeatable)}
    {--force : Overwrite existing version values when set}
')]
#[Description('Bulk-stamp board cards with a release version (idempotent unless --force).')]
class StampVersionCommand extends Command
{
    public function handle(BoardStore $store): int
    {
        $version = trim((string) $this->argument('version'));
        if ($version === '') {
            $this->error('Version cannot be empty.');

            return self::FAILURE;
        }

        $status = $this->option('status');
        $refs = (array) $this->option('ref');
        $force = (bool) $this->option('force');

        if ($status === null && empty($refs)) {
            $this->error('Pass --status=<col> or one or more --ref=<n> to pick which cards to stamp.');

            return self::FAILURE;
        }

        $tasks = $store->all();
        $matched = [];

        if (is_string($status) && $status !== '') {
            foreach ($tasks as $i => $task) {
                if (($task['status'] ?? null) === $status) {
                    $matched[$i] = true;
                }
            }
        }

        foreach ($refs as $ref) {
            $i = $store->findByRef($tasks, (string) $ref);
            if ($i !== null) {
                $matched[$i] = true;
            } else {
                $this->warn("Skipping unknown ref: {$ref}");
            }
        }

        $stamped = 0;
        foreach (array_keys($matched) as $i) {
            $existing = (string) ($tasks[$i]['version'] ?? '');
            if ($existing !== '' && ! $force) {
                continue;
            }
            $tasks[$i]['version'] = $version;
            $stamped++;
        }

        if ($stamped === 0) {
            $this->info('No cards needed stamping (already labeled, or no matches).');

            return self::SUCCESS;
        }

        $store->replace($tasks);

        $this->info("Stamped {$stamped} card(s) with {$version}.");

        return self::SUCCESS;
    }
}
