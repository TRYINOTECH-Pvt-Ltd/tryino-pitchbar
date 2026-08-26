<?php

namespace App\Concerns;

use Illuminate\Support\Str;

trait HasUuidV7
{
    public static function bootHasUuidV7(): void
    {
        static::creating(function ($model): void {
            if (empty($model->getKey())) {
                $model->{$model->getKeyName()} = (string) Str::uuid7();
            }
        });
    }

    public function getKeyType(): string
    {
        return 'string';
    }

    public function getIncrementing(): bool
    {
        return false;
    }
}
