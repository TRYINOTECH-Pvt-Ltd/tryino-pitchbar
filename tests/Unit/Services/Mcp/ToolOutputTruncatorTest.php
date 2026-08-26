<?php

use App\Services\Mcp\Support\ToolOutputTruncator;

test('short text passes through unchanged', function () {
    $res = ToolOutputTruncator::truncate('hello world', 100);
    expect($res['truncated'])->toBeFalse();
    expect($res['text'])->toBe('hello world');
});

test('long text gets truncated with marker', function () {
    $long = str_repeat('a', 10_000); // ~2500 tokens at 4 chars/token
    $res = ToolOutputTruncator::truncate($long, 100); // 400 char budget

    expect($res['truncated'])->toBeTrue();
    expect(mb_strlen($res['text']))->toBeLessThan(mb_strlen($long));
    expect($res['text'])->toContain('truncated:');
    expect($res['text'])->toContain('retained');
});

test('budget of 0 truncates everything to the marker', function () {
    $res = ToolOutputTruncator::truncate('any content', 0);
    expect($res['truncated'])->toBeTrue();
    expect($res['retained_tokens'])->toBe(0);
});
