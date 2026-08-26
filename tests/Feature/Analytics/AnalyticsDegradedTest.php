<?php

use Illuminate\Support\Facades\Schema;

test('analytics overview surfaces a degraded banner when a metric query throws', function () {
    ['user' => $user] = workspaceMemberWithAgent(
        ['role' => 'owner'],
        ['name' => 'Degraded Test Agent', 'is_published' => true],
    );

    // Drop a column the controller's safeBlock closures reference so the
    // very first query (Conversation::where('is_playground', false))
    // throws. DDL is auto-committed in MySQL/Postgres so we MUST restore
    // it in a finally block — RefreshDatabase wraps each test in a
    // transaction but DDL ignores the transaction, and a permanent drop
    // would brick every analytics test that follows.
    Schema::table('conversations', fn ($t) => $t->dropColumn('is_playground'));

    try {
        $this->actingAs($user)
            ->get('/app/analytics')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('app/analytics/index')
                ->where('degraded', true),
            );
    } finally {
        Schema::table('conversations', function ($t) {
            $t->boolean('is_playground')->default(false);
        });
    }
});
