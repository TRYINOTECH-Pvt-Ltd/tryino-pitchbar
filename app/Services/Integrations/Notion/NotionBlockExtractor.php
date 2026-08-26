<?php

namespace App\Services\Integrations\Notion;

/**
 * Walks a block tree from NotionClient::getBlocks() and concatenates the
 * extractable plain text in document order. Handles the common types:
 *   - paragraph, heading_1/2/3
 *   - bulleted_list_item, numbered_list_item, to_do
 *   - quote, callout, code, toggle
 *   - child_page (just the page title — its body is already in the tree)
 *
 * Unknown types are skipped silently rather than throwing — Notion adds new
 * block types regularly.
 */
class NotionBlockExtractor
{
    /**
     * @param  array<int, array<string, mixed>>  $blocks
     */
    public function toText(array $blocks): string
    {
        $lines = [];
        foreach ($blocks as $block) {
            $type = (string) ($block['type'] ?? '');
            $piece = match ($type) {
                'paragraph' => $this->richText($block['paragraph']['rich_text'] ?? []),
                'heading_1' => '# '.$this->richText($block['heading_1']['rich_text'] ?? []),
                'heading_2' => '## '.$this->richText($block['heading_2']['rich_text'] ?? []),
                'heading_3' => '### '.$this->richText($block['heading_3']['rich_text'] ?? []),
                'bulleted_list_item' => '- '.$this->richText($block['bulleted_list_item']['rich_text'] ?? []),
                'numbered_list_item' => '1. '.$this->richText($block['numbered_list_item']['rich_text'] ?? []),
                'to_do' => '[ ] '.$this->richText($block['to_do']['rich_text'] ?? []),
                'quote' => '> '.$this->richText($block['quote']['rich_text'] ?? []),
                'callout' => $this->richText($block['callout']['rich_text'] ?? []),
                'code' => $this->richText($block['code']['rich_text'] ?? []),
                'toggle' => $this->richText($block['toggle']['rich_text'] ?? []),
                'child_page' => '## '.($block['child_page']['title'] ?? ''),
                default => '',
            };

            $piece = trim($piece);
            if ($piece !== '') {
                $lines[] = $piece;
            }
        }

        return implode("\n\n", $lines);
    }

    /**
     * @param  array<int, array<string, mixed>>  $rich
     */
    private function richText(array $rich): string
    {
        return collect($rich)->pluck('plain_text')->filter()->implode('');
    }
}
