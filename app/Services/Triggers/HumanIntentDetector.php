<?php

namespace App\Services\Triggers;

use App\Support\HumanPhrases;

/**
 * Detects when a visitor's message explicitly asks for a human. The
 * widget previously relied on the LLM choosing to call the
 * `escalate_to_human` tool, which is unreliable across small Workers AI
 * models — visitors who literally typed "connect me to a human" still
 * got an LLM answer instead of the escalation button.
 *
 * This detector runs synchronously in the hot path right after the
 * curated-answer short-circuit and BEFORE retrieval / LLM streaming.
 * When a high-confidence intent match fires, MessageStreamController
 * emits the standard `escalation_button` block + a short confirmation
 * message and skips the LLM call entirely.
 *
 * Designed to add < 1ms — substring scan over a fixed phrase list.
 * Tuned for high precision: false positives strand visitors with an
 * unhelpful button when they didn't ask for it, so the phrases are
 * deliberately explicit ("talk to a human", not "human"). Visitors
 * with adjacent intent ("anyone there?") still reach a human via the
 * always-on header button + the LLM tool path.
 */
class HumanIntentDetector
{
    /**
     * Returns true if the visitor's message contains an explicit
     * "talk to a human" intent. Caller is responsible for emitting the
     * escalation_button block.
     */
    public function matches(string $message): bool
    {
        $haystack = mb_strtolower(trim($message));
        if ($haystack === '') {
            return false;
        }

        foreach (HumanPhrases::PHRASES as $phrase) {
            if (str_contains($haystack, $phrase)) {
                return true;
            }
        }

        return false;
    }
}
