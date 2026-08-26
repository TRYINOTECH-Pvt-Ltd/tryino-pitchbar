<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workspace_api_tokens', function (Blueprint $table) {
            // Per-token shared signing secret for short-lived shopper
            // tokens issued by CMS adapters (WordPress plugin etc.).
            // Stored plaintext because (a) it never authenticates a
            // request on its own — it only signs an opaque shopper
            // claim that is itself ignored on signature mismatch and
            // (b) the plugin needs the plaintext to sign. Bearer token
            // remains hash-only.
            $table->string('shopper_signing_secret', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('workspace_api_tokens', function (Blueprint $table) {
            $table->dropColumn('shopper_signing_secret');
        });
    }
};
