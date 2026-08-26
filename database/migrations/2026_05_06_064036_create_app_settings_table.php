<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Single-row settings table that stores DB-backed overrides for the
     * env-configured providers (Stripe, Cloudflare, OpenAI, OpenRouter,
     * Mail, branding). The AppSettingsOverrideServiceProvider reads
     * this row on boot and merges it into config() so the existing
     * service resolvers don't need to know where the value came from.
     *
     * Sensitive columns (api keys, mail password, webhook secrets) get
     * the `encrypted` cast on the model so the DB never holds plaintext.
     */
    public function up(): void
    {
        Schema::create('app_settings', function (Blueprint $table) {
            $table->id();

            // Stripe / Cashier
            $table->text('stripe_key')->nullable();
            $table->text('stripe_secret')->nullable();
            $table->text('stripe_webhook_secret')->nullable();
            $table->string('cashier_currency', 8)->nullable();

            // Cloudflare (Workers AI + Vectorize)
            $table->string('cloudflare_account_id')->nullable();
            $table->text('cloudflare_api_token')->nullable();
            $table->string('cloudflare_chat_model')->nullable();
            $table->string('cloudflare_embed_model')->nullable();
            $table->string('cloudflare_vectorize_index')->nullable();

            // OpenAI
            $table->text('openai_api_key')->nullable();
            $table->string('openai_chat_model')->nullable();
            $table->string('openai_embed_model')->nullable();

            // OpenRouter
            $table->text('openrouter_api_key')->nullable();
            $table->string('openrouter_chat_model')->nullable();

            // Provider routing
            $table->string('llm_provider', 32)->nullable();
            $table->string('vector_provider', 32)->nullable();

            // Mail
            $table->string('mail_driver', 32)->nullable();
            $table->string('mail_host')->nullable();
            $table->integer('mail_port')->nullable();
            $table->string('mail_encryption', 16)->nullable();
            $table->string('mail_username')->nullable();
            $table->text('mail_password')->nullable();
            $table->string('mail_from_address')->nullable();
            $table->string('mail_from_name')->nullable();

            // Branding (the "Powered by" footer in the widget)
            $table->string('pitchbar_brand_url')->nullable();
            $table->string('pitchbar_brand_label')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_settings');
    }
};
