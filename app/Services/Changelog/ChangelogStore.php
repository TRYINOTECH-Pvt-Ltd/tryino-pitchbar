<?php

namespace App\Services\Changelog;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * File-backed persistence for the platform changelog.
 *
 * Stored as `storage/app/private/changelog-entries.json` (Laravel
 * local disk) — NOT in the database. Same reason as the Kanban
 * board: `php artisan migrate:fresh` during development would
 * otherwise wipe every release note. The file survives schema
 * resets, factory tear-downs, and test transactions.
 *
 * Reads return [] on first access. On every read, the store also
 * looks for source-of-truth markdown files at
 * `database/changelog-entries/v*.md` and seeds any version that
 * doesn't already exist on disk. That gives buyers a "ship the
 * source in git, sync to disk on first request" workflow without
 * a separate command — and re-running migrate:fresh never loses
 * shipped release notes.
 */
class ChangelogStore
{
    public const FILENAME = 'changelog-entries.json';

    public function __construct(
        private readonly string $disk = 'local',
        private readonly ?string $bootstrapDir = null,
    ) {}

    /**
     * @return array<int, array<string, mixed>>
     */
    public function all(): array
    {
        $entries = $this->readRaw();
        [$entries, $changed] = $this->bootstrapFromMarkdown($entries);

        if ($changed) {
            $this->writeRaw($entries);
        }

        return $entries;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function published(): array
    {
        $rows = array_values(array_filter(
            $this->all(),
            fn (array $e) => ($e['status'] ?? null) === 'published',
        ));

        usort($rows, function (array $a, array $b): int {
            $rt = strcmp(
                (string) ($b['released_at'] ?? ''),
                (string) ($a['released_at'] ?? ''),
            );
            if ($rt !== 0) {
                return $rt;
            }

            return strcmp(
                (string) ($b['created_at'] ?? ''),
                (string) ($a['created_at'] ?? ''),
            );
        });

        return $rows;
    }

    /**
     * Order matches the admin index expectations: published first,
     * then drafts, then archived; newest first within each.
     *
     * @return array<int, array<string, mixed>>
     */
    public function allOrderedForAdmin(): array
    {
        $entries = $this->all();
        $statusRank = ['published' => 0, 'draft' => 1, 'archived' => 2];

        usort($entries, function (array $a, array $b) use ($statusRank): int {
            $sa = $statusRank[$a['status'] ?? ''] ?? 3;
            $sb = $statusRank[$b['status'] ?? ''] ?? 3;
            if ($sa !== $sb) {
                return $sa <=> $sb;
            }

            $rt = strcmp(
                (string) ($b['released_at'] ?? ''),
                (string) ($a['released_at'] ?? ''),
            );
            if ($rt !== 0) {
                return $rt;
            }

            return strcmp(
                (string) ($b['created_at'] ?? ''),
                (string) ($a['created_at'] ?? ''),
            );
        });

        return $entries;
    }

    public function findById(string $id): ?array
    {
        foreach ($this->all() as $entry) {
            if (($entry['id'] ?? null) === $id) {
                return $entry;
            }
        }

        return null;
    }

    public function findByVersion(string $version): ?array
    {
        foreach ($this->all() as $entry) {
            if (($entry['version'] ?? null) === $version) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function create(array $data): array
    {
        $now = Carbon::now()->toIso8601String();
        $entry = [
            'id' => $data['id'] ?? (string) Str::uuid7(),
            'version' => (string) ($data['version'] ?? ''),
            'title' => (string) ($data['title'] ?? ''),
            'body' => (string) ($data['body'] ?? ''),
            'status' => (string) ($data['status'] ?? 'draft'),
            'released_at' => $this->normalizeDate($data['released_at'] ?? null),
            'created_by_user_id' => isset($data['created_by_user_id'])
                ? (int) $data['created_by_user_id']
                : null,
            'source' => (string) ($data['source'] ?? 'markdown'),
            'created_at' => $now,
            'updated_at' => $now,
        ];

        $entries = $this->all();
        $entries[] = $entry;
        $this->writeRaw($entries);

        return $entry;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>|null
     */
    public function update(string $id, array $data): ?array
    {
        $entries = $this->all();
        $found = null;
        foreach ($entries as $i => $entry) {
            if (($entry['id'] ?? null) === $id) {
                $entries[$i] = array_merge($entry, $this->normalizePatch($data), [
                    'updated_at' => Carbon::now()->toIso8601String(),
                ]);
                $found = $entries[$i];
                break;
            }
        }

        if ($found === null) {
            return null;
        }

        $this->writeRaw($entries);

        return $found;
    }

    public function delete(string $id): bool
    {
        $entries = $this->all();
        $next = array_values(array_filter(
            $entries,
            fn (array $e) => ($e['id'] ?? null) !== $id,
        ));

        if (count($next) === count($entries)) {
            return false;
        }

        $this->writeRaw($next);

        return true;
    }

    /**
     * Idempotent upsert by version. Used by the markdown bootstrap
     * AND the artisan add-changelog-entry command. Existing rows
     * with the same version are updated; new versions land as
     * fresh rows. Status is preserved on existing rows unless the
     * caller explicitly sets it (so admin-edited drafts don't get
     * reverted to whatever the markdown frontmatter says).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function upsertByVersion(array $data): array
    {
        $version = (string) ($data['version'] ?? '');
        if ($version === '') {
            throw new \InvalidArgumentException('version is required');
        }

        $entries = $this->all();
        foreach ($entries as $i => $entry) {
            if (($entry['version'] ?? null) === $version) {
                $patch = $data;
                // Preserve existing status if the upsert call didn't
                // pass one — admin-published rows shouldn't get
                // demoted just because the source markdown still
                // says draft.
                if (! array_key_exists('status', $data)) {
                    unset($patch['status']);
                }
                $entries[$i] = array_merge(
                    $entry,
                    $this->normalizePatch($patch),
                    ['updated_at' => Carbon::now()->toIso8601String()],
                );
                $this->writeRaw($entries);

                return $entries[$i];
            }
        }

        return $this->create($data);
    }

    /**
     * @param  array<int, array<string, mixed>>  $entries
     * @return array{0: array<int, array<string, mixed>>, 1: bool}
     */
    private function bootstrapFromMarkdown(array $entries): array
    {
        // Empty string / null = bootstrap disabled (used in tests so
        // each fake disk starts empty). Otherwise resolve the directory.
        if ($this->bootstrapDir === null || $this->bootstrapDir === '') {
            return [$entries, false];
        }

        $source = $this->bootstrapDir;
        if (! is_dir($source)) {
            return [$entries, false];
        }

        $changed = false;

        foreach (glob($source.'/*.md') ?: [] as $path) {
            $parsed = $this->parseMarkdown($path);
            if ($parsed === null) {
                continue;
            }

            $existingIndex = null;
            foreach ($entries as $i => $entry) {
                if (($entry['version'] ?? null) === $parsed['version']) {
                    $existingIndex = $i;
                    break;
                }
            }

            // Brand-new version → insert.
            if ($existingIndex === null) {
                $now = Carbon::now()->toIso8601String();
                $entries[] = [
                    'id' => (string) Str::uuid7(),
                    'version' => $parsed['version'],
                    'title' => $parsed['title'],
                    'body' => $parsed['body'],
                    'status' => 'published',
                    'released_at' => $this->normalizeDate($parsed['released_at']),
                    'source' => 'markdown',
                    'created_by_user_id' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
                $changed = true;

                continue;
            }

            $existing = $entries[$existingIndex];

            // Admin has edited this version through the UI → don't
            // clobber their changes with the markdown content. The
            // controller stamps `source = 'admin'` whenever a human
            // touches an entry; everything else is fair game for
            // markdown-driven updates.
            if (($existing['source'] ?? 'markdown') === 'admin') {
                continue;
            }

            // Markdown-sourced entry whose body / title / released_at
            // hasn't drifted → no-op.
            $sameBody = (string) ($existing['body'] ?? '') === $parsed['body']
                && (string) ($existing['title'] ?? '') === $parsed['title']
                && (string) ($existing['released_at'] ?? '')
                    === (string) ($this->normalizeDate($parsed['released_at']) ?? '');
            if ($sameBody) {
                continue;
            }

            $entries[$existingIndex] = array_merge($existing, [
                'title' => $parsed['title'],
                'body' => $parsed['body'],
                'released_at' => $this->normalizeDate($parsed['released_at']),
                'source' => 'markdown',
                'updated_at' => Carbon::now()->toIso8601String(),
            ]);
            $changed = true;
        }

        return [$entries, $changed];
    }

    /**
     * Parse `database/changelog-entries/v*.md` files. Frontmatter
     * is YAML-ish (3 top-level keys: version, title, released_at).
     * Body is everything after the closing `---`.
     *
     * @return array{version: string, title: string, released_at: ?string, body: string}|null
     */
    private function parseMarkdown(string $path): ?array
    {
        $raw = (string) @file_get_contents($path);
        if ($raw === '') {
            return null;
        }

        if (! str_starts_with($raw, '---')) {
            // Frontmatter is required.
            return null;
        }

        $rest = substr($raw, 3);
        $closing = strpos($rest, "\n---");
        if ($closing === false) {
            return null;
        }

        $frontmatter = trim(substr($rest, 0, $closing));
        $body = ltrim(substr($rest, $closing + 4), "\n");

        $fields = ['version' => '', 'title' => '', 'released_at' => null];
        foreach (preg_split("/\r?\n/", $frontmatter) as $line) {
            if (preg_match('/^([a-z_]+)\s*:\s*(.+?)\s*$/', $line, $m)) {
                $key = $m[1];
                $value = trim($m[2], "\"'");
                if (array_key_exists($key, $fields)) {
                    $fields[$key] = $value;
                }
            }
        }

        if ($fields['version'] === '' || $fields['title'] === '') {
            return null;
        }

        return [
            'version' => (string) $fields['version'],
            'title' => (string) $fields['title'],
            'released_at' => $fields['released_at'],
            'body' => $body,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function readRaw(): array
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
            return [];
        }

        if (! is_array($decoded)) {
            return [];
        }

        return array_values($decoded);
    }

    /**
     * @param  array<int, array<string, mixed>>  $entries
     */
    private function writeRaw(array $entries): void
    {
        $payload = json_encode(
            array_values($entries),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );

        $this->fs()->put(self::FILENAME, $payload === false ? '[]' : $payload);
    }

    /**
     * @param  array<string, mixed>  $patch
     * @return array<string, mixed>
     */
    private function normalizePatch(array $patch): array
    {
        if (array_key_exists('released_at', $patch)) {
            $patch['released_at'] = $this->normalizeDate($patch['released_at']);
        }

        return $patch;
    }

    private function normalizeDate(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance($value)->toIso8601String();
        }

        try {
            return Carbon::parse((string) $value)->toIso8601String();
        } catch (\Throwable) {
            return null;
        }
    }

    private function fs(): Filesystem
    {
        return Storage::disk($this->disk);
    }
}
