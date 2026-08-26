<?php

use App\Services\Triggers\HumanIntentDetector;

beforeEach(function () {
    $this->detector = new HumanIntentDetector;
});

test('detects explicit "talk to a human" phrases', function () {
    expect($this->detector->matches('talk to a human'))->toBeTrue();
    expect($this->detector->matches('I want to talk to a human please'))->toBeTrue();
    expect($this->detector->matches('Talk To A Human'))->toBeTrue();
    expect($this->detector->matches('CONNECT ME TO A HUMAN'))->toBeTrue();
});

test('detects "connect to support" variants', function () {
    expect($this->detector->matches('connect me to support'))->toBeTrue();
    expect($this->detector->matches('please connect to support'))->toBeTrue();
});

test('detects "real person" / "live agent" variants', function () {
    expect($this->detector->matches('I want to speak to a real person'))->toBeTrue();
    expect($this->detector->matches('can I get a live agent'))->toBeTrue();
    expect($this->detector->matches('live support please'))->toBeTrue();
});

test('detects framing variations', function () {
    expect($this->detector->matches('transfer me to a human'))->toBeTrue();
    expect($this->detector->matches('get me a human'))->toBeTrue();
    expect($this->detector->matches('is anyone there'))->toBeTrue();
    expect($this->detector->matches('customer service'))->toBeTrue();
});

test('does not match the bare word "human" in unrelated context', function () {
    expect($this->detector->matches('the human genome project'))->toBeFalse();
    expect($this->detector->matches('this is humanly impossible'))->toBeFalse();
    expect($this->detector->matches('I love human-readable docs'))->toBeFalse();
});

test('does not match the bare word "person" in unrelated context', function () {
    expect($this->detector->matches('the person who runs the warehouse'))->toBeFalse();
    expect($this->detector->matches('I am a private person'))->toBeFalse();
});

test('returns false for empty / whitespace', function () {
    expect($this->detector->matches(''))->toBeFalse();
    expect($this->detector->matches("   \n\t "))->toBeFalse();
});

test('matches independent of surrounding text', function () {
    expect($this->detector->matches('hello there, can i talk to a human about my order'))
        ->toBeTrue();
    expect($this->detector->matches('this bot is useless, get me a human now'))
        ->toBeTrue();
});
