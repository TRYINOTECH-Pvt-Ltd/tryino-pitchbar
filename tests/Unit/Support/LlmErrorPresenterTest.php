<?php

use App\Support\LlmErrorPresenter;

test('null / empty inputs short-circuit', function () {
    expect(LlmErrorPresenter::present(null))->toBeNull();
    expect(LlmErrorPresenter::present(''))->toBeNull();
    expect(LlmErrorPresenter::present('   '))->toBeNull();
});

test('Workers AI 401 chat envelope translates to actionable account/token-mismatch hint', function () {
    $raw = 'Workers AI 401: {"success":false,"result":[],"messages":[],"error":[{"code":2009,"message":"Unauthorized"}]}';

    $msg = LlmErrorPresenter::present($raw);

    expect($msg)->not->toContain('"success":false');
    expect($msg)->not->toContain('"code":2009');
    expect($msg)->toContain('CLOUDFLARE_ACCOUNT_ID');
    expect($msg)->toContain('401');
});

test('Workers AI embed 401 envelope gets the same actionable hint', function () {
    $raw = 'Workers AI embed 401: {"success":false,"result":[],"messages":[],"error":[{"code":2009,"message":"Unauthorized"}]}';

    $msg = LlmErrorPresenter::present($raw);

    expect($msg)->toContain('CLOUDFLARE_ACCOUNT_ID');
    expect($msg)->not->toContain('{"success"');
});

test('Workers AI 403 hints at account/model scope mismatch', function () {
    $raw = 'Workers AI 403: {"success":false,"error":[{"code":10000,"message":"Forbidden"}]}';

    $msg = LlmErrorPresenter::present($raw);

    expect($msg)->toContain('CLOUDFLARE_ACCOUNT_ID');
    expect($msg)->toContain('403');
});

test('Workers AI 404 points at the configured model slugs', function () {
    $raw = 'Workers AI 404: {"success":false,"error":[{"code":7000,"message":"Not found"}]}';

    $msg = LlmErrorPresenter::present($raw);

    expect($msg)->toContain('CLOUDFLARE_CHAT_MODEL');
    expect($msg)->toContain('CLOUDFLARE_EMBED_MODEL');
});

test('Workers AI 429 reads as a rate-limit and suggests retrying', function () {
    $raw = 'Workers AI 429: {"success":false,"error":[{"code":1015,"message":"Rate limited"}]}';

    $msg = LlmErrorPresenter::present($raw);

    expect($msg)->toContain('rate-limit');
    expect($msg)->not->toContain('{"success"');
});

test('Workers AI timeout reads as a transient cold-start', function () {
    $raw = 'Workers AI timeout: took 504 seconds';

    $msg = LlmErrorPresenter::present($raw);

    expect($msg)->toContain('retry');
});

test('Workers AI 5xx surfaces a Cloudflare-status pointer', function () {
    $raw = 'Workers AI 503: {"success":false,"error":[{"message":"Service Unavailable"}]}';

    $msg = LlmErrorPresenter::present($raw);

    expect($msg)->toContain('Cloudflare');
    expect($msg)->toContain('cloudflarestatus.com');
});

test('OpenAI-style invalid-key error gets a clean rotation hint', function () {
    $raw = 'OpenAI 401: Incorrect API key provided: sk-...redacted';

    $msg = LlmErrorPresenter::present($raw);

    expect(strtolower($msg))->toContain('rotate');
    expect($msg)->not->toContain('sk-');
});

test('quota errors map to the billing-dashboard hint', function () {
    $raw = 'Workers AI 402: {"error":[{"message":"insufficient_quota"}]}';

    $msg = LlmErrorPresenter::present($raw);

    expect($msg)->toContain('quota');
    expect($msg)->toContain('billing');
});

test('cURL DNS-resolution error points at DNS + outbound networking', function () {
    $raw = 'cURL error 6: Could not resolve host: api.cloudflare.com';

    $msg = LlmErrorPresenter::present($raw);

    expect($msg)->toContain('outbound');
    expect($msg)->toContain('DNS');
});

