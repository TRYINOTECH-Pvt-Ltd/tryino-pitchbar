<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sources', function (Blueprint $table) {
            // AES-256-GCM ciphertext via Laravel's `encrypted:array` cast.
            // Used by SQL-database sources (host/port/db/user/password) so
            // sensitive bits never live in the `config` JSON column. Other
            // source types leave it null.
            $table->text('credentials_encrypted')->nullable()->after('config');
        });
    }

    public function down(): void
    {
        Schema::table('sources', function (Blueprint $table) {
            $table->dropColumn('credentials_encrypted');
        });
    }
};
