<?php

use App\Models\AppSetting;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * First-install backfill: copy Cloudflare + LLM/Vector provider values
 * from .env into the singleton app_settings row so the admin UI is
 * pre-populated. After this migration runs once, the admin can rotate
 * credentials via /settings/system and remove the keys from .env.
 *
 * Only fills NULL columns — won't overwrite admin-saved values on
 * re-run / fresh-install-after-edit. Buyer-reported (2026-05-21):
 * customer shouldn't need to edit .env when the UI exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        $row = AppSetting::query()->firstOrCreate(['id' => AppSetting::SINGLETON_ID]);

        $copies = [
            'cloudflare_account_id' => env('CLOUDFLARE_ACCOUNT_ID'),
            'cloudflare_api_token' => env('CLOUDFLARE_API_TOKEN'),
            'cloudflare_chat_model' => env('CLOUDFLARE_CHAT_MODEL'),
            'cloudflare_embed_model' => env('CLOUDFLARE_EMBED_MODEL'),
            'cloudflare_vectorize_index' => env('CLOUDFLARE_VECTORIZE_INDEX'),
            'cloudflare_ai_gateway_url' => env('CLOUDFLARE_AI_GATEWAY_URL'),
            'llm_provider' => env('LLM_PROVIDER'),
            'vector_provider' => env('VECTOR_PROVIDER'),
        ];

        $dirty = false;
        foreach ($copies as $column => $envValue) {
            if (! Schema::hasColumn('app_settings', $column)) {
                continue;
            }
            if (empty($envValue)) {
                continue;
            }
            if (! empty($row->{$column})) {
                continue;
            }
            $row->{$column} = $envValue;
            $dirty = true;
        }

        // Browser Rendering is a boolean that defaults to true at the
        // schema level. Only override when the env explicitly opts out.
        if (Schema::hasColumn('app_settings', 'cloudflare_browser_rendering')
            && env('CLOUDFLARE_BROWSER_RENDERING') !== null
        ) {
            $row->cloudflare_browser_rendering = (bool) env('CLOUDFLARE_BROWSER_RENDERING');
            $dirty = true;
        }

        if ($dirty) {
            $row->save();
            AppSetting::flushSingleton();
        }
    }

    public function down(): void
    {
        // No-op — leave admin-saved values intact on rollback.
    }
};
