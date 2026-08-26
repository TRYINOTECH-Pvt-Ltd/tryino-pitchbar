<?php

use App\Support\SafeMarkdown;

/**
 * Hard-asserts every stored-XSS payload that landed during the
 * security audit. `Illuminate\Support\Str::markdown` previously
 * rendered each of these as live HTML; SafeMarkdown must strip the
 * dangerous parts while preserving the safe markdown structure
 * around them.
 *
 * If anyone "fixes" SafeMarkdown by re-enabling raw HTML, these
 * tests turn red immediately — the regression doesn't slip out.
 */
beforeEach(fn () => SafeMarkdown::flush());

test('strips raw <script> tags from operator markdown', function () {
    $out = SafeMarkdown::render("Hi.\n\n<script>alert(1)</script>\n\nBye.");

    expect($out)
        ->not->toContain('<script>')
        ->not->toContain('alert(1)')
        ->toContain('Hi.')
        ->toContain('Bye.');
});

test('strips inline event-handler attributes like onerror', function () {
    $out = SafeMarkdown::render('<img src=x onerror=alert(1)>');

    // html_input=strip drops the entire <img> tag along with its
    // payload — verified via the converter's structural output.
    expect($out)
        ->not->toContain('<img')
        ->not->toContain('onerror')
        ->not->toContain('alert(1)');
});

test('rejects javascript: URIs in markdown links', function () {
    $out = SafeMarkdown::render('[click me](javascript:alert(1))');

    // commonmark's allow_unsafe_links=false drops the href attribute
    // entirely while preserving the link text — visitors see "click me"
    // as plain text, NOT a clickable JS exec.
    expect($out)
        ->not->toContain('javascript:')
        ->not->toContain('alert(1)')
        ->toContain('click me');
});

test('rejects vbscript: and data: URIs in markdown links', function () {
    $vb = SafeMarkdown::render('[x](vbscript:msgbox(1))');
    $data = SafeMarkdown::render('[x](data:text/html,<script>alert(1)</script>)');

    expect($vb)->not->toContain('vbscript:');
    expect($data)
        ->not->toContain('data:text/html')
        ->not->toContain('<script>');
});

test('preserves safe markdown structure (headings, bold, lists, code)', function () {
    $input = <<<'MD'
    # Heading One

    Paragraph with **bold** and *italic* and `inline code`.

    - bullet a
    - bullet b

    1. ordered one
    2. ordered two

    ```
    fenced code block
    ```
    MD;

    $out = SafeMarkdown::render($input);

    expect($out)
        ->toContain('<h1>Heading One</h1>')
        ->toContain('<strong>bold</strong>')
        ->toContain('<em>italic</em>')
        ->toContain('<code>inline code</code>')
        ->toContain('<li>bullet a</li>')
        ->toContain('<li>ordered one</li>')
        ->toContain('<pre><code>');
});

test('preserves http(s) and relative links', function () {
    $out = SafeMarkdown::render(
        "[https](https://example.com)\n\n[http](http://example.com)\n\n[rel](/docs/page)"
    );

    expect($out)
        ->toContain('href="https://example.com"')
        ->toContain('href="http://example.com"')
        ->toContain('href="/docs/page"');
});

test('strips iframe + object tags that smuggle remote content', function () {
    $out = SafeMarkdown::render('<iframe src="https://evil.example/"></iframe><object data="x"></object>');

    expect($out)
        ->not->toContain('<iframe')
        ->not->toContain('<object')
        ->not->toContain('evil.example');
});

test('strips <svg> with embedded scripts', function () {
    // html_input=strip removes the tags but preserves any text
    // nodes between them ("alert(1)" survives as inert text). The
    // security goal is no executable HTML/JS — verify the tags are
    // gone; the leftover literal "alert(1)" string is harmless.
    $out = SafeMarkdown::render('<svg><script>alert(1)</script></svg>');

    expect($out)
        ->not->toContain('<svg')
        ->not->toContain('<script');
});

test('empty input renders empty', function () {
    expect(SafeMarkdown::render(''))->toBe('');
});

test('flush() resets the memoised converter (used by test setup)', function () {
    SafeMarkdown::render('# warmup');
    SafeMarkdown::flush();

    // After flush the next render must still succeed — the converter
    // is rebuilt lazily.
    expect(SafeMarkdown::render('# again'))->toContain('<h1>again</h1>');
});
