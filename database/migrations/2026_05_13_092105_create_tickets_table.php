<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * C3: HelpCenter / Ticketing primitive.
 *
 * A `Ticket` is the durable side of a support interaction — outlives
 * the chat session that spawned it. The LLM tool `open_ticket` creates
 * one when the help_center vertical's `ticketing` capability is
 * enabled; CTAs of kind `open_ticket` do the same one-click from
 * operator-curated buttons. Operators work tickets from /app/tickets.
 *
 * Multi-tenant: `workspace_id` carries the BelongsToWorkspace global
 * scope so cross-tenant queries can't accidentally surface another
 * customer's tickets.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('tickets');
        Schema::create('tickets', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->char('workspace_id', 36)->index();
            $table->char('agent_id', 36)->nullable();
            $table->char('conversation_id', 36)->nullable();
            $table->string('subject', 200);
            $table->text('body');
            $table->string('status', 24)->default('open');
            $table->string('priority', 16)->default('normal');
            $table->unsignedBigInteger('assigned_to_user_id')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->timestamp('resolved_at')->nullable();

            $table->index(['workspace_id', 'status']);
            $table->index(['workspace_id', 'priority']);
            $table->index(['workspace_id', 'assigned_to_user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tickets');
    }
};
