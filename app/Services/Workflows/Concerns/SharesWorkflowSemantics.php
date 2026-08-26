<?php

namespace App\Services\Workflows\Concerns;

/**
 * Pure workflow semantics shared verbatim by the runtime
 * (WorkflowEngine) and the canvas test-run (WorkflowSimulator).
 *
 * Extracted so the simulator can never drift from what the engine
 * actually does: keyword matching, branch-case evaluation, jump
 * clamping, tag normalization and `{{ var }}` interpolation exist in
 * exactly one place. Everything here is side-effect free.
 */
trait SharesWorkflowSemantics
{
    /**
     * Match a lowercased visitor message against a keyword set using
     * the trigger's match_mode (any | all | exact). Defaults to `any`
     * for backwards compatibility with Phase 1 workflows that don't
     * carry a match_mode field.
     *
     * @param  array<int, string>  $keywords
     */
    protected function keywordSetMatches(array $keywords, string $mode, string $needle): bool
    {
        if ($keywords === []) {
            return false;
        }

        return match ($mode) {
            'all' => $this->allKeywordsMatch($keywords, $needle),
            'exact' => $this->exactKeywordMatch($keywords, $needle),
            default => $this->anyKeywordMatches($keywords, $needle),
        };
    }

    /**
     * @param  array<int, string>  $keywords
     */
    protected function anyKeywordMatches(array $keywords, string $needle): bool
    {
        foreach ($keywords as $keyword) {
            if ($keyword === '') {
                continue;
            }
            if (str_contains($needle, mb_strtolower($keyword))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int, string>  $keywords
     */
    protected function allKeywordsMatch(array $keywords, string $needle): bool
    {
        $any = false;
        foreach ($keywords as $keyword) {
            if ($keyword === '') {
                continue;
            }
            if (! str_contains($needle, mb_strtolower($keyword))) {
                return false;
            }
            $any = true;
        }

        return $any;
    }

    /**
     * @param  array<int, string>  $keywords
     */
    protected function exactKeywordMatch(array $keywords, string $needle): bool
    {
        foreach ($keywords as $keyword) {
            if ($keyword === '') {
                continue;
            }
            if ($needle === mb_strtolower($keyword)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Evaluate a branch step's cases against the captured vars and
     * return [target step index or null, matched case index or
     * 'default' or null]. First match wins; `match: default` fires
     * when no other case hits; null target = fall through linearly.
     *
     * @param  array<string, mixed>  $step
     * @param  array<string, mixed>  $vars
     * @return array{0: int|null, 1: int|string|null}
     */
    protected function resolveBranchTargetWithCase(array $step, array $vars, int $stepCount): array
    {
        $varName = (string) ($step['var'] ?? '');
        $value = $vars[$varName] ?? null;
        $cases = (array) ($step['cases'] ?? []);
        $default = null;

        foreach ($cases as $idx => $case) {
            if (! is_array($case)) {
                continue;
            }
            $match = (string) ($case['match'] ?? 'equals');
            $target = (int) ($case['go_to'] ?? -1);

            if ($match === 'default') {
                $default = $target;

                continue;
            }

            if ($this->caseMatches($match, $value, $case['value'] ?? null)) {
                return [$this->clampTarget($target, $stepCount), (int) $idx];
            }
        }

        if ($default !== null) {
            return [$this->clampTarget($default, $stepCount), 'default'];
        }

        return [null, null];
    }

    protected function caseMatches(string $match, mixed $varValue, mixed $caseValue): bool
    {
        $varStr = is_scalar($varValue) || $varValue === null ? (string) $varValue : '';
        $caseStr = is_scalar($caseValue) || $caseValue === null ? (string) $caseValue : '';

        return match ($match) {
            'equals' => mb_strtolower($varStr) === mb_strtolower($caseStr),
            'contains' => $caseStr !== '' && str_contains(mb_strtolower($varStr), mb_strtolower($caseStr)),
            'starts_with' => $caseStr !== '' && str_starts_with(mb_strtolower($varStr), mb_strtolower($caseStr)),
            'is_empty' => trim($varStr) === '',
            'not_empty' => trim($varStr) !== '',
            default => false,
        };
    }

    protected function clampTarget(int $target, int $stepCount): int
    {
        if ($target < 0) {
            return 0;
        }
        if ($target > $stepCount) {
            // Past the end → trigger the "walked off" completion path.
            return $stepCount;
        }

        return $target;
    }

    /**
     * @param  array<int, mixed>  $tags
     * @return array<int, string>
     */
    protected function normalizeTags(array $tags): array
    {
        return array_values(array_unique(array_filter(
            array_map(static fn ($t) => is_string($t) ? trim($t) : '', $tags),
            static fn (string $t): bool => $t !== '',
        )));
    }

    /**
     * Tiny `{{var_name}}` substitution. Anything unrecognised is left
     * untouched so the admin can preview the literal placeholder.
     *
     * @param  array<string, mixed>  $vars
     */
    protected function interpolate(string $template, array $vars): string
    {
        return (string) preg_replace_callback(
            '/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/',
            function (array $m) use ($vars): string {
                $key = $m[1];

                return is_string($vars[$key] ?? null) ? $vars[$key] : $m[0];
            },
            $template,
        );
    }
}
