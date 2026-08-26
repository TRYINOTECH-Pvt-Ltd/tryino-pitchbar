<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-resource limits beyond the AI rate-limit dials we already had
 * (monthly_conversations / monthly_messages / max_tokens_per_response).
 *
 * Buyer ask: admins want to differentiate plan tiers by capping how many
 * agents, KB sources, workflows, integrations, members the workspace
 * can have — plus a flag to gate API token access on paid tiers only.
 *
 * Semantics: NULL = unlimited (every grandfathered plan starts here so
 * existing customers don't get retroactively capped). 0 = blocked
 * entirely (useful for "Free tier" with no API access at all). A
 * positive integer caps at that count.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->unsignedInteger('agents_limit')->nullable()->after('monthly_messages');
            $table->unsignedInteger('sources_limit')->nullable()->after('agents_limit');
            $table->unsignedInteger('workflows_limit')->nullable()->after('sources_limit');
            $table->unsignedInteger('integrations_limit')->nullable()->after('workflows_limit');
            $table->unsignedInteger('members_limit')->nullable()->after('integrations_limit');
            $table->boolean('api_access')->default(true)->after('members_limit');
        });
    }

    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn([
                'agents_limit',
                'sources_limit',
                'workflows_limit',
                'integrations_limit',
                'members_limit',
                'api_access',
            ]);
        });
    }
};
