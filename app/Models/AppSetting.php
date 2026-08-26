<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * Singleton row holding admin-editable provider config (Stripe, CF,
 * OpenAI, mail, branding). Always exactly one row, id=1.
 *
 * NOTE on caching: an earlier version cached the resolved Eloquent
 * model via Cache::remember. That blew up in production because a
 * stale serialized blob would deserialize to __PHP_Incomplete_Class
 * after deploys / schema changes / cache survives + class evolves —
 * and the strict return type on singleton() then threw a TypeError
 * the caller couldn't recover from. We now query on every call.
 * One indexed PK lookup per request boot is well below the noise
 * floor for the typical traffic pattern, and the page that needs
 * settings (admin /settings/system) is itself low-traffic.
 *
 * Sensitive columns are cast as `encrypted` — Laravel's app-key-based
 * AES wrapper. The DB never holds plaintext API keys, even if dumped.
 */
class AppSetting extends Model
{
    public const SINGLETON_ID = 1;

    private const LEGACY_CACHE_KEY = 'app_settings.singleton';

    protected $guarded = ['id', 'created_at', 'updated_at'];

    protected $casts = [
        // Sensitive — encrypt at rest. Plain Stripe public key (`stripe_key`)
        // and PayPal `client_id` / Razorpay `key_id` are intentionally NOT
        // encrypted; they're publishable identifiers by design.
        'stripe_secret' => 'encrypted',
        'stripe_webhook_secret' => 'encrypted',
        'paypal_client_secret' => 'encrypted',
        'razorpay_key_secret' => 'encrypted',
        'razorpay_webhook_secret' => 'encrypted',
        'cloudflare_api_token' => 'encrypted',
        'openai_api_key' => 'encrypted',
        'openrouter_api_key' => 'encrypted',
        'mail_password' => 'encrypted',
        'internal_queue_token' => 'encrypted',
        'marketing_home_content' => 'array',
        'pricing_faqs' => 'array',
        'pricing_matrix' => 'array',
        'integrations_enabled' => 'array',
        'integration_cards' => 'array',
        'privacy_policy_content' => 'array',
        'widget_defaults' => 'array',
        'auth_aside_bullets' => 'array',
        'cron_worker_deployed_at' => 'datetime',
        'cron_worker_last_status_at' => 'datetime',
        'cron_worker_last_status' => 'array',
        'stripe_enabled' => 'boolean',
        'paypal_enabled' => 'boolean',
        'razorpay_enabled' => 'boolean',
        'marketing_site_enabled' => 'boolean',
        'marketing_widget_enabled' => 'boolean',
        'marketing_theme' => 'string',
        'require_email_verification' => 'boolean',
        'byok_enabled_globally' => 'boolean',
        'admin_daily_digest_enabled' => 'boolean',
        'cloudflare_browser_rendering' => 'boolean',
    ];

    /**
     * Resolve the single row, creating it on first access.
     */
    public static function singleton(): self
    {
        return self::firstOrCreate(['id' => self::SINGLETON_ID]);
    }

    /**
     * Kept as a hook for callers that wrote to the row — historically
     * cleared the singleton cache. Now also forgets any leftover
     * cache entry from the prior cached implementation, which is the
     * fix path for installs that hit the __PHP_Incomplete_Class bug.
     */
    public static function flushSingleton(): void
    {
        try {
            Cache::forget(self::LEGACY_CACHE_KEY);
        } catch (\Throwable) {
            // Cache backend down — the next read won't hit a stale
            // entry anyway since we no longer write one.
        }
    }
}
