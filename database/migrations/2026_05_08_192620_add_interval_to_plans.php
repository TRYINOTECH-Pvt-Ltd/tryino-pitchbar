<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-plan billing interval. Existing rows default to 'month'
     * — current installs are 100% monthly so this is a safe upgrade.
     *
     * Annual ("year") plans are admin-created side-by-side: an admin
     * who wants Monthly + Yearly variants creates two plan rows with
     * matching names ("Pro" / "Pro" annual) and different intervals.
     * The pricing page groups them by name and shows a Monthly/Yearly
     * toggle. Workspace.plan_id continues to point at exactly one row.
     */
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->string('interval', 16)->default('month')->after('price_cents');
            $table->index('interval');
        });
    }

    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->dropIndex(['interval']);
            $table->dropColumn('interval');
        });
    }
};
