<?php

use App\Services\Integrations\Notion\NotionBlockExtractor;

beforeEach(function () {
    $this->ex = new NotionBlockExtractor;
});

function rich(string $text): array
{
    return [['plain_text' => $text]];
}

test('joins paragraphs with blank lines', function () {
    $blocks = [
        ['type' => 'paragraph', 'paragraph' => ['rich_text' => rich('First.')]],
        ['type' => 'paragraph', 'paragraph' => ['rich_text' => rich('Second.')]],
    ];

    expect($this->ex->toText($blocks))->toBe("First.\n\nSecond.");
});

test('emits markdown for headings, lists, and todos', function () {
    $blocks = [
        ['type' => 'heading_1', 'heading_1' => ['rich_text' => rich('Title')]],
        ['type' => 'heading_2', 'heading_2' => ['rich_text' => rich('Sub')]],
        ['type' => 'bulleted_list_item', 'bulleted_list_item' => ['rich_text' => rich('a bullet')]],
        ['type' => 'numbered_list_item', 'numbered_list_item' => ['rich_text' => rich('a number')]],
        ['type' => 'to_do', 'to_do' => ['rich_text' => rich('a todo')]],
        ['type' => 'quote', 'quote' => ['rich_text' => rich('a quote')]],
    ];

    $out = $this->ex->toText($blocks);

    expect($out)->toContain('# Title');
    expect($out)->toContain('## Sub');
    expect($out)->toContain('- a bullet');
    expect($out)->toContain('1. a number');
    expect($out)->toContain('[ ] a todo');
    expect($out)->toContain('> a quote');
});

test('skips unknown block types silently', function () {
    $blocks = [
        ['type' => 'paragraph', 'paragraph' => ['rich_text' => rich('Hello')]],
        ['type' => 'audio', 'audio' => ['rich_text' => rich('ignored')]],
        ['type' => 'paragraph', 'paragraph' => ['rich_text' => rich('Bye')]],
    ];

    expect($this->ex->toText($blocks))->toBe("Hello\n\nBye");
});

test('handles empty rich_text gracefully', function () {
    $blocks = [
        ['type' => 'paragraph', 'paragraph' => ['rich_text' => []]],
        ['type' => 'paragraph', 'paragraph' => ['rich_text' => rich('real')]],
    ];

    expect($this->ex->toText($blocks))->toBe('real');
});
