<?php

namespace App\Models;

use App\Services\Changelog\ChangelogStore;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Application-wide release notes. Platform-level entries authored by
 * super-admins; published entries fan out to /changelog and the
 * /changelog.json feed.
 *
 * Storage is a JSON file at `storage/app/private/changelog-entries.json`
 * (NOT a database table) — same pattern as the internal Kanban board.
 * `php artisan migrate:fresh` during development never touches storage,
 * so release notes survive every schema reset and factory tear-down.
 *
 * On the very first read of any environment, the store seeds the
 * file from `database/changelog-entries/v*.md` source markdown
 * shipped in git, so a fresh deploy gets the latest release notes
 * without a separate command.
 *
 * This class is a thin DTO + static repository facade. It is NOT
 * Eloquent — consumers call static factory + finder methods on the
 * class itself. The instance methods (save, update, delete, etc.)
 * write back through the underlying ChangelogStore.
 */
class ChangelogEntry
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_PUBLISHED = 'published';

    public const STATUS_ARCHIVED = 'archived';

    public string $id;

    public string $version;

    public string $title;

    public string $body;

    public string $status;

    public ?Carbon $released_at;

    public ?int $created_by_user_id;

    /**
     * Where this entry came from: 'markdown' (auto-seeded from
     * database/changelog-entries/v*.md) or 'admin' (created or
     * edited via the admin UI). The bootstrap re-seeds markdown-
     * sourced entries when the source file changes; admin-sourced
     * entries are left alone forever.
     */
    public string $source;

    public Carbon $created_at;

    public Carbon $updated_at;

    /**
     * @param  array<string, mixed>  $row
     */
    public function __construct(array $row)
    {
        $this->id = (string) ($row['id'] ?? '');
        $this->version = (string) ($row['version'] ?? '');
        $this->title = (string) ($row['title'] ?? '');
        $this->body = (string) ($row['body'] ?? '');
        $this->status = (string) ($row['status'] ?? self::STATUS_DRAFT);
        $this->released_at = $this->parseDate($row['released_at'] ?? null);
        $this->created_by_user_id = isset($row['created_by_user_id'])
            ? (int) $row['created_by_user_id']
            : null;
        $this->source = (string) ($row['source'] ?? 'markdown');
        $this->created_at = $this->parseDate($row['created_at'] ?? null) ?? Carbon::now();
        $this->updated_at = $this->parseDate($row['updated_at'] ?? null) ?? Carbon::now();
    }

    public function isPublished(): bool
    {
        return $this->status === self::STATUS_PUBLISHED;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'version' => $this->version,
            'title' => $this->title,
            'body' => $this->body,
            'status' => $this->status,
            'released_at' => $this->released_at?->toIso8601String(),
            'created_by_user_id' => $this->created_by_user_id,
            'source' => $this->source,
            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
        ];
    }

    // ─── Repository facade ─────────────────────────────────────────

    /**
     * @return Collection<int, self>
     */
    public static function all(): Collection
    {
        return collect(static::store()->all())
            ->map(fn (array $row) => new self($row));
    }

    /**
     * @return Collection<int, self>
     */
    public static function published(): Collection
    {
        return collect(static::store()->published())
            ->map(fn (array $row) => new self($row));
    }

    /**
     * @return Collection<int, self>
     */
    public static function allOrderedForAdmin(): Collection
    {
        return collect(static::store()->allOrderedForAdmin())
            ->map(fn (array $row) => new self($row));
    }

    public static function findById(string $id): ?self
    {
        $row = static::store()->findById($id);

        return $row === null ? null : new self($row);
    }

    public static function findByVersion(string $version): ?self
    {
        $row = static::store()->findByVersion($version);

        return $row === null ? null : new self($row);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function create(array $data): self
    {
        return new self(static::store()->create($data));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function upsertByVersion(array $data): self
    {
        return new self(static::store()->upsertByVersion($data));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(array $data): self
    {
        $row = static::store()->update($this->id, $data);
        if ($row !== null) {
            $this->__construct($row);
        }

        return $this;
    }

    /**
     * Eloquent-compatibility shim: callers used to do
     * `$entry->forceFill([...])->save()`. We keep the same surface
     * but route both calls into the underlying store.
     *
     * @param  array<string, mixed>  $data
     */
    public function forceFill(array $data): self
    {
        $this->__pendingPatch = $data;

        return $this;
    }

    public function save(): self
    {
        if (! empty($this->__pendingPatch)) {
            $this->update($this->__pendingPatch);
            $this->__pendingPatch = [];
        }

        return $this;
    }

    public function delete(): bool
    {
        return static::store()->delete($this->id);
    }

    /**
     * Implementation of Carbon parsing for the constructor + updates.
     */
    private function parseDate(mixed $value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }
        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance($value);
        }

        try {
            return Carbon::parse((string) $value);
        } catch (\Throwable) {
            return null;
        }
    }

    /** @var array<string, mixed> */
    private array $__pendingPatch = [];

    private static function store(): ChangelogStore
    {
        return app(ChangelogStore::class);
    }
}