// blengi 2026-06-26: the Playground "very slow + frequently shows
// 'check your firewall/DNS'" report. The real cause was a slow /
// cold-starting Llama 3.3 70B overrunning the per-call timeout — Guzzle
// raises "cURL error 28: Operation timed out", which used to fall into
// the firewall/DNS branch and send the operator chasing a network ghost.
// A timeout is a SLOW provider, not a misconfigured network.
test('a cURL read timeout reads as a slow provider, not a firewall/DNS fault', function () {
    $raw = 'cURL error 28: Operation timed out after 25000 milliseconds with 0 bytes received';

    $msg = LlmErrorPresenter::present($raw);

    expect($msg)->toContain('timed out');
    // The headline bug: a timeout must NOT be reported as an outbound
    // network-config problem the way the old single branch did.
    expect($msg)->not->toContain('outbound');
    // …and it must hand the operator the real next step.
    expect($msg)->toContain('faster');
});

test('a Guzzle connect timeout is also treated as a slow provider', function () {
    $raw = 'cURL error 28: Connection timed out after 5001 milliseconds';

    $msg = LlmErrorPresenter::present($raw);

    expect($msg)->toContain('timed out');
    expect($msg)->not->toContain('outbound');
});

test('connection-refused maps to a firewall/connect hint, distinct from DNS', function () {
    $raw = 'cURL error 7: Failed to connect to api.cloudflare.com port 443: Connection refused';

    $msg = LlmErrorPresenter::present($raw);

    expect(strtolower($msg))->toContain('firewall');
    expect($msg)->not->toContain('DNS lookup');
    expect($msg)->not->toContain('timed out');
});

test('an unclassified cURL transport error still gets a generic network line', function () {
    $raw = 'cURL error 35: SSL connect error';

    $msg = LlmErrorPresenter::present($raw);

    expect(strtolower($msg))->toContain('network');
    expect($msg)->toContain('TLS');
});

test('unrecognised Workers AI HTTP code is sanitised to a code-only line', function () {
    $raw = 'Workers AI 418: {"success":false,"error":[{"message":"I am a teapot"}]}';

    $msg = LlmErrorPresenter::present($raw);

    expect($msg)->toContain('418');
    expect($msg)->not->toContain('teapot');
});

test('totally unrecognised errors are truncated, never leaked verbatim past 200 chars', function () {
    $raw = str_repeat('A', 5000);

    $msg = LlmErrorPresenter::present($raw);

    expect(mb_strlen($msg))->toBeLessThanOrEqual(201);
});

// Buyer-reported (Lucian, 2026-05-15): playground showed
//   "Vectorize POST indexes/whispbar-chunks/query failed:
//    {"result":null,"success":false,"errors":[{"code":40006,"message":
//    "invalid query vector, expected 768 dimensions, and got 1024..."
// Now translates into an actionable rebuild-index hint.
test('Vectorize dim-mismatch envelope translates to the rebuild-index hint', function () {
    $raw = 'Vectorize POST indexes/whispbar-chunks/query failed: {"result":null,"result_info":null,"success":false,"errors":[{"code":40006,"message":"invalid query vector, expected 768 dimensions, and got 1024 dimensions"}],"messages":[]}';

    $msg = LlmErrorPresenter::present($raw);

    expect($msg)->toContain('vector:rebuild-index');
    expect($msg)->not->toContain('"success":false');
    expect($msg)->not->toContain('"code":40006');
});

test('Vectorize upsert 40012 also hits the rebuild-index branch', function () {
    $raw = 'Vectorize POST indexes/whispbar-chunks/upsert failed: {"errors":[{"code":40012,"message":"invalid vector for id=\"x\", expected 768 dimensions, and got 1024 dimensions"}]}';

    $msg = LlmErrorPresenter::present($raw);

    expect($msg)->toContain('vector:rebuild-index');
});

test('Vectorize 401 envelope translates to the token-rotation hint', function () {
    $raw = 'Vectorize POST indexes/foo failed: 401 Unauthorized';

    $msg = LlmErrorPresenter::present($raw);

    expect($msg)->toContain('Vectorize');
    expect($msg)->toContain('CLOUDFLARE_API_TOKEN');
});

test('Vectorize index-not-found 404 points at the setup command', function () {
    $raw = 'Vectorize GET indexes/foo failed: 404 The index was not found';

    $msg = LlmErrorPresenter::present($raw);

    expect($msg)->toContain('qdrant:setup');
});

test('unrecognised Vectorize envelope falls back to the generic strip-prefix branch', function () {
    $raw = 'Vectorize POST indexes/foo/query failed: {"result":null,"success":false,"errors":[{"code":99999,"message":"???"}]}';

    $msg = LlmErrorPresenter::present($raw);

    expect($msg)->not->toContain('"success":false');
    expect($msg)->not->toContain('"code":99999');
    expect($msg)->toContain('Vectorize');
});
