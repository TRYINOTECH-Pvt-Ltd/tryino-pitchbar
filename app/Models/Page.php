<?php

namespace App\Models;

use App\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Admin-managed custom content page. Public at /p/{slug} when published.
 * Markdown body is rendered server-side via the same converter the
 * /documentation/* pages use — no raw HTML injection.
 */
class Page extends Model
{
    use HasFactory;
    use HasUuidV7;

    protected $fillable = [
        'slug', 'title', 'content_markdown', 'is_published', 'sort_order',
    ];

    protected $casts = [
        'is_published' => 'boolean',
        'sort_order' => 'integer',
    ];
}
