<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workspace_api_tokens', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained()->cascadeOnDelete();
            // users.id is bigInteger (Laravel's default `$table->id()`).
            // foreignUuid would declare char(36) which MySQL rejects on
            // FK creation as "incompatible column types". Mirror the
            // users table column type so the FK is valid on both
            // MySQL and Postgres.
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name');
            // sha256 hex digest of the plaintext token; plaintext is shown to
            // the issuer once at creation and never persisted.
            $table->string('token_hash', 64)->unique();
            $table->json('abilities');
            $table->timestampTz('last_used_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->timestampsTz();

            $table->index(['workspace_id', 'revoked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workspace_api_tokens');
    }
};
