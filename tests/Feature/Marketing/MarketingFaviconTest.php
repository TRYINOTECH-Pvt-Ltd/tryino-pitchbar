<?php

use App\Models\Page;

test('marketing /p/{slug} page emits a favicon link in head', function () {
    Page::create([
        'slug' => 'about',
        'title' => 'About us',
        'content_markdown' => '# Hello',
        'is_published' => true,
    ]);

    $html = $this->get('/p/about')->assertOk()->getContent();

    expect($html)->toMatch('#<link\s+[^>]*rel="icon"[^>]*>#i');
});

test('marketing /privacy page emits a favicon link in head', function () {
    $html = $this->get('/privacy')->assertOk()->getContent();

    expect($html)->toMatch('#<link\s+[^>]*rel="icon"[^>]*>#i');
});
