<?php

use App\Enums\PlatformRole;
use App\Models\Page;
use App\Models\User;

/**
 * Admin custom content pages CRUD + the public /p/{slug} renderer.
 * Buyer ask: operator wants to author footer pages (About, Company)
 * without editing Blade files.
 */
function pagesAdmin(): User
{
    return User::factory()->create([
        'email' => 'pages-admin@example.com',
        'role' => PlatformRole::SuperAdmin,
    ]);
}

test('admin can preview markdown render via the JSON endpoint', function () {
    $this->actingAs(pagesAdmin())
        ->postJson('/admin/pages/preview', [
            'content_markdown' => "# Heading\n\n- item",
        ])
        ->assertOk()
        ->assertJson(fn ($json) => $json
            ->has('html')
            ->whereType('html', 'string'));
});

test('preview endpoint rejects non-super_admin', function () {
    $user = User::factory()->create(['role' => PlatformRole::Customer]);

    $response = $this->actingAs($user)
        ->postJson('/admin/pages/preview', ['content_markdown' => '# Hi']);

    expect($response->status())->toBeIn([302, 403, 404]);
});

test('preview endpoint escapes raw HTML for safety', function () {
    $response = $this->actingAs(pagesAdmin())
        ->postJson('/admin/pages/preview', [
            'content_markdown' => 'Hello <script>alert(1)</script>',
        ]);
    $response->assertOk();
    $html = (string) $response->json('html');
    expect($html)->not->toContain('<script>alert(1)</script>');
    expect($html)->toContain('&lt;script&gt;');
});

test('admin index returns the page list', function () {
    Page::create([
        'slug' => 'about',
        'title' => 'About us',
        'content_markdown' => '# Hello',
        'is_published' => true,
    ]);

    $this->actingAs(pagesAdmin())
        ->get('/admin/pages')
        ->assertOk()
        ->assertInertia(fn ($p) => $p
            ->component('admin/pages/index')
            ->has('pages', 1)
            ->where('pages.0.slug', 'about')
            ->where('pages.0.public_url', '/p/about'));
});

test('admin can create a page with a custom slug', function () {
    $this->actingAs(pagesAdmin())
        ->post('/admin/pages', [
            'title' => 'Company',
            'slug' => 'company',
            'content_markdown' => '## About the company',
            'is_published' => true,
        ])
        ->assertRedirect('/admin/pages');

    $page = Page::query()->where('slug', 'company')->first();
    expect($page)->not->toBeNull();
    expect($page->title)->toBe('Company');
    expect($page->is_published)->toBeTrue();
});

test('admin store auto-derives slug from the title when omitted', function () {
    $this->actingAs(pagesAdmin())
        ->post('/admin/pages', [
            'title' => 'Our Story',
            'content_markdown' => 'Body',
        ])
        ->assertRedirect();

    $page = Page::query()->where('title', 'Our Story')->first();
    expect($page->slug)->toBe('our-story');
});

test('admin store ensures slug uniqueness on collision', function () {
    Page::create([
        'slug' => 'about',
        'title' => 'About',
        'content_markdown' => 'x',
        'is_published' => false,
    ]);

    $this->actingAs(pagesAdmin())
        ->post('/admin/pages', [
            'title' => 'About',
            'content_markdown' => 'y',
        ])
        ->assertRedirect();

    expect(Page::query()->where('slug', 'about-2')->exists())->toBeTrue();
});

test('admin can update a page', function () {
    $page = Page::create([
        'slug' => 'about',
        'title' => 'Old',
        'content_markdown' => 'Old body',
        'is_published' => false,
    ]);

    $this->actingAs(pagesAdmin())
        ->patch("/admin/pages/{$page->id}", [
            'title' => 'New',
            'content_markdown' => 'New body',
            'is_published' => true,
            'slug' => 'about',
        ])
        ->assertRedirect();

    $page->refresh();
    expect($page->title)->toBe('New');
    expect($page->is_published)->toBeTrue();
});

test('admin can delete a page', function () {
    $page = Page::create([
        'slug' => 'about',
        'title' => 'About',
        'content_markdown' => 'x',
    ]);

    $this->actingAs(pagesAdmin())
        ->delete("/admin/pages/{$page->id}")
        ->assertRedirect();

    expect(Page::query()->where('id', $page->id)->exists())->toBeFalse();
});

test('public /p/{slug} renders published page', function () {
    Page::create([
        'slug' => 'about',
        'title' => 'About us',
        'content_markdown' => '# Hello world',
        'is_published' => true,
    ]);

    $this->get('/p/about')
        ->assertOk()
        ->assertSee('About us')
        ->assertSee('Hello world', false);
});

test('public /p/{slug} renders through the React MarketingShell so nav + footer match the landing pages', function () {
    Page::create([
        'slug' => 'about-us',
        'title' => 'About us',
        'content_markdown' => '# Welcome',
        'is_published' => true,
    ]);

    // Pre-fix custom pages went through a separate Blade layout
    // (resources/views/marketing/_layout.blade.php) which shipped a
    // different header + footer than the React-rendered marketing
    // pages — client report 2026-05-22, header/footer mismatch
    // screenshots. After fix they render via Inertia +
    // `MarketingShell`, so the page payload must arrive as an Inertia
    // response with the standard `shell` shape and the `marketing/page`
    // component name.
    $response = $this->get('/p/about-us')->assertOk();
    $payload = $response->viewData('page');

    expect($payload['component'])->toBe('marketing/page');
    expect($payload['props'])->toHaveKey('shell');
    expect($payload['props']['shell'])->toHaveKey('nav_items');
    expect($payload['props']['shell'])->toHaveKey('footer');
    expect($payload['props']['page'])->toMatchArray([
        'slug' => 'about-us',
        'title' => 'About us',
    ]);
    expect($payload['props']['page']['html'])->toContain('Welcome');
});

test('public /p/{slug} 404s for an unpublished page', function () {
    Page::create([
        'slug' => 'draft',
        'title' => 'Draft',
        'content_markdown' => 'secret',
        'is_published' => false,
    ]);

    $this->get('/p/draft')->assertStatus(404);
});

test('public /p/{slug} escapes inline HTML', function () {
    Page::create([
        'slug' => 'xss-test',
        'title' => 'XSS test',
        'content_markdown' => '<script>alert("xss")</script>',
        'is_published' => true,
    ]);

    $response = $this->get('/p/xss-test')->assertOk();
    expect((string) $response->getContent())
        ->not->toContain('<script>alert');
});

test('customer cannot reach admin pages CRUD (404 to hide existence)', function () {
    $customer = User::factory()->create(['role' => PlatformRole::Customer]);

    $this->actingAs($customer)->get('/admin/pages')->assertStatus(404);
    $this->actingAs($customer)
        ->post('/admin/pages', [
            'title' => 'Sneaky',
            'content_markdown' => 'x',
        ])
        ->assertStatus(404);
});
