<?php

use App\Services\Crawl\HtmlExtractor;

beforeEach(function () {
    $this->extractor = new HtmlExtractor;
});

test('extracts title and main text', function () {
    $html = '<html><head><title>Hello</title></head><body><h1>Hello</h1><p>World content here.</p></body></html>';

    $result = $this->extractor->extract($html);

    expect($result['title'])->toBe('Hello');
    expect($result['text'])->toContain('World content here');
});

test('drops <script>, <style>, <nav>, <footer>, <header>, <form>, <button>, <svg>', function () {
    $html = <<<'HTML'
    <html><body>
        <header>HEADER NOISE</header>
        <nav>NAV NOISE</nav>
        <script>alert("X");</script>
        <style>.x{}</style>
        <button>BTN NOISE</button>
        <svg><path d="M0 0"/></svg>
        <main><p>Genuine product copy.</p></main>
        <form>FORM NOISE</form>
        <footer>FOOTER NOISE</footer>
    </body></html>
    HTML;

    $text = $this->extractor->extract($html)['text'];

    expect($text)->toContain('Genuine product copy');
    expect($text)->not->toContain('HEADER NOISE');
    expect($text)->not->toContain('NAV NOISE');
    expect($text)->not->toContain('BTN NOISE');
    expect($text)->not->toContain('FORM NOISE');
    expect($text)->not->toContain('FOOTER NOISE');
});

test('drops blocks with chrome class names (cart, sidebar, breadcrumb, related)', function () {
    $html = <<<'HTML'
    <html><body>
        <div class="cart-summary">CART SUMMARY NOISE</div>
        <div class="sidebar">SIDEBAR NOISE</div>
        <div class="breadcrumb">BREADCRUMB NOISE</div>
        <div class="related-products">RELATED PRODUCTS NOISE</div>
        <div class="product-info">
            <h1>MacBook Air M5</h1>
            <p>Price 148000</p>
        </div>
    </body></html>
    HTML;

    $text = $this->extractor->extract($html)['text'];

    expect($text)->toContain('MacBook Air M5');
    expect($text)->toContain('148000');
    expect($text)->not->toContain('CART SUMMARY NOISE');
    expect($text)->not->toContain('SIDEBAR NOISE');
    expect($text)->not->toContain('BREADCRUMB NOISE');
    expect($text)->not->toContain('RELATED PRODUCTS NOISE');
});

test('strips Material Icons ligatures (shopping_basket, library_add, etc.)', function () {
    // The exact noise we saw indexed from startech.com.bd
    $html = '<html><body><p>Macbook Air M5 13 Inch Price In Bangladesh shopping_basket Cart 0 library_add Compare 0 Compare Product close YOUR CART close home Laptop bookmark_border Save library_add Add to Compare Share: Real product copy continues here.</p></body></html>';

    $text = $this->extractor->extract($html)['text'];

    expect($text)->toContain('Macbook Air M5 13 Inch Price In Bangladesh');
    expect($text)->toContain('Real product copy continues here');
    expect($text)->not->toContain('shopping_basket');
    expect($text)->not->toContain('library_add');
    expect($text)->not->toContain('bookmark_border');
    expect($text)->not->toContain('Compare Product');
    expect($text)->not->toContain('Add to Compare');
    expect($text)->not->toContain('YOUR CART');
});

test('preserves real product data (price, model name, specs)', function () {
    $html = <<<'HTML'
    <html>
        <head><title>MacBook Air M5</title></head>
        <body>
            <header>Cart 0 Compare 0</header>
            <main>
                <h1>MacBook Air M5 Chip 13-inch</h1>
                <div class="price">Price 148,000 ৳ Regular Price 158,400 ৳</div>
                <p>13.6-inch Liquid Retina display, Apple M5 chip, 16GB RAM, 256GB SSD.</p>
                <p>EMI available — 0% EMI for up to 12 months.</p>
            </main>
        </body>
    </html>
    HTML;

    $text = $this->extractor->extract($html)['text'];

    expect($text)->toContain('MacBook Air M5 Chip 13-inch');
    expect($text)->toContain('148,000');
    expect($text)->toContain('158,400');
    expect($text)->toContain('Liquid Retina');
    expect($text)->toContain('EMI available');
    expect($text)->toContain('0% EMI for up to 12 months');
});

test('handles nested chrome blocks via iterative stripping', function () {
    $html = '<div class="navigation"><div class="menu"><div class="cart">REMOVE ME</div></div></div><p>KEEP ME</p>';

    $text = $this->extractor->extract($html)['text'];

    expect($text)->toContain('KEEP ME');
    expect($text)->not->toContain('REMOVE ME');
});

test('returns null title when <title> is missing', function () {
    $result = $this->extractor->extract('<html><body><p>No title here.</p></body></html>');

    expect($result['title'])->toBeNull();
    expect($result['text'])->toContain('No title here');
});
