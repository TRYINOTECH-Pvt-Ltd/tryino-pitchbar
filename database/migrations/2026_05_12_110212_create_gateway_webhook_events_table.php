<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Webhook idempotency log. Every payment-gateway webhook event records
 * (gateway, event_id) once it's been processed; subsequent retries
 * (Stripe retries on >20s response, PayPal retries up to 25 times,
 * Razorpay up to 5) short-circuit at the controller without
 * re-applying state changes.
 *
 * Stripe's `event.id`, PayPal's `event.id`, and Razorpay's
 * `payload.payment.entity.id` (or subscription entity id) are stable
 * across retries so the unique constraint catches duplicates
 * deterministically.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gateway_webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('gateway', 16);        // stripe | paypal | razorpay
            $table->string('event_id', 191);
            $table->string('event_type')->nullable();
            $table->timestamp('processed_at')->useCurrent();

            $table->unique(['gateway', 'event_id']);
            $table->index('processed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gateway_webhook_events');
    }
};
