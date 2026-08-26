<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lets the customer pick WHEN the in-bar lead form appears.
 *
 *   - engagement (default): high-intent keyword OR turn ≥ 3 (legacy).
 *   - first_turn:           prompt on the visitor's very first message.
 *   - keyword_only:         only high-intent keyword, never engagement.
 *   - never:                disabled; admin handles lead capture some other way.
 *
 * Buyer Dovydas hit this with "I set 'Lead form fields' but I'm not
 * sure when it should show up. It doesn't show up right away." — the
 * legacy engagement gate is intentional but undiscoverable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            $table->string('lead_prompt_strategy', 32)
                ->default('engagement')
                ->after('lead_form_fields');
        });
    }

    public function down(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            $table->dropColumn('lead_prompt_strategy');
        });
    }
};
