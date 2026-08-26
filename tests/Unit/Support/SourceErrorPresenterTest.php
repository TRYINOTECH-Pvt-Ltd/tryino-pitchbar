<?php

use App\Support\SourceErrorPresenter;

test('returns null for an empty error', function () {
    expect(SourceErrorPresenter::present(null))->toBeNull();
    expect(SourceErrorPresenter::present(''))->toBeNull();
    expect(SourceErrorPresenter::present('   '))->toBeNull();
});

test('hides Cloudflare Browser Rendering 401 with a customer-safe line', function () {
    $raw = 'Crawl failed: Cloudflare Browser Rendering HTTP 401: {"result":null,"success":false,"errors":[{"code":10000,"message":"Authentication error"}],"messages":[]}';

    $msg = SourceErrorPresenter::present($raw);

    expect($msg)->not->toContain('Cloudflare');
    expect($msg)->not->toContain('401');
    expect($msg)->not->toContain('Authentication');
    expect($msg)->toContain('crawl service is temporarily unavailable');
});

test('hides Cloudflare 429 rate-limit body', function () {
    $raw = 'Crawl failed: Cloudflare Browser Rendering rate-limited (429).';

    $msg = SourceErrorPresenter::present($raw);

    expect($msg)->not->toContain('Cloudflare');
    expect($msg)->not->toContain('429');
    expect($msg)->toContain('busy right now');
});

test('hides 404 from the target site with a friendly line', function () {
    $raw = 'Crawl failed: HTTP 404 — page not found at https://example.com/missing';

    $msg = SourceErrorPresenter::present($raw);

    expect($msg)->not->toContain('https://example.com');
    expect($msg)->toContain('404');
    expect($msg)->toContain("couldn't find this page");
});

test('hides cURL timeout body', function () {
    $raw = 'Crawl failed: cURL error 28: Operation timed out after 30001 milliseconds';

    $msg = SourceErrorPresenter::present($raw);

    expect($msg)->not->toContain('cURL');
    expect($msg)->not->toContain('28');
    expect($msg)->toContain("couldn't reach this page");
});

test('returns a generic fallback for an unrecognised internal error', function () {
    $raw = 'Crawl failed: Something obscure happened deep in the parser';

    $msg = SourceErrorPresenter::present($raw);

    expect($msg)->toBe("Couldn't index this page.");
    expect($msg)->not->toContain('parser');
});

test('SSRF guard surfaces a safety-oriented message', function () {
    $raw = 'Crawl failed: Forbidden host 169.254.169.254 (cloud metadata endpoint)';

    $msg = SourceErrorPresenter::present($raw);

    expect($msg)->not->toContain('169.254');
    expect($msg)->toContain('safety reasons');
});

test('spreadsheet uploads without Cloudflare get the actionable CF + CSV hint', function () {
    $raw = 'Unsupported file type: quarterly-report.xlsx (Spreadsheet / OpenDocument formats need Cloudflare Workers AI — set CLOUDFLARE_ACCOUNT_ID and CLOUDFLARE_API_TOKEN.)';

    $msg = SourceErrorPresenter::present($raw);

    expect($msg)->toContain('Cloudflare Workers AI');
    expect($msg)->toContain('CSV');
});

test('unrelated "unsupported file type" errors fall through to the generic copy', function () {
    $raw = 'Unsupported file type: payload.exe';

    $msg = SourceErrorPresenter::present($raw);

    expect($msg)->toContain('This file type is not supported');
    expect($msg)->not->toContain('Cloudflare');
});

test('a revoked Google token maps to the reconnect advice, not the catch-all', function () {
    $msg = SourceErrorPresenter::present(
        'Google API error: Refresh failed: Token has been expired or revoked.',
    );

    expect($msg)->toContain('Reconnect Google')
        ->and($msg)->toContain('Reindex')
        ->and($msg)->not->toBe("Couldn't index this page.");
});

test('a Google Doc the connected account cannot open maps to share-the-doc advice', function () {
    $msg = SourceErrorPresenter::present(
        'Google API error: getDoc metadata failed: File not found: 1AbC.',
    );

    expect($msg)->toContain('Share it with that account');
});

test('a Google export 403 maps to the share advice, not the generic HTTP line', function () {
    $msg = SourceErrorPresenter::present('Google API error: export failed: HTTP 403');

    expect($msg)->toContain('Share it with that account');
});

test('a non-Doc Google file maps to the Docs-only advice', function () {
    $msg = SourceErrorPresenter::present(
        'Google API error: File is not a Google Doc (mimeType=application/vnd.google-apps.spreadsheet).',
    );

    expect($msg)->toContain("isn't a Google Doc");
});

test('an empty Google Doc keeps its own actionable line', function () {
    $msg = SourceErrorPresenter::present('Google Doc is empty or too short to index.');

    expect($msg)->toContain('empty or too short');
});

test('a crawl error that merely embeds a google.com URL does NOT hit the Google branch', function () {
    $msg = SourceErrorPresenter::present(
        'Crawl failed: Could not reach this URL after 3 attempts: https://www.google.com/some-page',
    );

    expect($msg)->not->toContain('Reconnect Google')
        ->and($msg)->not->toContain('Share it with that account');
});

test('the legacy "No URL configured" reindex scar maps to click-reindex advice', function () {
    $msg = SourceErrorPresenter::present('No URL configured');

    expect($msg)->toContain('Click Reindex')
        ->and($msg)->not->toBe("Couldn't index this page.");
});

test('a docs.google.com crawl error diagnoses the wrong source type, whatever the proximate failure', function () {
    // The "it's a Google Doc" diagnosis must beat the 429/JS-required
    // rules that the crawl error also matches.
    $msg = SourceErrorPresenter::present(
        'Crawl failed: Every crawler tier failed for https://docs.google.com/document/d/1xnTuS/edit: {"plain_http":"HTTP 429","cf_browser":"please enable javascript"}',
    );

    expect($msg)->toContain('Google Doc')
        ->and($msg)->toContain('Google Docs tab');
});
