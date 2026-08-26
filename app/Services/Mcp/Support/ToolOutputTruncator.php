<?php

namespace App\Services\Mcp\Support;

/**
 * Truncate large tool outputs to a token budget. Default 1200 tokens
 * — sized to leave room in small models (Llama 3.3 on Workers AI,
 * 8K context) for the system prompt + RAG chunks + history + reply.
 *
 * Truncation is purely character-based (one token ≈ 4 chars). Good
 * enough for budget defence; the executor still passes the exact
 * remaining budget so this doesn't accidentally cap us above the
 * model's remaining context.
 */
final class ToolOutputTruncator
{
    public const CHARS_PER_TOKEN = 4;

    /**
     * @return array{text: string, truncated: bool, retained_tokens: int}
     */
    public static function truncate(string $text, int $tokenBudget): array
    {
        $charBudget = max(0, $tokenBudget * self::CHARS_PER_TOKEN);

        if (mb_strlen($text) <= $charBudget) {
            return [
                'text' => $text,
                'truncated' => false,
                'retained_tokens' => (int) ceil(mb_strlen($text) / self::CHARS_PER_TOKEN),
            ];
        }

        $kept = mb_substr($text, 0, $charBudget);
        $originalTokens = (int) ceil(mb_strlen($text) / self::CHARS_PER_TOKEN);
        $keptTokens = (int) ceil(mb_strlen($kept) / self::CHARS_PER_TOKEN);

        return [
            'text' => $kept."\n\n[truncated: original ~{$originalTokens} tokens, retained ~{$keptTokens}]",
            'truncated' => true,
            'retained_tokens' => $keptTokens,
        ];
    }
}
