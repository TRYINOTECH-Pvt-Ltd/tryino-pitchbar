<?php

use App\Support\HttpErrorRedactor;

test('redacts authorization header lines', function () {
    $body = "Some error.\nAuthorization: Bearer sk-abc123def456ghi789jkl0mn\nMore text.";

    $redacted = HttpErrorRedactor::redact($body);

    expect($redacted)->toContain('authorization: [REDACTED]');
    expect($redacted)->not->toContain('sk-abc123');
});

test('redacts cookie header values', function () {
    $body = 'Cookie: session=topsecret; path=/; HttpOnly';

    expect(HttpErrorRedactor::redact($body))
        ->toContain('cookie: [REDACTED]')
        ->not->toContain('topsecret');
});

test('redacts inline bearer tokens', function () {
    $body = 'Failed: invalid Bearer abcdef1234567890ABCDEF';

    expect(HttpErrorRedactor::redact($body))
        ->toContain('Bearer [REDACTED]')
        ->not->toContain('abcdef1234567890');
});

test('redacts jwt-shaped tokens in payload', function () {
    $body = 'token=eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxMjM0NTY3ODkwIn0.signature_part_here_xyz';

    $redacted = HttpErrorRedactor::redact($body);

    expect($redacted)->toContain('[REDACTED_TOKEN]');
    expect($redacted)->not->toContain('eyJhbGciOiJIUzI1NiJ9');
});

test('truncates oversized bodies to 240 chars', function () {
    $big = str_repeat('A', 500);

    $redacted = HttpErrorRedactor::redact($big);

    expect(mb_strlen($redacted))->toBeLessThanOrEqual(241);
    expect($redacted)->toEndWith('…');
});

test('leaves benign bodies unchanged', function () {
    $body = 'Cloudflare returned 502 Bad Gateway from origin.';

    expect(HttpErrorRedactor::redact($body))->toBe($body);
});
