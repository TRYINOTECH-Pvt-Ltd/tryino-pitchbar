<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integration_connections', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('kind');
            $table->text('credentials_encrypted')->nullable();
            $table->json('config')->nullable();
            $table->string('status')->default('active');
            $table->timestampTz('last_sync_at')->nullable();
            $table->timestampsTz();

            $table->index(['workspace_id', 'kind']);
        });

        Schema::create('webhook_subscriptions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('url', 1000);
            $table->string('secret', 128);
            $table->json('events')->nullable();
            $table->boolean('enabled')->default(true);
            $table->timestampsTz();

            $table->index(['workspace_id', 'enabled']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_subscriptions');
        Schema::dropIfExists('integration_connections');
    }
};
