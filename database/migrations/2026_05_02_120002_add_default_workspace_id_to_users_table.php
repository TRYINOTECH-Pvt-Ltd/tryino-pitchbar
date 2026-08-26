<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignUuid('default_workspace_id')->nullable()->after('email')
                ->constrained('workspaces')->nullOnDelete();
            $table->string('avatar_url')->nullable()->after('default_workspace_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('default_workspace_id');
            $table->dropColumn('avatar_url');
        });
    }
};
