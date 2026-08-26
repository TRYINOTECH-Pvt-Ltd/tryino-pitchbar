<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * In-chat Stripe Checkout sessions. One row per <checkout/> block the
 * widget renders. Lifecycle: created (session minted) → paid (webhook)
 * → expired (Stripe TTL hit) or canceled. workspace_id pins the row
 * for tenant isolation; agent_id + conversation_id give us the chat
 * context so the post-pay broadcast can fire on the right channel.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('in_chat_payments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('workspace_id');
            $table->uuid('agent_id');
            $table->uuid('conversation_id');

            // Stripe session reference. `cs_test_...` / `cs_live_...`.
            // Unique so a re-emitted block can't double-bill.
            $table->string('stripe_session_id', 191)->unique();
            $table->string('stripe_payment_intent', 191)->nullable();

            // Buyer-visible amount + currency on the card.
            $table->unsignedInteger('amount_cents');
            $table->string('currency', 8);
            $table->string('title', 255);
            $table->text('description')->nullable();

            // 'created' → 'paid' | 'expired' | 'canceled'
            $table->string('status', 24)->default('created');

            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->index(['workspace_id', 'created_at']);
            $table->index(['conversation_id']);
            $table->index(['status', 'created_at']);

            $table->foreign('workspace_id')
                ->references('id')->on('workspaces')
                ->cascadeOnDelete();
            $table->foreign('agent_id')
                ->references('id')->on('agents')
                ->cascadeOnDelete();
            $table->foreign('conversation_id')
                ->references('id')->on('conversations')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('in_chat_payments');
    }
};
