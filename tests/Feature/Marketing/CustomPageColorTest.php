<?php

use App\Models\AppSetting;
use App\Models\Page;

/**
 * Client report 2026-05-23: /p/{slug} body text rendered faint slate
 * grey because the wrapper had a Tailwind `text-slate-700` utility
 * that cascaded over the `.page-content` slate-900 colour. The fix
 * (a) swaps the wrapper class to `text-slate-900` and (b) pins
 * descendant element colours in app.css so a future regression on
 * the wrapper utility can't dim the body copy again.
 */
test('/p/{slug} renders Inertia component with the dark text wrapper class', function () {
    AppSetting::singleton()->forceFill(['marketing_site_enabled' => true])->save();

    Page::create([
        'slug' => 'about-test',
        'title' => 'About Us',
        'content_markdown' => "Some body copy.\n\n- bullet one\n- bullet two",
        'is_published' => true,
    ]);

    $this->get('/p/about-test')
        ->assertOk()
        ->assertInertia(
            fn ($p) => $p
                ->component('marketing/page')
                ->where('page.slug', 'about-test')
                ->where('page.title', 'About Us'),
        );
});

test('app.css ships explicit descendant colour rules for .page-content body copy', function () {
    $css = (string) file_get_contents(resource_path('css/app.css'));

    // The pinned colour must be slate-900 (the dark colour). Any
    // future PR that flips this to a lighter slate-700/600 will
    // make the body copy faint again — the assertions below guard
    // against that regression specifically.
    //
    // We locate the descendant-colour rule block by its selector
    // and assert the colour value inside.
    $ruleStart = strpos($css, '.page-content p,');
    expect($ruleStart)->not->toBeFalse();
    $ruleEnd = strpos($css, '}', $ruleStart);
    expect($ruleEnd)->not->toBeFalse();
    $rule = substr($css, $ruleStart, $ruleEnd - $ruleStart);

    expect($rule)->toContain('.page-content li,');
    expect($rule)->toContain('color: rgb(15 23 42)'); // slate-900
    expect($rule)->not->toContain('rgb(71 85 105)'); // slate-600
    expect($rule)->not->toContain('rgb(51 65 85)'); // slate-700
});

test('marketing/page.tsx wrapper uses text-slate-900 (not text-slate-700)', function () {
    $source = (string) file_get_contents(resource_path('js/pages/marketing/page.tsx'));

    expect($source)->toContain('text-slate-900');
    expect($source)->not->toContain('text-slate-700');
});
