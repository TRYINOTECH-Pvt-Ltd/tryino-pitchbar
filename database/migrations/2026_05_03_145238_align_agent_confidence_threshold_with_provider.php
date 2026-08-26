<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The original column default of 0.78 was tuned for OpenAI's
 * text-embedding-3-small. For Cloudflare's bge-base-en-v1.5 — which the app
 * defaults to — relevant matches typically score 0.50–0.65, so 0.78 silently
 * filters every retrieval, leaving the agent answering "I don't have enough
 * information" even when its sources contain the answer.
 *
 * This migration:
 *   - rewrites every existing agent that's still on the old default (0.78)
 *     to the env-appropriate value;
 *   - leaves agents the user has explicitly tuned to anything else
 *     (e.g. 0.65, 0.85) untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        $target = (float) config('services.rag.confidence_threshold', 0.5);

        DB::table('agents')
            ->where('confidence_threshold', 0.78)
            ->update(['confidence_threshold' => $target]);
    }

    public function down(): void
    {
        $target = (float) config('services.rag.confidence_threshold', 0.5);

        DB::table('agents')
            ->where('confidence_threshold', $target)
            ->update(['confidence_threshold' => 0.78]);
    }
};
