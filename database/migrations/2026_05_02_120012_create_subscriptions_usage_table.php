<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // App-level subscription mirror (Cashier publishes its own tables separately).
        Schema::create('plan_subscriptions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignUuid('plan_id')->nullable()->constrained()->nullOnDelete();
            $table->string('stripe_subscription_id')->nullable()->index();
            $table->string('status')->default('active');
            $table->timestampTz('current_period_end')->nullable();
            $table->boolean('cancel_at_period_end')->default(false);
            $table->timestampsTz();
        });

        Schema::create('usage_events', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignUuid('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('kind');
            $table->integer('quantity')->default(1);
            $table->json('meta')->nullable();
            $table->timestampTz('occurred_at');
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['workspace_id', 'occurred_at']);
            $table->index(['workspace_id', 'kind', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('usage_events');
        Schema::dropIfExists('plan_subscriptions');
    }
};
