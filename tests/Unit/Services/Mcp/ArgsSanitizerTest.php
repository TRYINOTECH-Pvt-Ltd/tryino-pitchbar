<?php

use App\Services\Mcp\Support\ArgsSanitizer;

test('null values are stripped from arg object', function () {
    $out = ArgsSanitizer::sanitize(['a' => 1, 'b' => null, 'c' => 'x']);
    expect($out)->toBe(['a' => 1, 'c' => 'x']);
});

test('nested null values are stripped', function () {
    $out = ArgsSanitizer::sanitize([
        'filter' => ['from' => '2026-01-01', 'urgency' => null],
    ]);
    expect($out)->toBe(['filter' => ['from' => '2026-01-01']]);
});

test('arrays are not key-stripped (numeric keys preserved)', function () {
    $out = ArgsSanitizer::sanitize(['ids' => [1, 2, 3]]);
    expect($out)->toBe(['ids' => [1, 2, 3]]);
});

test('UTF-8 strings round-trip cleanly', function () {
    $out = ArgsSanitizer::sanitize(['q' => 'héllo café']);
    expect($out['q'])->toBe('héllo café');
});
