<?php

use App\Services\Widget\InlineBlockParser;

beforeEach(function () {
    $this->parser = new InlineBlockParser;
});

test('extracts a clean product card and strips the marker from text', function () {
    $text = 'The Aurora Hoodie is great. <product title="Aurora Hoodie" price="49.00" currency="USD" url="https://shop.example.com/aurora" image="https://shop.example.com/aurora.jpg" summary="Soft pullover"/> Want to see it?';

    $result = $this->parser->extract($text);

    expect($result['blocks'])->toHaveCount(1);
    expect($result['blocks'][0]['type'])->toBe('product_card');
    expect($result['blocks'][0]['payload'])->toMatchArray([
        'title' => 'Aurora Hoodie',
        'price' => '49.00',
        'currency' => 'USD',
        'url' => 'https://shop.example.com/aurora',
        'image' => 'https://shop.example.com/aurora.jpg',
        'summary' => 'Soft pullover',
    ]);
    expect($result['text'])->not->toContain('<product');
    expect($result['text'])->toContain('The Aurora Hoodie is great.');
    expect($result['text'])->toContain('Want to see it?');
});

test('extracts a pricing card', function () {
    $text = 'Pro is the best fit. <pricing title="Pro" price="49" currency="USD" period="month" cta="Start free trial" url="https://app.example.com/signup?plan=pro"/>';

    $result = $this->parser->extract($text);

    expect($result['blocks'])->toHaveCount(1);
    expect($result['blocks'][0]['type'])->toBe('pricing_card');
    expect($result['blocks'][0]['payload'])->toMatchArray([
        'title' => 'Pro',
        'price' => '49',
        'period' => 'month',
        'cta' => 'Start free trial',
    ]);
});

test('extracts a case study card', function () {
    $text = 'Here is a great example. <case-study title="ACME cut onboarding 40%" outcome="6 weeks to 3" url="https://example.com/case/acme"/>';

    $result = $this->parser->extract($text);

    expect($result['blocks'])->toHaveCount(1);
    expect($result['blocks'][0]['type'])->toBe('case_study_card');
    expect($result['blocks'][0]['payload']['title'])->toBe('ACME cut onboarding 40%');
});

test('extracts multiple blocks of mixed types', function () {
    $text = <<<'TXT'
    Here are two options.
    <product title="Hoodie" price="49" currency="USD" url="https://shop.example.com/hoodie"/>
    <product title="Tote" price="22" currency="USD" url="https://shop.example.com/tote"/>
    <pricing title="Pro" price="99" period="year" cta="Subscribe" url="https://app.example.com/pro"/>
    TXT;

    $result = $this->parser->extract($text);

    expect($result['blocks'])->toHaveCount(3);
    $types = array_column($result['blocks'], 'type');
    expect($types)->toContain('product_card');
    expect($types)->toContain('pricing_card');
    expect($result['text'])->not->toContain('<product');
    expect($result['text'])->not->toContain('<pricing');
});

test('returns empty blocks and unmodified text when no markers present', function () {
    $text = 'Hello — what can I help you find today?';

    $result = $this->parser->extract($text);

    expect($result['blocks'])->toBe([]);
    expect($result['text'])->toBe($text);
});

test('repairs LLM-collapsed attributes (currency value swallows next attr)', function () {
    // Llama 3.3 70B on Workers AI sometimes emits this shape — the closing
    // quote on currency is missing, so a naive parser captures
    // 'USD url=https://...' as the currency value. The repair pass should
    // split it back into two attributes.
    $text = '<product title="Hoodie" price="49.00" currency="USD url=https://shop.example.com/aurora-hoodie" summary="Soft hoodie"/>';

    $result = $this->parser->extract($text);

    expect($result['blocks'])->toHaveCount(1);
    expect($result['blocks'][0]['payload'])->toMatchArray([
        'title' => 'Hoodie',
        'price' => '49.00',
        'currency' => 'USD',
        'url' => 'https://shop.example.com/aurora-hoodie',
        'summary' => 'Soft hoodie',
    ]);
});

test('repairs collapsed attributes on <pricing/> markers', function () {
    // Same attribute-collapse Llama 3.3 quirk applied to a pricing card.
    $text = '<pricing title="Pro" price="49" currency="USD period=month" cta="Start free trial" url="https://app.example.com/signup?plan=pro"/>';

    $result = $this->parser->extract($text);

    expect($result['blocks'])->toHaveCount(1);
    expect($result['blocks'][0]['type'])->toBe('pricing_card');
    expect($result['blocks'][0]['payload'])->toMatchArray([
        'title' => 'Pro',
        'price' => '49',
        'currency' => 'USD',
        'period' => 'month',
        'cta' => 'Start free trial',
        'url' => 'https://app.example.com/signup?plan=pro',
    ]);
});

test('repairs collapsed attributes on <case-study/> markers', function () {
    $text = '<case-study title="ACME 40% faster" outcome="6 weeks to 3 url=https://example.com/case/acme"/>';

    $result = $this->parser->extract($text);

    expect($result['blocks'])->toHaveCount(1);
    expect($result['blocks'][0]['type'])->toBe('case_study_card');
    expect($result['blocks'][0]['payload'])->toMatchArray([
        'title' => 'ACME 40% faster',
        'outcome' => '6 weeks to 3',
        'url' => 'https://example.com/case/acme',
    ]);
});

