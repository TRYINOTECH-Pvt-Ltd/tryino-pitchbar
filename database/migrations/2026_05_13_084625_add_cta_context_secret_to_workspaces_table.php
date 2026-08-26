<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Workspace-scoped secret used by `link_with_context` CTAs to sign the
 * outbound query payload (HMAC SHA-256). The receiving site copies the
 * same secret out of Settings → API tokens and verifies via
 * `hash_equals(hash_hmac('sha256', $raw, $secret), $sig)`.
 *
 * Encrypted at rest via Laravel's `encrypted` cast on the Workspace
 * model so a database leak doesn't expose every customer's downstream
 * signing key. The plaintext value is shown to the workspace owner
 * exactly once when they reveal it (same pattern as Stripe webhook
 * secrets).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            $table->text('cta_context_secret')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            $table->dropColumn('cta_context_secret');
        });
    }
};
