<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bring Your Own API Key (BYOK) — 3-tier model:
 *
 *   - App-level toggle. `app_settings.byok_enabled_globally` (already
 *     stored in the existing settings JSON, no new column needed). When
 *     ON: every workspace MUST supply its own keys; platform keys are
 *     never resolved.
 *
 *   - Per-user override. `users.byok_enabled` tri-state nullable
 *     boolean. NULL = inherit the global flag. TRUE = force-enable
 *     BYOK for this user regardless of global state. FALSE = force-
 *     disable BYOK for this user regardless of global state.
 *
 *   - Per-workspace keys. `workspaces.byok_keys` JSON, encrypted at
 *     rest via Laravel's `encrypted` cast on the Workspace model.
 *     Shape: { cloudflare_account_id, cloudflare_api_token,
 *              cloudflare_vectorize_index, openai_api_key,
 *              openai_chat_model, openai_embed_model,
 *              openrouter_api_key, openrouter_chat_model,
 *              qdrant_url, qdrant_api_key, qdrant_collection }
 *
 * Resolver (App\Support\ByokResolver) consults these in order and the
 * LLM / Vector providers route to workspace credentials when BYOK is
 * unlocked for the visitor's workspace. See `byok` documentation page
 * for the full matrix.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('byok_enabled')->nullable();
        });

        Schema::table('workspaces', function (Blueprint $table) {
            $table->json('byok_keys')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('byok_enabled');
        });

        Schema::table('workspaces', function (Blueprint $table) {
            $table->dropColumn('byok_keys');
        });
    }
};