test('tolerates newlines inside the attribute list', function () {
    $text = <<<'TXT'
    <product
        title="Hoodie"
        price="49"
        currency="USD"
        url="https://shop.example.com/hoodie"
    />
    TXT;

    $result = $this->parser->extract($text);

    expect($result['blocks'])->toHaveCount(1);
    expect($result['blocks'][0]['payload']['title'])->toBe('Hoodie');
    expect($result['blocks'][0]['payload']['url'])->toBe('https://shop.example.com/hoodie');
});

test('accepts single-quoted attributes', function () {
    $text = "<product title='Hoodie' price='49' currency='USD' url='https://shop.example.com/hoodie'/>";

    $result = $this->parser->extract($text);

    expect($result['blocks'])->toHaveCount(1);
    expect($result['blocks'][0]['payload']['title'])->toBe('Hoodie');
});

test('tolerates a space before the self-close', function () {
    $text = '<product title="Hoodie" price="49" currency="USD" url="https://shop.example.com/x" />';

    $result = $this->parser->extract($text);

    expect($result['blocks'])->toHaveCount(1);
});

test('drops a marker with zero parsable attributes', function () {
    $text = '<product/>';

    $result = $this->parser->extract($text);

    expect($result['blocks'])->toBe([]);
});

test('collapses excessive blank lines left behind by stripped markers', function () {
    $text = "First.\n\n\n\n<product title=\"X\" price=\"1\" currency=\"USD\" url=\"https://x.test\"/>\n\n\nSecond.";

    $result = $this->parser->extract($text);

    expect($result['text'])->toContain('First.');
    expect($result['text'])->toContain('Second.');
    // Should not have 3+ consecutive newlines anywhere
    expect(preg_match('/\n{3,}/', $result['text']))->toBe(0);
});

test('partial markers (missing self-close) are ignored, not emitted', function () {
    $text = 'Take a look at <product title="Half-baked" price="49" currency="USD" url="https://x.test"';

    $result = $this->parser->extract($text);

    expect($result['blocks'])->toBe([]);
    // Original text preserved
    expect($result['text'])->toContain('<product title="Half-baked"');
});

test('case-insensitive tag matching', function () {
    $text = '<PRODUCT title="Hoodie" price="49" currency="USD" url="https://x.test"/>';

    $result = $this->parser->extract($text);

    expect($result['blocks'])->toHaveCount(1);
});

test('payload keys are lowercased even if the LLM upper-cases them', function () {
    $text = '<product TITLE="Hoodie" PRICE="49" Currency="USD" URL="https://x.test"/>';

    $result = $this->parser->extract($text);

    expect($result['blocks'])->toHaveCount(1);
    expect($result['blocks'][0]['payload'])->toHaveKey('title');
    expect($result['blocks'][0]['payload'])->toHaveKey('price');
    expect($result['blocks'][0]['payload'])->toHaveKey('currency');
    expect($result['blocks'][0]['payload'])->toHaveKey('url');
});

test('extracts a suggestions block and strips the marker', function () {
    $text = 'The Standard plan is $49/month. <suggestions q1="What features are included?" q2="Can I try it free?" q3="How do I cancel?"/>';

    $result = $this->parser->extract($text);

    expect($result['blocks'])->toHaveCount(1);
    expect($result['blocks'][0]['type'])->toBe('suggestion_chips');
    expect($result['blocks'][0]['payload'])->toMatchArray([
        'q1' => 'What features are included?',
        'q2' => 'Can I try it free?',
        'q3' => 'How do I cancel?',
    ]);
    expect($result['text'])->not->toContain('<suggestions');
    expect($result['text'])->toContain('The Standard plan is $49/month.');
});

test('suggestions block allows fewer than three follow-ups', function () {
    $text = 'Sure thing. <suggestions q1="Where do I sign up?"/>';

    $result = $this->parser->extract($text);

    expect($result['blocks'])->toHaveCount(1);
    expect($result['blocks'][0]['type'])->toBe('suggestion_chips');
    expect($result['blocks'][0]['payload'])->toMatchArray([
        'q1' => 'Where do I sign up?',
    ]);
    expect($result['blocks'][0]['payload'])->not->toHaveKey('q2');
});

test('suggestions block emitted mid-text is still extracted (LLM contract violation)', function () {
    // The prompt instructs the LLM to emit `<suggestions/>` AFTER all
    // other content, but small models occasionally drop it inline. The
    // parser must still extract it and the visible reply text must not
    // contain the raw marker.
    $text = 'You should try Pro. <suggestions q1="What features come with Pro?" q2="Can I try free?"/> The Standard plan is also a good fit.';

    $result = $this->parser->extract($text);

    expect($result['blocks'])->toHaveCount(1);
    expect($result['blocks'][0]['type'])->toBe('suggestion_chips');
    expect($result['text'])->not->toContain('<suggestions');
    expect($result['text'])->toContain('You should try Pro.');
    expect($result['text'])->toContain('The Standard plan is also a good fit.');
});

test('suggestions block coexists with a product card on the same turn', function () {
    $text = 'Take a look. <product title="Hoodie" price="49" currency="USD" url="https://shop.example.com/x"/> <suggestions q1="What sizes?" q2="Returns?"/>';

    $result = $this->parser->extract($text);

    $types = array_column($result['blocks'], 'type');
    expect($types)->toContain('product_card');
    expect($types)->toContain('suggestion_chips');
    expect($result['text'])->not->toContain('<product');
    expect($result['text'])->not->toContain('<suggestions');
});
