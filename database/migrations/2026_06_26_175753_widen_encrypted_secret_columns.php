<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Widen the two secret columns that the 2026_05_16 encrypt-at-rest sweep
 * left too narrow. Encrypting a value with Laravel's `encrypted` cast
 * produces a base64 JSON envelope (~250+ chars even for a short secret),
 * but these columns kept their plaintext-era widths:
 *
 *   - workspace_api_tokens.shopper_signing_secret : VARCHAR(64)  -> TEXT
 *   - webhook_subscriptions.secret                : VARCHAR(128) -> TEXT
 *
 * On MySQL the overflow surfaced as
 * `SQLSTATE[22001] Data too long for column 'shopper_signing_secret'`
 * the first time a token was created after the cast shipped. SQLite
 * ignores VARCHAR length, which is why local/dev/CI never caught it.
 *
 * The two webhook-URL siblings (workspaces.slack_webhook_url /
 * teams_webhook_url) were created at VARCHAR(1024) and comfortably fit an
 * encrypted URL, so they're left untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workspace_api_tokens', function (Blueprint $table) {
            // Nullable preserved — a token without a shopper secret is valid.
            $table->text('shopper_signing_secret')->nullable()->change();
        });

        Schema::table('webhook_subscriptions', function (Blueprint $table) {
            // NOT NULL preserved — a subscription always has a signing secret.
            $table->text('secret')->change();
        });
    }

    public function down(): void
    {
        Schema::table('workspace_api_tokens', function (Blueprint $table) {
            $table->string('shopper_signing_secret', 64)->nullable()->change();
        });

        Schema::table('webhook_subscriptions', function (Blueprint $table) {
            $table->string('secret', 128)->change();
        });
    }
};
