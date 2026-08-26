<?php

use App\Services\Widget\InlineBlockParser;

test('coupon block is extracted as coupon_card', function () {
    $parser = new InlineBlockParser;

    $text = 'Here you go: <coupon code="WELCOME10" label="10% off your first order" discount="10%"/> apply on checkout.';
    $out = $parser->extract($text);

    expect($out['blocks'])->toHaveCount(1);
    expect($out['blocks'][0]['type'])->toBe('coupon_card');
    expect($out['blocks'][0]['payload'])->toMatchArray([
        'code' => 'WELCOME10',
        'label' => '10% off your first order',
        'discount' => '10%',
    ]);
    expect($out['text'])->not()->toContain('<coupon');
});

test('multiple coupon blocks are each extracted', function () {
    $parser = new InlineBlockParser;
    $text = '<coupon code="A" label="ten" discount="10%"/> and <coupon code="B" label="twenty" discount="20%"/>';
    $out = $parser->extract($text);

    expect($out['blocks'])->toHaveCount(2);
});
