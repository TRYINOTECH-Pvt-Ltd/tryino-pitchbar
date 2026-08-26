<?php

namespace App\Providers;

use App\Models\AppSetting;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;

/**
 * Reads the single-row `app_settings` table on boot and merges any
 * non-null columns into config(). The downstream service resolvers
 * (Stripe via Cashier, OpenAiClient binding in AppServiceProvider,
 * Mail via the framework, branding via config('branding')) all read
 * config() rather than env() — so admin saves take effect on the
 * next request without a deploy.
 *
 * Boots before AppServiceProvider (because it's registered first in
 * bootstrap/providers.php). Skips silently when the table doesn't
 * exist yet (fresh install, between migrations) or if the DB is
 * unreachable, so config:cache → migrate sequences don't blow up.
 */
class AppSettingsOverrideServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if (! $this->canReadSettings()) {
            return;
        }

        try {
            $s = AppSetting::singleton();
        } catch (\Throwable) {
            return;
        }

        $this->applyStripe($s);
        $this->applyPayPal($s);
        $this->applyRazorpay($s);
        $this->applyCloudflare($s);
        $this->applyOpenAi($s);
        $this->applyOpenRouter($s);
        $this->applyProviderRouting($s);
        $this->applyMail($s);
        $this->applyBranding($s);
    }

    /**
     * Don't even try to read AppSetting during artisan migrate, fresh
     * installs, or testing environments where the schema may not exist
     * yet. Schema::hasTable is wrapped in try because some DB drivers
     * (sqlite in-memory) may also throw before init.
     */
    private function canReadSettings(): bool
    {
        if ($this->app->runningInConsole()
            && in_array($_SERVER['argv'][1] ?? '', ['migrate', 'migrate:fresh', 'migrate:install', 'migrate:reset', 'migrate:rollback', 'migrate:status', 'package:discover'], true)) {
            return false;
        }

        try {
            return Schema::hasTable('app_settings');
        } catch (\Throwable) {
            return false;
        }
    }

    private function applyStripe(AppSetting $s): void
    {
        if ($s->stripe_key) {
            config(['cashier.key' => $s->stripe_key]);
        }

        if ($s->stripe_secret) {
            config(['cashier.secret' => $s->stripe_secret]);
        }

        if ($s->stripe_webhook_secret) {
            config(['cashier.webhook.secret' => $s->stripe_webhook_secret]);
        }

        if ($s->cashier_currency) {
            config(['cashier.currency' => $s->cashier_currency]);
        }

        // `stripe_enabled` defaults to true so existing installs keep working
        // without the admin having to flip a flag — the column itself defaults
        // to true at the schema level, so we just mirror it onto config.
        config(['cashier.enabled' => (bool) $s->stripe_enabled]);
    }

    private function applyPayPal(AppSetting $s): void
    {
        if ($s->paypal_mode) {
            config(['services.paypal.mode' => $s->paypal_mode]);
        }

        if ($s->paypal_client_id) {
            config(['services.paypal.client_id' => $s->paypal_client_id]);
        }

        if ($s->paypal_client_secret) {
            config(['services.paypal.client_secret' => $s->paypal_client_secret]);
        }

        if ($s->paypal_webhook_id) {
            config(['services.paypal.webhook_id' => $s->paypal_webhook_id]);
        }

        config(['services.paypal.enabled' => (bool) $s->paypal_enabled]);
    }

    private function applyRazorpay(AppSetting $s): void
    {
        if ($s->razorpay_key_id) {
            config(['services.razorpay.key_id' => $s->razorpay_key_id]);
        }

        if ($s->razorpay_key_secret) {
            config(['services.razorpay.key_secret' => $s->razorpay_key_secret]);
        }

        if ($s->razorpay_webhook_secret) {
            config(['services.razorpay.webhook_secret' => $s->razorpay_webhook_secret]);
        }

        config(['services.razorpay.enabled' => (bool) $s->razorpay_enabled]);
    }

    private function applyCloudflare(AppSetting $s): void
    {
        if ($s->cloudflare_account_id) {
            config(['services.cloudflare.account_id' => $s->cloudflare_account_id]);
        }

        if ($s->cloudflare_api_token) {
            config(['services.cloudflare.api_token' => $s->cloudflare_api_token]);
        }

        if ($s->cloudflare_chat_model) {
            config(['services.cloudflare.chat_model' => $s->cloudflare_chat_model]);
        }

        if ($s->cloudflare_embed_model) {
            config(['services.cloudflare.embed_model' => $s->cloudflare_embed_model]);
        }

        if ($s->cloudflare_vectorize_index) {
            config(['services.cloudflare.vectorize_index' => $s->cloudflare_vectorize_index]);
        }

        if ($s->cloudflare_ai_gateway_url) {
            config(['services.cloudflare.ai_gateway_url' => $s->cloudflare_ai_gateway_url]);
        }

        // Browser Rendering is a boolean toggle — admins can disable it
        // when they hit Cloudflare's free-tier daily cap. Always mirror
        // the DB value (boolean cast guarantees true/false, never null).
        config(['services.cloudflare.browser_rendering' => (bool) $s->cloudflare_browser_rendering]);
    }

    private function applyOpenAi(AppSetting $s): void
    {
        if ($s->openai_api_key) {
            config(['services.openai.key' => $s->openai_api_key]);
        }

        if ($s->openai_chat_model) {
            config(['services.openai.chat_model' => $s->openai_chat_model]);
        }

        if ($s->openai_embed_model) {
            config(['services.openai.embed_model' => $s->openai_embed_model]);
        }
    }

    private function applyOpenRouter(AppSetting $s): void
    {
        if ($s->openrouter_api_key) {
            config(['services.openrouter.key' => $s->openrouter_api_key]);
        }

        if ($s->openrouter_chat_model) {
            config(['services.openrouter.chat_model' => $s->openrouter_chat_model]);
        }
    }

    private function applyProviderRouting(AppSetting $s): void
    {
        if ($s->llm_provider) {
            config(['services.llm.provider' => $s->llm_provider]);
        }

        if ($s->vector_provider) {
            config(['services.vector.provider' => $s->vector_provider]);
        }
    }

    private function applyMail(AppSetting $s): void
    {
        if ($s->mail_driver) {
            config(['mail.default' => $s->mail_driver]);
        }

        $driver = (string) config('mail.default');

        if ($s->mail_host) {
            config(["mail.mailers.{$driver}.host" => $s->mail_host]);
        }

        if ($s->mail_port !== null) {
            config(["mail.mailers.{$driver}.port" => $s->mail_port]);
        }

        if ($s->mail_encryption) {
            config(["mail.mailers.{$driver}.encryption" => $s->mail_encryption]);
        }

        if ($s->mail_username) {
            config(["mail.mailers.{$driver}.username" => $s->mail_username]);
        }

        if ($s->mail_password) {
            config(["mail.mailers.{$driver}.password" => $s->mail_password]);
        }

        if ($s->mail_from_address) {
            config(['mail.from.address' => $s->mail_from_address]);
        }

        if ($s->mail_from_name) {
            config(['mail.from.name' => $s->mail_from_name]);
        }
    }

    private function applyBranding(AppSetting $s): void
    {
        if (trim((string) $s->site_title) !== '') {
            config([
                'branding.site_title' => $s->site_title,
                'app.name' => $s->site_title,
            ]);
        }

        if ($s->header_logo_path) {
            config(['branding.header_logo_path' => $s->header_logo_path]);
        }

        if ($s->footer_logo_path) {
            config(['branding.footer_logo_path' => $s->footer_logo_path]);
        }

        if ($s->dashboard_logo_path) {
            config(['branding.dashboard_logo_path' => $s->dashboard_logo_path]);
        }

        if ($s->favicon_path) {
            config(['branding.favicon_path' => $s->favicon_path]);
        }

        if ($s->pitchbar_brand_url) {
            config(['branding.url' => $s->pitchbar_brand_url]);
        }

        if ($s->pitchbar_brand_label) {
            config(['branding.label' => $s->pitchbar_brand_label]);
        }
    }
}
