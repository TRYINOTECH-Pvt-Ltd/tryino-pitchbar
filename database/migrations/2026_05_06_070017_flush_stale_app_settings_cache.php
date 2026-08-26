<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;

/**
 * One-shot cleanup for installs that hit the __PHP_Incomplete_Class
 * TypeError bug from the prior cached AppSetting::singleton() — a
 * stale serialized model survived in cache and broke the type return
 * after the model class evolved. The singleton() method no longer
 * caches; we just need to evict whatever the old code stored under
 * the legacy key. Idempotent: a no-op when the cache miss.
 */
return new class extends Migration
{
    public function up(): void
    {
        try {
            Cache::forget('app_settings.singleton');
        } catch (Throwable) {
            // Cache driver down or unconfigured at migrate-time —
            // safe to skip; the new singleton() doesn't read cache.
        }
    }

    public function down(): void
    {
        // Nothing to roll back.
    }
};
