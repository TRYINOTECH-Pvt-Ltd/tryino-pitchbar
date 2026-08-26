<?php

use App\Services\Crawl\HtmlExtractor;
use App\Services\Crawl\ReadabilityExtractor;

test('XXE payload does not leak host file contents', function () {
    $payload = <<<'HTML'
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE foo [
    <!ELEMENT foo ANY>
    <!ENTITY xxe SYSTEM "file:///etc/hostname">
]>
<html><body><article><h1>XXE</h1><p>&xxe;</p></article></body></html>
HTML;

    $extractor = new ReadabilityExtractor(new HtmlExtractor);

    $result = $extractor->extract($payload);

    $hostname = trim((string) @file_get_contents('/etc/hostname'));

    expect($result)->toBeArray();
    if ($hostname !== '' && $hostname !== false) {
        expect($result['text'])->not->toContain($hostname);
    }
    // The entity should never be resolved — text contains the literal
    // reference (&xxe;) instead of file contents.
    expect($result['text'])->not->toContain('/etc/hostname');
});

test('extractor still handles plain HTML after XXE guard cleanup', function () {
    $extractor = new ReadabilityExtractor(new HtmlExtractor);
    $html = '<html><body><article>'.str_repeat('<p>The pro plan is $49 a month with unlimited seats.</p>', 6).'</article></body></html>';

    $result = $extractor->extract($html);

    expect($result['text'])->toContain('pro plan');
});
