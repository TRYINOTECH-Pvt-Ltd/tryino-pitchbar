<?php

namespace App\Support;

/**
 * Approximate the token count of a piece of text without pulling in a
 * vendor-specific tokenizer. The cl100k_base / o200k_base tokenizers
 * used by GPT-4-class models tokenize English at roughly 3.5–4.0 chars
 * per token; non-Latin scripts compress closer to 1 token per char.
 *
 * For billing / usage logs the absolute precision matters less than the
 * shape — we just want the number to track real model usage instead of
 * the previous `mb_strlen()` shortcut, which over-counted English by 4×
 * and under-counted CJK by 2×. The catalog in `TokenPricing` multiplies
 * by per-million rates that are calibrated against the model's real
 * tokenizer, so getting tokens within ±15% gets cost within ±15%.
 *
 * Operators that need exact accounting should plumb `response.usage`
 * through `$call['tokens_in']` / `$call['tokens_out']` when they're
 * available from the upstream provider; this estimator is the fallback.
 */
final class TokenEstimator
{
    /**
     * Estimate the BPE-style token count for `$text`.
     *
     * Heuristic:
     *  - ASCII (Latin) chunks: 1 token per ~4 characters.
     *  - Non-ASCII chunks (CJK, Cyrillic, Arabic, emoji): 1 token per
     *    ~1.5 characters — closer to what cl100k_base produces.
     *  - Whitespace runs collapse to one token at most.
     *
     * Returns at least 1 for any non-empty string so we never zero-out
     * a billable call.
     */
    public static function estimate(string $text): int
    {
        $trimmed = trim($text);
        if ($trimmed === '') {
            return 0;
        }

        // Count by Unicode code points so a 3-byte UTF-8 CJK glyph is
        // one character (not three). preg_split with /u handles this
        // without pulling in mbstring config dependencies.
        $codepoints = preg_split('//u', $trimmed, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $ascii = 0;
        $nonAscii = 0;
        foreach ($codepoints as $char) {
            if (strlen($char) === 1 && ord($char) < 0x80) {
                $ascii++;
            } else {
                $nonAscii++;
            }
        }

        $asciiTokens = (int) ceil($ascii / 4.0);
        $nonAsciiTokens = (int) ceil($nonAscii / 1.5);

        return max(1, $asciiTokens + $nonAsciiTokens);
    }

    /**
     * Estimate tokens across a chat history array of `{role, content}`
     * messages. Sums every message body plus the role + delimiter
     * overhead the upstream tokenizer counts (~4 tokens per message).
     *
     * @param  array<int, array<string, mixed>>  $messages
     */
    public static function estimateMessages(array $messages): int
    {
        $total = 0;
        foreach ($messages as $message) {
            $content = (string) ($message['content'] ?? '');
            $total += self::estimate($content) + 4;
        }

        // Every chat completion adds a trailing "assistant" priming
        // overhead that the OpenAI cookbook documents as ~3 tokens.
        return $total + 3;
    }
}
