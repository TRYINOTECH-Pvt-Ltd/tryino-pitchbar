<?php

namespace App\Services\Rag;

/**
 * Builds the string that gets embedded for retrieval.
 *
 * A visitor's follow-up often drops the subject: after "Where was Jan
 * Roel born?" they ask "And in which year?". Embedding that bare
 * follow-up finds nothing — it carries no entity and no topic, so the
 * ANN search can't surface the chunk that actually holds the answer
 * (reproduced live 2026-07-05: the birth-year doc was never retrieved
 * for the query "En in welk jaar?").
 *
 * The prompt already receives full conversation history; retrieval did
 * not. This stitches the most recent prior *user* question onto a
 * context-dependent follow-up so the embedding regains the missing
 * entity + topic. Self-contained questions pass through untouched.
 *
 * Deliberately heuristic and LLM-free: the hot path's p95 ≤ 1s budget
 * forbids an extra round-trip just to rewrite the query. String work
 * only.
 */
class RetrievalQueryBuilder
{
    /**
     * A message with NO connective opener and at most this many words is a
     * bare fragment that leans on the previous turn for its subject
     * ("welk jaar?", "hoeveel?"). Anything longer is treated as a
     * self-contained question and embedded exactly as typed.
     *
     * This used to be 7, which was far too broad: most complete short
     * questions ("wat is uw adres?", "waar zijn jullie gevestigd?",
     * "what are your prices?") are ≤7 words, so mid-conversation they got
     * the previous question's subject glued on and the true answer dropped
     * below the confidence threshold. Proven live 2026-07-05: "wat is uw
     * adres" answered as a first turn but deferred right after a pricing
     * question.
     */
    private const SHORT_FRAGMENT_MAX_WORDS = 3;

    /**
     * Opening tokens that mark a LONGER message as continuing the prior
     * turn — conjunctions and anaphora only. Interrogatives (what / how
     * / which / …) are deliberately excluded: a long question that opens
     * with one is almost always self-contained ("What are your opening
     * hours in Meppel?"), while a short interrogative follow-up ("Which
     * year?") is already caught by the word-count rule. English + Dutch,
     * matched on the first word (lowercased, punctuation-stripped).
     */
    private const CONNECTIVE_OPENERS = [
        // English conjunctions + anaphora
        'and', 'or', 'but', 'so', 'also', 'then', 'plus',
        'it', 'they', 'them', 'that', 'those', 'this', 'these',
        // Dutch conjunctions + anaphora
        'en', 'of', 'maar', 'dus', 'ook', 'dan',
        'die', 'dat', 'deze', 'dit', 'het', 'ze', 'hij', 'zij', 'hen', 'hun',
    ];

    /**
     * Leading question words dropped from a prior question when it is used
     * to enrich a follow-up's retrieval query. English + Dutch.
     */
    private const INTERROGATIVE_OPENERS = [
        'where', 'what', 'when', 'who', 'whom', 'whose', 'why', 'which', 'how',
        'waar', 'wat', 'wanneer', 'wie', 'wiens', 'waarom', 'welke', 'welk', 'hoe',
    ];

    /**
     * How much of the assistant's own turn may be carried forward as
     * subject terms. Only proper nouns are taken (see
     * {@see subjectTermsFromAssistant}), so this is a backstop against a
     * reply that lists a whole catalogue.
     */
    private const ASSISTANT_TERMS_MAX = 4;

    /**
     * Whether this message leans on the conversation for its meaning — a
     * context-dependent follow-up / bare fragment with a prior user turn to
     * resolve against. This is the gate the LLM QueryRewriter uses to decide
     * whether a rewrite is worth a round-trip: a self-contained question (or
     * a first turn) is already a clean query and needs neither the LLM nor
     * the heuristic stitch.
     *
     * @param  array<int, array{role?: string, content?: string}>  $history
     */
    public function isContextDependent(string $message, array $history): bool
    {
        $trimmed = trim($message);
        if ($trimmed === '') {
            return false;
        }

        $answering = $this->answersAssistantQuestion($trimmed, $history);
        if (! $this->looksLikeFollowUp($trimmed) && ! $answering) {
            return false;
        }

        return $answering || $this->mostRecentUserContent($history) !== null;
    }

