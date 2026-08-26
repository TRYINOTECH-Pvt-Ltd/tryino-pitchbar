<?php

use App\Support\MailHeader;

test('strips CR / LF / NUL from subject (header-injection defense)', function () {
    // CRLF is what makes header injection possible — the attacker needs
    // to break out onto a fresh header line. Stripping CR/LF/NUL kills
    // the attack regardless of what literal substrings remain in the
    // (now single-line) subject.
    $injected = "Welcome\r\nBcc: attacker@evil.com\r\n";
    $clean = MailHeader::subject($injected);

    expect($clean)->not->toContain("\r");
    expect($clean)->not->toContain("\n");
    expect($clean)->not->toContain("\0");
    expect($clean)->toBe('WelcomeBcc: attacker@evil.com');
});

test('collapses whitespace runs left behind by stripped line breaks', function () {
    $value = "Hello\r\n\r\n   world";

    expect(MailHeader::subject($value))->toBe('Hello world');
});

test('trims leading and trailing whitespace', function () {
    expect(MailHeader::subject('   spaced   '))->toBe('spaced');
});

test('truncates oversized subjects with an ellipsis', function () {
    $long = str_repeat('A', 250);
    $clean = MailHeader::subject($long);

    expect(mb_strlen($clean))->toBeLessThanOrEqual(200);
    expect(mb_substr($clean, -1))->toBe('…');
});

test('passes through a clean subject unchanged', function () {
    expect(MailHeader::subject('New lead — alice@example.com'))
        ->toBe('New lead — alice@example.com');
});
