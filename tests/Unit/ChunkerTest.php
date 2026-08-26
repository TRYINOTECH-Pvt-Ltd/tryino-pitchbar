<?php

use App\Services\Rag\Chunker;

beforeEach(function () {
    $this->chunker = new Chunker;
});

test('returns empty for empty/whitespace input', function () {
    expect($this->chunker->chunk(''))->toBe([]);
    expect($this->chunker->chunk("   \n\n  "))->toBe([]);
});

test('keeps short text as a single chunk', function () {
    $chunks = $this->chunker->chunk('Just a short paragraph.', 500, 50);
    expect($chunks)->toBe(['Just a short paragraph.']);
});

test('respects paragraph boundaries when packing', function () {
    $text = "Para one body.\n\nPara two body.\n\nPara three body.";
    $chunks = $this->chunker->chunk($text, 500, 0);

    // Three short paragraphs should pack into a single chunk preserving the breaks.
    expect($chunks)->toHaveCount(1);
    expect($chunks[0])->toContain("Para one body.\n\nPara two body.\n\nPara three body.");
});

test('splits across heading boundaries', function () {
    $text = "# Section One\nIntro to section one body.\n\n## Section Two\nSection two body.";
    $chunks = $this->chunker->chunk($text, 500, 0);

    expect(count($chunks))->toBeGreaterThan(1);
    // Heading line itself is dropped (treated as a separator), so chunks
    // should contain section bodies only.
    expect($chunks[0])->toContain('Intro to section one');
    expect($chunks[1])->toContain('Section two body');
});

test('splits long paragraph at sentence boundaries — no mid-sentence break', function () {
    // 5 sentences, each ~80 chars → ~400 chars total; tiny target forces multiple chunks.
    $sentences = [
        'The MacBook Air M5 features a 13.6-inch Liquid Retina display with True Tone.',
        'It is powered by the Apple M5 chip with a 10-core GPU and 16GB unified memory.',
        'The price in Bangladesh is 148,000 taka, or 151,000 with the upgraded SSD.',
        'EMI is available for up to 12 months at 0% interest through Star Tech.',
        'The laptop ships with macOS Sequoia and includes a one-year limited warranty.',
    ];
    $text = implode(' ', $sentences);

    $chunks = $this->chunker->chunk($text, 30, 0); // ~120 char target

    // Every chunk should end with terminal punctuation OR be at end of input.
    foreach ($chunks as $c) {
        $stripped = rtrim($c);
        $last = mb_substr($stripped, -1);
        expect(in_array($last, ['.', '!', '?'], true))->toBeTrue("Chunk ended mid-sentence: {$c}");
    }

    // The price line must survive intact in some chunk.
    $hasPrice = false;
    foreach ($chunks as $c) {
        if (str_contains($c, '148,000 taka, or 151,000')) {
            $hasPrice = true;
            break;
        }
    }
    expect($hasPrice)->toBeTrue();
});

test('overlap carries trailing context into the next chunk', function () {
    // Need each chunk-target (>=200 char floor) to be exceeded → use ~600 chars.
    $sentence = str_repeat('Some long sentence about pricing. ', 5);
    $text = $sentence."\n\n".$sentence."\n\n".$sentence."\n\n".$sentence;

    $chunks = $this->chunker->chunk($text, 50, 15); // 200 char target, 60 char overlap

    expect(count($chunks))->toBeGreaterThan(1);
    // Chunk N+1 should start with text from the tail of chunk N.
    $tail = mb_substr($chunks[0], max(0, mb_strlen($chunks[0]) - 60));
    expect($chunks[1])->toContain(trim($tail));
});

test('does not break a long unsplittable run by losing characters', function () {
    // 2000 chars, no sentence breaks (e.g. minified config dump).
    $text = str_repeat('abcdefghij', 200);

    $chunks = $this->chunker->chunk($text, 30, 0);

    // Reassembly should produce ≥ original length (overlap may make it longer,
    // but with overlap=0 here it should be exactly equal modulo whitespace).
    $rejoined = implode('', $chunks);
    expect(mb_strlen($rejoined))->toBe(mb_strlen($text));
});

test('e-commerce product page produces clean per-section chunks', function () {
    $text = <<<'TEXT'
    # MacBook Air M5 Chip 13-inch

    Price 148,000 taka. Regular price 158,400 taka. In stock.

    ## Key Features

    13.6-inch Liquid Retina display. Apple M5 chip with 10-core GPU.

    16GB unified memory. 256GB SSD storage. macOS Sequoia.

    ## Availability

    EMI available — 0% EMI for up to 12 months. Free delivery in Dhaka.
    TEXT;

    $chunks = $this->chunker->chunk($text, 60, 10);

    // Pricing fact should live in one chunk together.
    $priceChunk = collect($chunks)->first(fn ($c) => str_contains($c, '148,000'));
    expect($priceChunk)->not->toBeNull();
    expect($priceChunk)->toContain('158,400');

    // EMI fact should live in another chunk together.
    $emiChunk = collect($chunks)->first(fn ($c) => str_contains($c, 'EMI'));
    expect($emiChunk)->not->toBeNull();
    expect($emiChunk)->toContain('12 months');
});

test('content after a heading + numbered list is not dropped (paste-text truncation report)', function () {
    // A buyer reported a pasted doc looked like it "stopped" after a
    // numbered list ("1... 2..."). Reproduce a doc whose interesting
    // facts live AFTER the list and a later heading — they must all
    // survive into some chunk, proving the preview cut is display-only.
    $text = <<<'TEXT'
    How Blengi Works

    1. Create your AI assistant.
    2. Add your knowledge sources.
    3. Embed the widget on your site.

    ## Pricing

    The Pro plan is 49 EUR per month. The Team plan is 99 EUR per month.

    ## Support

    Email support@blengi.example for help. Response time is under 24 hours.
    TEXT;

    $chunks = $this->chunker->chunk($text);

    // Every load-bearing fact — including the ones AFTER the list — is present.
    expect(collect($chunks)->contains(fn ($c) => str_contains($c, '49 EUR')))->toBeTrue();
    expect(collect($chunks)->contains(fn ($c) => str_contains($c, '99 EUR')))->toBeTrue();
    expect(collect($chunks)->contains(fn ($c) => str_contains($c, 'support@blengi.example')))->toBeTrue();
    expect(collect($chunks)->contains(fn ($c) => str_contains($c, 'under 24 hours')))->toBeTrue();
});