    public function build(string $message, array $history): string
    {
        $trimmed = trim($message);
        if ($trimmed === '') {
            return '';
        }

        $answering = $this->answersAssistantQuestion($trimmed, $history);
        if (! $this->looksLikeFollowUp($trimmed) && ! $answering) {
            return $trimmed;
        }

        $parts = [];

        // Card #481 — when the visitor is ANSWERING the assistant, the
        // thing they're answering about lives in the assistant's turn, not
        // theirs: "Wil je meer weten over de Thermo Super?" → "Maat 39, wat
        // kost dat ongeveer?". Stitching only the prior user turn loses the
        // product entirely, retrieval answers a generic price question, and
        // the model fills the gap from conversation history — which is
        // where "kost € 11,25, ongeacht de maat" came from. Proper nouns
        // only, so a generic question ("Waar draag je ze het meest?")
        // contributes nothing and can't dilute the embedding.
        if ($answering) {
            $terms = $this->subjectTermsFromAssistant($history);
            if ($terms !== '') {
                $parts[] = $terms;
            }
        }

        $previousUser = $this->mostRecentUserContent($history);
        if ($previousUser !== null) {
            // Prepend the prior question's SUBJECT — its leading
            // interrogative stripped. Keeping the whole prior question
            // ("Where was Jan Roel born?") biases retrieval toward that
            // question's answer-type (a place), so a "which year?"
            // follow-up drops below the confidence threshold and the
            // sibling year doc is missed — reproduced live 2026-07-05,
            // worst cross-lingually (English query vs a Dutch doc).
            // Dropping the "Where"/"Waar" leaves the shared subject
            // ("Jan Roel born") and both facts surface. Capped so a long
            // earlier turn can't drown the current one.
            $parts[] = mb_substr($this->stripLeadingInterrogative($previousUser), 0, 300);
        }

        if ($parts === []) {
            // Nothing to lean on (first turn) — embed as-is.
            return $trimmed;
        }

        $parts[] = $trimmed;

        return implode(' ', $parts);
    }

    /**
     * The terms a rewrite must not lose: the proper nouns the assistant
     * named in the question this message is answering. Card #485 — a small
     * LLM asked to "make this self-contained" will happily drop the product
     * name, and the resulting query retrieves the wrong thing while looking
     * perfectly reasonable.
     *
     * Empty when this message isn't an answer, or when the assistant's
     * question named nothing specific.
     *
     * @param  array<int, array{role?: string, content?: string}>  $history
     * @return list<string>
     */
    public function criticalTerms(string $message, array $history): array
    {
        if (! $this->answersAssistantQuestion(trim($message), $history)) {
            return [];
        }

        $terms = $this->subjectTermsFromAssistant($history);

        return $terms === '' ? [] : array_values(array_filter(explode(' ', $terms)));
    }

