<?php

use App\Services\Cloudflare\ToMarkdownClient;
use App\Services\Parsers\CloudflareMarkdownParser;
use App\Services\Parsers\CsvParser;
use App\Services\Parsers\DocxParser;
use App\Services\Parsers\ParserRegistry;
use App\Services\Parsers\PdfParser;
use App\Services\Parsers\TextParser;
use Mockery as M;
use Tests\TestCase;

// Routing tests need the Laravel container to test bind/forget; opt
// into the Feature TestCase so $this->app is wired up.
uses(TestCase::class);

/**
 * ParserRegistry should pick the Cloudflare parser for binary office
 * formats when ToMarkdownClient is bound, and fall back to the local
 * PHP parsers when it isn't. Plain-text extensions (.md / .txt) and
 * .csv always stay on the local parsers — there's no value in
 * round-tripping them to Cloudflare.
 */
afterEach(fn () => M::close());

test('routes PDF/DOCX through Cloudflare parser when ToMarkdownClient is bound', function () {
    $this->app->bind(ToMarkdownClient::class, fn () => M::mock(ToMarkdownClient::class));

    $registry = new ParserRegistry(container: $this->app);

    expect($registry->for('pdf'))->toBeInstanceOf(CloudflareMarkdownParser::class);
    expect($registry->for('docx'))->toBeInstanceOf(CloudflareMarkdownParser::class);
    expect($registry->for('xlsx'))->toBeInstanceOf(CloudflareMarkdownParser::class);
});

test('falls back to in-process parsers when ToMarkdownClient is not bound', function () {
    // Tests bootstrap the container without a CF binding; verify we
    // hit the Smalot / PhpWord / League CSV path. This is the
    // 100%-backwards-compatible branch that protects buyers on the
    // OpenAI-only / BYOK setups.
    $this->app->forgetInstance(ToMarkdownClient::class);
    if ($this->app->bound(ToMarkdownClient::class)) {
        $this->app->offsetUnset(ToMarkdownClient::class);
    }

    $registry = new ParserRegistry(container: $this->app);

    expect($registry->for('pdf'))->toBeInstanceOf(PdfParser::class);
    expect($registry->for('docx'))->toBeInstanceOf(DocxParser::class);
});

test('CSV + plain text always use the local parsers regardless of CF binding', function () {
    $this->app->bind(ToMarkdownClient::class, fn () => M::mock(ToMarkdownClient::class));

    $registry = new ParserRegistry(container: $this->app);

    expect($registry->for('csv'))->toBeInstanceOf(CsvParser::class);
    expect($registry->for('md'))->toBeInstanceOf(TextParser::class);
    expect($registry->for('txt'))->toBeInstanceOf(TextParser::class);
});

test('returns null for unknown extension', function () {
    $registry = new ParserRegistry(container: $this->app);

    expect($registry->for('xyz'))->toBeNull();
});

test('binding throwing at resolve-time (missing CF creds) silently falls back', function () {
    // AppServiceProvider registers a closure that throws when CF
    // creds aren't set. The registry must catch that and route to
    // the local parsers instead of bubbling the error up to the
    // upload handler.
    $this->app->bind(ToMarkdownClient::class, function () {
        throw new RuntimeException('no Cloudflare creds');
    });

    $registry = new ParserRegistry(container: $this->app);

    expect($registry->for('pdf'))->toBeInstanceOf(PdfParser::class);
});
