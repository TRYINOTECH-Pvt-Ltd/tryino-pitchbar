<?php

namespace App\Models;

use App\Services\I18n\TranslationOverrides;
use Illuminate\Database\Eloquent\Model;

/**
 * One admin override for a translation string: (locale, key) → value.
 * `key_sha1` is derived automatically so the long English-source key can
 * be uniquely indexed. Layered over lang/{locale}.json at runtime by
 * {@see TranslationOverrides}.
 *
 * @property string $locale
 * @property string $key
 * @property string $value
 */
class TranslationOverride extends Model
{
    protected $fillable = [
        'locale',
        'key',
        'value',
        'updated_by',
    ];

    protected static function booted(): void
    {
        static::saving(function (TranslationOverride $override): void {
            $override->key_sha1 = sha1((string) $override->key);
        });
    }
}