    /**
     * True when the assistant's own last turn ended in a question, making
     * this message its answer.
     *
     * An answer is context-dependent at ANY length — which is the whole
     * point: `looksLikeFollowUp` only catches connective openers and ≤3-word
     * fragments, so an Advisor-Mode answer ("Hoge rand, ik draag
     * werklaarzen. Ik werk buiten in de bouw en het is best koud.") sailed
     * through as a self-contained query, carried no product noun, and
     * retrieved nothing. Single-turn tests never hit this, because a first
     * message is self-contained by definition.
     *
     * A complete question that OPENS with an interrogative is excluded: the
     * visitor is changing the subject, not answering, and gluing the prior
     * topic onto "wat is uw adres" is the 2026-07-05 regression.
     *
     * @param  array<int, array{role?: string, content?: string}>  $history
     */
    private function answersAssistantQuestion(string $message, array $history): bool
    {
        $words = preg_split('/\s+/', $message, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($words === []) {
            return false;
        }

        $first = mb_strtolower(trim((string) $words[0], " \t\n\r\0\x0B.,;:!?\"'()"));
        if (count($words) > self::SHORT_FRAGMENT_MAX_WORDS
            && in_array($first, self::INTERROGATIVE_OPENERS, true)) {
            return false;
        }

        return $this->lastAssistantContent($history) !== null;
    }

    /**
     * The assistant's most recent turn, but only when it ended in a
     * question. Returns null otherwise — a statement doesn't make the
     * next message an answer.
     *
     * @param  array<int, array{role?: string, content?: string}>  $history
     */
    private function lastAssistantContent(array $history): ?string
    {
        for ($i = count($history) - 1; $i >= 0; $i--) {
            $turn = $history[$i];
            $role = $turn['role'] ?? null;
            $content = trim((string) ($turn['content'] ?? ''));
            if ($content === '') {
                continue;
            }
            if ($role !== 'assistant') {
                // A later user turn means the assistant's question is no
                // longer the thing being answered.
                return null;
            }

            return str_ends_with(rtrim($content, " \t\n\r\0\x0B*_\"')"), '?') ? $content : null;
        }

        return null;
    }

    /**
     * Proper nouns from the assistant's question turn — the product it
     * just named ("Thermo Super", "STAPP Boston"). Sentence-initial
     * capitals are skipped, so ordinary Dutch/English sentences
     * contribute nothing; adjacent capitals merge into one phrase.
     *
     * @param  array<int, array{role?: string, content?: string}>  $history
     */
    private function subjectTermsFromAssistant(array $history): string
    {
        $content = $this->lastAssistantContent($history);
        if ($content === null) {
            return '';
        }

        $words = preg_split('/\s+/', $content, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $phrases = [];
        $current = [];
        $sentenceStart = true;

        foreach ($words as $raw) {
            $word = trim((string) $raw, " \t\n\r\0\x0B.,;:!?\"'()*_—–-");
            $endsSentence = (bool) preg_match('/[.!?:]$/', (string) $raw);

            $isProperNoun = $word !== ''
                && ! $sentenceStart
                && preg_match('/^\p{Lu}[\p{L}\d-]*$/u', $word) === 1;
            // ALL-CAPS brand tokens (STAPP, COOLMAX) count anywhere,
            // including at the start of a sentence.
            $isBrand = $word !== '' && preg_match('/^\p{Lu}{3,}$/u', $word) === 1;

            if ($isProperNoun || $isBrand) {
                $current[] = $word;
            } elseif ($current !== []) {
                $phrases[] = implode(' ', $current);
                $current = [];
            }

            $sentenceStart = $endsSentence;
        }

        if ($current !== []) {
            $phrases[] = implode(' ', $current);
        }

        $phrases = array_slice(array_values(array_unique($phrases)), 0, self::ASSISTANT_TERMS_MAX);

        return implode(' ', $phrases);
    }

    /**
     * Drop a single leading interrogative word ("Where…", "Waar…") so the
     * prior question contributes its subject without its answer-type bias.
     * Leaves non-question openers ("Tell me about …") untouched.
     */
    private function stripLeadingInterrogative(string $question): string
    {
        $words = preg_split('/\s+/', trim($question), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($words === []) {
            return $question;
        }

        $first = mb_strtolower(trim((string) $words[0], " \t\n\r\0\x0B.,;:!?\"'()"));
        if (in_array($first, self::INTERROGATIVE_OPENERS, true)) {
            array_shift($words);
        }

        return implode(' ', $words);
    }

    private function looksLikeFollowUp(string $message): bool
    {
        $words = preg_split('/\s+/', $message, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($words === []) {
            return false;
        }

        $first = mb_strtolower(trim((string) $words[0], " \t\n\r\0\x0B.,;:!?\"'()"));

        // A connective / anaphoric opener marks a continuation of the prior
        // turn at ANY length ("En in welk jaar?", "and the price for the
        // large unit?").
        if (in_array($first, self::CONNECTIVE_OPENERS, true)) {
            return true;
        }

        // Otherwise only a very short bare fragment ("welk jaar?",
        // "hoeveel?") leans on the prior turn. A longer message that opens
        // with an interrogative or a content word is a self-contained
        // question and MUST be embedded exactly as typed — gluing the
        // prior topic onto "wat is uw adres" pollutes the search and drops
        // the answer below the confidence threshold.
        return count($words) <= self::SHORT_FRAGMENT_MAX_WORDS;
    }

    /**
     * @param  array<int, array{role?: string, content?: string}>  $history
     */
    private function mostRecentUserContent(array $history): ?string
    {
        for ($i = count($history) - 1; $i >= 0; $i--) {
            $turn = $history[$i];
            if (($turn['role'] ?? null) === 'user') {
                $content = trim((string) ($turn['content'] ?? ''));
                if ($content !== '') {
                    return $content;
                }
            }
        }

        return null;
    }
}
