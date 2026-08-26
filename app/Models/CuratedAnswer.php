<?php

namespace App\Models;

use App\Concerns\BelongsToAgent;
use App\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class CuratedAnswer extends Model
{
    use BelongsToAgent;
    use HasFactory;
    use HasUuidV7;

    protected $fillable = [
        'agent_id', 'question_pattern', 'answer', 'priority',
        'conditions', 'lang', 'enabled',
        // C3 KB surface: slug + title + visibility flag for the
        // public /kb/{workspace}/{slug} route.
        'slug', 'kb_title', 'kb_published',
    ];

    protected $casts = [
        'conditions' => 'array',
        'priority' => 'integer',
        'enabled' => 'boolean',
        'kb_published' => 'boolean',
    ];

    protected static function booted(): void
    {
        // Auto-derive a slug from the question pattern on save so
        // operators never have to type one manually. Idempotent on
        // re-save unless the operator explicitly cleared the slug.
        static::saving(function (self $row): void {
            if (empty($row->slug) && ! empty($row->question_pattern)) {
                $row->slug = self::deriveUniqueSlugFor(
                    (string) $row->question_pattern,
                    (string) $row->agent_id,
                    ignoreId: $row->id,
                );
            }
        });
    }

    public static function deriveUniqueSlugFor(string $source, string $agentId, ?string $ignoreId = null): string
    {
        $base = Str::slug($source);
        if ($base === '') {
            $base = 'article-'.Str::random(6);
        }

        $slug = $base;
        $i = 2;
        while (
            self::query()
                ->withoutGlobalScopes()
                ->where('agent_id', $agentId)
                ->where('slug', $slug)
                ->when($ignoreId !== null, fn ($q) => $q->where('id', '!=', $ignoreId))
                ->exists()
        ) {
            $slug = "{$base}-{$i}";
            $i++;
        }

        return $slug;
    }

    public function displayTitle(): string
    {
        $title = trim((string) $this->kb_title);

        return $title !== '' ? $title : (string) $this->question_pattern;
    }
}
