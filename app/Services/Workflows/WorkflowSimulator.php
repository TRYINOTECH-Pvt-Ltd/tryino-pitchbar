<?php

namespace App\Services\Workflows;

use App\Services\Workflows\Concerns\SharesWorkflowSemantics;

/**
 * Stateless dry-run of a workflow definition for the canvas "Test run"
 * panel. Walks the exact same semantics as WorkflowEngine (shared via
 * the SharesWorkflowSemantics trait) but with ZERO side effects:
 *
 *   - no WorkflowRun row is created or touched
 *   - webhook steps are traced, never dispatched
 *   - tag_lead steps are traced, never applied to a Lead
 *   - escalate completes the trace without notifying operators
 *
 * The caller sends the visitor's opening message plus any replies to
 * question steps as a flat `messages[]` array; the simulator consumes
 * one entry per pause, exactly like consecutive visitor turns. When it
 * runs out of replies at a question, it reports `status: waiting_reply`
 * so the UI can prompt for the next message and re-run.
 */
class WorkflowSimulator
{
    use SharesWorkflowSemantics;

    /**
     * Mirrors WorkflowEngine::MAX_BRANCH_JUMPS — a malformed graph
     * (two branches pointing at each other) fails the run instead of
     * looping forever.
     */
    private const MAX_BRANCH_JUMPS = 32;

    /**
     * @param  array<int, string>  $keywords
     * @param  array<int, array<string, mixed>>  $steps
     * @param  array<int, string>  $messages
     * @return array{
     *     triggered: bool,
     *     status: string,
     *     events: array<int, array<string, mixed>>,
     *     vars: array<string, mixed>,
     *     waiting_var: string|null,
     * }
     */
    public function simulate(array $keywords, string $matchMode, array $steps, array $messages): array
    {
        $first = (string) ($messages[0] ?? '');
        $needle = mb_strtolower(trim($first));

        if ($needle === '' || ! $this->keywordSetMatches($keywords, $matchMode, $needle)) {
            return [
                'triggered' => false,
                'status' => 'no_match',
                'events' => [],
                'vars' => [],
                'waiting_var' => null,
            ];
        }

        $vars = ['trigger_message' => $first];
        $events = [];
        $index = 0;
        $messageCursor = 1;
        $branchJumps = 0;
        $stepCount = count($steps);
        $status = 'completed';
        $waitingVar = null;

        while ($index < $stepCount && $index >= 0) {
            $step = (array) ($steps[$index] ?? []);
            $type = (string) ($step['type'] ?? '');

            switch ($type) {
                case 'message':
                    $text = (string) ($step['text'] ?? '');
                    if ($text !== '') {
                        $events[] = ['step' => $index, 'type' => 'message', 'text' => $this->interpolate($text, $vars)];
                    }
                    $index++;
                    break;

                case 'question':
                    $text = (string) ($step['text'] ?? '');
                    if ($text !== '') {
                        $events[] = ['step' => $index, 'type' => 'question', 'text' => $this->interpolate($text, $vars)];
                    }

                    // Same default as WorkflowEngine::resume() — the
                    // canvas builder inserts exactly this key.
                    $varName = (string) ($step['var_name'] ?? 'visitor_answer');

                    if ($messageCursor >= count($messages)) {
                        // The engine pauses AT the question; the UI asks
                        // for the visitor's reply and re-runs.
                        $status = 'waiting_reply';
                        $waitingVar = $varName;

                        break 2;
                    }

                    $reply = mb_substr((string) $messages[$messageCursor], 0, 2000);
                    $messageCursor++;
                    $vars[$varName] = $reply;
                    $events[] = ['step' => $index, 'type' => 'reply', 'var' => $varName, 'text' => $reply];
                    $index++;
                    break;

                case 'escalate':
                    $text = (string) ($step['text'] ?? "Connecting you with a human now — they'll be with you in a moment.");
                    $events[] = ['step' => $index, 'type' => 'escalate', 'text' => $text];
                    $vars['escalated'] = true;
                    $status = 'escalated';

                    break 2;

                case 'branch':
                    if (++$branchJumps > self::MAX_BRANCH_JUMPS) {
                        $events[] = ['step' => $index, 'type' => 'loop_guard'];
                        $status = 'failed_loop';

                        break 2;
                    }

                    [$target, $matchedCase] = $this->resolveBranchTargetWithCase($step, $vars, $stepCount);
                    if ($target === null) {
                        // No case matched and no default — fall through
                        // to the next step linearly (engine behavior).
                        $target = $index + 1;
                    }
                    $events[] = [
                        'step' => $index,
                        'type' => 'branch',
                        'var' => (string) ($step['var'] ?? ''),
                        'matched_case' => $matchedCase,
                        'go_to' => $target,
                    ];
                    $index = $target;
                    break;

                case 'tag_lead':
                    $tags = $this->normalizeTags((array) ($step['tags'] ?? []));
                    $events[] = ['step' => $index, 'type' => 'tag_lead', 'tags' => $tags];
                    $index++;
                    break;

                case 'webhook':
                    $url = (string) ($step['url'] ?? '');
                    if ($url !== '') {
                        $events[] = [
                            'step' => $index,
                            'type' => 'webhook',
                            'url' => $url,
                            'method' => (string) ($step['method'] ?? 'POST'),
                        ];
                    }
                    $index++;
                    break;

                default:
                    // Unknown step type — engine skips rather than crashes.
                    $events[] = ['step' => $index, 'type' => 'skipped'];
                    $index++;
                    break;
            }
        }

        return [
            'triggered' => true,
            'status' => $status,
            'events' => $events,
            'vars' => $vars,
            'waiting_var' => $waitingVar,
        ];
    }
}
