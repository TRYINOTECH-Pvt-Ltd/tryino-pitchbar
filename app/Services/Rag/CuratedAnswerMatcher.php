<?php

namespace App\Services\Rag;

use App\Models\CuratedAnswer;
use Illuminate\Support\Facades\Cache;

class CuratedAnswerMatcher
{
    /**
     * Returns the answer text if a curated answer matches, else null.
     *
     * `question_pattern` is treated as a comma-separated OR-set of
     * keywords. Each keyword is a lowercase substring needle against
     * the visitor's message. Buyer-reported (Lucian, 2026-05-15):
     * the V1 implementation used the raw `question_pattern` string as a
     * single substring, so an admin who wrote "pricing, web design"
     * expecting either keyword to fire the curated answer found that
     * NOTHING ever matched (visitor would have to literally type
     * "pricing, web design" verbatim).
     */
    public function match(string $agentId, string $userMessage, ?string $lang = null): ?string
    {
        $key = "curated:{$agentId}";
        /** @var array<int, array{tokens: list<string>, answer: string, lang: ?string}> $entries */
        $entries = Cache::remember($key, 60, function () use ($agentId): array {
            return CuratedAnswer::query()->withoutWorkspaceScope()
                ->where('agent_id', $agentId)
                ->where('enabled', true)
                ->orderByDesc('priority')
                ->get()
                ->map(fn ($a) => [
                    'tokens' => self::tokenize((string) $a->question_pattern),
                    'answer' => $a->answer,
                    'lang' => $a->lang,
                ])
                ->all();
        });

        $needle = mb_strtolower(trim($userMessage));
        if ($needle === '') {
            return null;
        }

        foreach ($entries as $entry) {
            if ($lang !== null && $entry['lang'] !== null && $entry['lang'] !== $lang) {
                continue;
            }
            foreach ($entry['tokens'] as $token) {
                if (str_contains($needle, $token)) {
                    return $entry['answer'];
                }
            }
        }

        return null;
    }

    /**
     * Split a `question_pattern` string into the OR-set of keyword
     * needles used by `match()`. Lowercase, trimmed, no empties.
     *
     * @return list<string>
     */
    private static function tokenize(string $pattern): array
    {
        return array_values(array_filter(
            array_map(
                fn ($t) => mb_strtolower(trim((string) $t)),
                explode(',', $pattern),
            ),
            fn ($t) => $t !== '',
        ));
    }
}
