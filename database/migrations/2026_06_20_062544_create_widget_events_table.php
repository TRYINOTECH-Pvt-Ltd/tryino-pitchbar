<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Widget reliability telemetry sink — the durable backing store for the
     * super-admin "Widget Monitor" dashboard. One row per failure/anomaly on
     * the visitor hot path (stream failures, provider failovers/outages,
     * tool-loop timeouts, retrieval failures, client-reported stalls) so an
     * operator can triage "what's breaking" without grepping laravel.log.
     *
     * Deliberately NOT a foreign-key-constrained table: this is a log sink,
     * and a telemetry insert must NEVER be rejected because a conversation /
     * agent was pruned or the id is partial — losing the error record is the
     * one outcome we can't accept. The reference columns are plain nullable
     * uuids (indexed for filtering), not `constrained()` foreign keys, and
     * error history intentionally survives conversation/agent deletion.
     */
    public function up(): void
    {
        Schema::create('widget_events', function (Blueprint $table) {
            $table->bigIncrements('id');

            // Tenancy references — nullable, UNconstrained (see class docblock).
            // workspace_id lets the super-admin filter the platform-wide feed
            // down to one tenant; agent/conversation pinpoint the failing turn.
            $table->uuid('workspace_id')->nullable();
            $table->uuid('agent_id')->nullable();
            $table->uuid('conversation_id')->nullable();

            // What happened + how bad. `type` is a stable slug
            // (stream_failed, provider_failover, provider_down,
            // tool_loop_timeout, retrieval_failed, client_stalled, …);
            // `severity` ∈ {error, warning, info}.
            $table->string('type', 64);
            $table->string('severity', 16)->default('error');

            // Which LLM provider was involved, when relevant (cloudflare,
            // openai, openrouter, byok) — drives the "is provider X down?"
            // read of the monitor.
            $table->string('provider', 32)->nullable();

            // Human-readable one-liner + structured context (exception class,
            // fallback that won, elapsed ms, client reason, route, …).
            $table->string('message', 1024)->nullable();
            $table->json('context')->nullable();

            // When the failure actually occurred (recorder stamps it at the
            // failure site; the async job may run later).
            $table->timestampTz('occurred_at')->useCurrent()->index();

            // Triage workflow — super-admin marks an event resolved once
            // understood/fixed. resolved_by → users.id (bigint), unconstrained
            // to keep this a pure log sink.
            $table->timestampTz('resolved_at')->nullable();
            $table->unsignedBigInteger('resolved_by')->nullable();

            $table->timestampsTz();

            // Hot read paths: the dashboard slices by type over a time window,
            // filters open vs resolved, and zooms into one tenant/agent.
            $table->index(['type', 'occurred_at']);
            $table->index(['severity', 'occurred_at']);
            $table->index(['resolved_at']);
            $table->index(['workspace_id', 'occurred_at']);
            $table->index(['agent_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('widget_events');
    }
};
