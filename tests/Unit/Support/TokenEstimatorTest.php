<?php

use App\Support\TokenEstimator;

test('estimate returns 0 for empty / whitespace strings', function () {
    expect(TokenEstimator::estimate(''))->toBe(0);
    expect(TokenEstimator::estimate("   \n\t  "))->toBe(0);
});

test('estimate counts ASCII at roughly 4 characters per token', function () {
    // 16 ASCII chars ⇒ 4 tokens (ceil(16/4)).
    expect(TokenEstimator::estimate('hello world abcd'))->toBe(4);
    // 8 ASCII chars ⇒ 2 tokens.
    expect(TokenEstimator::estimate('abcd efg'))->toBe(2);
});

test('estimate counts CJK / non-ASCII more densely than Latin', function () {
    // 6 CJK chars ⇒ ceil(6/1.5) = 4 tokens; under no circumstance < 1.
    expect(TokenEstimator::estimate('你好世界吗呢'))
        ->toBeGreaterThanOrEqual(3)
        ->toBeLessThanOrEqual(5);
});

test('estimate never returns 0 for a non-empty string', function () {
    expect(TokenEstimator::estimate('a'))->toBe(1);
    expect(TokenEstimator::estimate('?'))->toBe(1);
});

test('estimateMessages sums per-message tokens plus chat overhead', function () {
    $messages = [
        ['role' => 'system', 'content' => 'You are helpful.'],
        ['role' => 'user', 'content' => 'Hi'],
    ];

    $total = TokenEstimator::estimateMessages($messages);

    // (ceil(16/4)=4 +4 ovh) + (ceil(2/4)=1 +4 ovh) + 3 trailing prime = 16.
    expect($total)->toBe(16);
});
