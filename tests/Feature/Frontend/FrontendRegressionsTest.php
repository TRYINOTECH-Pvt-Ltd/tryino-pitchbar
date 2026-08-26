<?php

/**
 * Pure-Pest regression guards for buyer-reported UI bugs that ship as
 * CSS / TypeScript-only fixes. The components are exercised in real
 * usage by the Inertia React pages they back; this file pins the
 * specific class-name / API choices in place so a future "let me clean
 * up Tailwind classes" pass can't silently regress the buyer's repro.
 */
test('B2: lead form builder keeps long-title chips on a single row via flex-wrap + min-w-0', function () {
    $component = (string) file_get_contents(
        base_path('resources/js/components/agents/lead-form-builder.tsx'),
    );

    // Repro: a buyer with very long form-field labels saw the badge row
    // overflow into the body copy. The fix wraps the row + truncates
    // each chip. Both classes must remain present.
    expect($component)->toContain('flex-wrap')
        ->and($component)->toContain('min-w-0')
        ->and($component)->toContain('truncate')
        ->and($component)->toContain('shrink-0');
});

test('B3: customize page uses two-column grid with sticky preview', function () {
    $component = (string) file_get_contents(
        base_path('resources/js/pages/app/agents/customize.tsx'),
    );

    // Repro: the live-preview pane scrolled away from the form on tall
    // screens. The fix uses a min(640px,1fr)+min(360px,440px) grid and
    // pins the preview with lg:sticky lg:top-4. If any of these tokens
    // disappear the layout regresses to the old single-column scroll.
    expect($component)
        ->toContain('minmax(640px,1fr)')
        ->and($component)
        ->toContain('minmax(360px,440px)')
        ->and($component)
        ->toContain('lg:sticky')
        ->and($component)
        ->toContain('lg:top-4')
        ->and($component)
        ->toContain('auto-rows-fr');
});

test('B4: sidebar persists open/closed state across reloads via cookie + localStorage', function () {
    $component = (string) file_get_contents(
        base_path('resources/js/components/ui/sidebar.tsx'),
    );

    // Repro: refreshing the page lost the collapsed sidebar state.
    // The fix reads + writes both a Lax-SameSite cookie (so SSR can
    // see it) AND localStorage (so the client can read it before the
    // cookie round-trips). Both helpers + their key must remain.
    expect($component)
        ->toContain('readPersistedSidebarOpen')
        ->and($component)
        ->toContain('persistSidebarOpen')
        ->and($component)
        ->toContain('SameSite=Lax');
});

test('widget Bar exposes always-visible "Talk to a human" header button', function () {
    $bar = (string) file_get_contents(
        base_path('resources/widget/src/ui/Bar.tsx'),
    );

    // Repro: visitors trying to reach a human couldn't find a button
    // because the only entry point was an LLM-emitted escalation block
    // (which the Workers AI model often failed to trigger). The fix
    // hoists requestHumanHandoff() to the Bar component scope + adds
    // a header button keyed off data-pitchbar-talk-to-human, gated on
    // !isHumanHandling + !humanRequestedAt so it disappears once the
    // visitor's already in the queue.
    expect($bar)
        ->toContain('requestHumanHandoff')
        ->and($bar)
        ->toContain('data-pitchbar-talk-to-human')
        ->and($bar)
        ->toContain("tr('Talk to a human')");
});
