<?php

use App\Support\DocumentationNav;

test('documentation index renders the welcome page', function () {
    $this->get('/documentation')
        ->assertOk()
        ->assertSee('Welcome', false)
        ->assertSee('docs-page-title', false)
        ->assertSee('docs-nav-link', false);
});

test('every nav slug resolves to a 200', function () {
    foreach (DocumentationNav::flat() as $slug => $meta) {
        $response = $this->get("/documentation/{$slug}");
        $response->assertOk();
        // assertSeeText decodes HTML entities so titles with & match
        // the rendered &amp; without each test caring about the encoding.
        $response->assertSeeText($meta['title']);
    }
});

test('a known slug returns 200 with the right title', function () {
    $this->get('/documentation/quickstart')
        ->assertOk()
        ->assertSee('Quickstart', false)
        ->assertSee('Get started', false);
});

test('an unknown slug returns 404', function () {
    $this->get('/documentation/this-page-does-not-exist')
        ->assertStatus(404);
});

test('the route does not require authentication', function () {
    // No login, no workspace — public on purpose.
    $this->get('/documentation/architecture')->assertOk();
    $this->get('/documentation/widget-api')->assertOk();
});
