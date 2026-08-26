<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-agent custom lead-form schema. Stored as a JSON array of
     * field definitions:
     *
     *   [
     *     {"key":"name","label":"Your name","type":"text","required":false},
     *     {"key":"email","label":"Work email","type":"email","required":true},
     *     {"key":"company","label":"Company","type":"text","required":true,"maxlength":120},
     *     {"key":"team_size","label":"Team size","type":"select","required":false,"options":["1-10","11-50","51-200","201+"]},
     *     {"key":"consent","label":"I agree to be contacted","type":"checkbox","required":true}
     *   ]
     *
     * NULL = use the default Name + Email shape (current behaviour).
     * The widget renders this list both in the inline mid-conversation
     * lead form AND in the pre-chat gate (#10) — same renderer, two
     * mount points.
     */
    public function up(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            $table->json('lead_form_fields')->nullable()->after('require_lead_before_chat');
        });
    }

    public function down(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            $table->dropColumn('lead_form_fields');
        });
    }
};
