<?php

namespace App\Services\Tools\Tools;

use App\Models\Agent;
use App\Services\Tools\Contracts\HasIntentSignals;
use App\Services\Tools\Contracts\Tool;
use App\Support\HumanPhrases;

/**
 * Hands the conversation off to a human operator. Universal — every
 * vertical that has a `ticket_escalation` capability surfaces this.
 *
 * The execution side is informational: it returns a payload the LLM
 * folds into its final response, plus a `block` payload the widget
 * renders as a clickable "Connect me with a human" button. Actual
 * claim of the conversation by a human happens via the existing
 * ConversationTakeoverController flow.
 */
class EscalateToHumanTool implements HasIntentSignals, Tool
{
    public function name(): string
    {
        return 'escalate_to_human';
    }

    public function description(): string
    {
        // Buyer report 2026-05-18 (Lithuanian customer): small Workers
        // AI models call this tool on almost every turn, so the
        // "Connect me with a human" button showed up on every reply.
        // Tightened scope to ONLY explicit, in-message human requests
        // — the broader sentiment / unresolved-issue clauses
        // encouraged over-eager invocation. Server also suppresses
        // duplicate emissions in MessageStreamController::runToolLoop.
        return 'Offer the visitor an option to connect with a human support agent. Call this tool ONLY when the visitor explicitly asks for a human in their current message (phrases like "talk to a human", "speak to an agent", "connect me to support"). Do NOT call it when answering general product / pricing / feature questions, when low-confidence answers are produced, or based on inferred sentiment alone.';
    }

    public function capability(): string
    {
        return 'ticket_escalation';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'reason' => [
                    'type' => 'string',
                    'description' => 'A short reason for the escalation, surfaced to the operator.',
                ],
            ],
            'required' => ['reason'],
        ];
    }

    public function execute(array $args, Agent $agent, array $context = []): array
    {
        $reason = (string) ($args['reason'] ?? 'Visitor requested a human.');

        return [
            'result' => [
                'status' => 'offered',
                'message' => 'A human-handoff button has been shown to the visitor.',
            ],
            'block' => [
                'type' => 'escalation_button',
                'payload' => [
                    'label' => 'Connect me with a human',
                    'reason' => $reason,
                ],
            ],
        ];
    }

    /**
     * Fast-router signals. Keywords reuse the shared HumanPhrases list
     * so the router and the upstream HumanIntentDetector shortcut stay
     * in lock-step.
     *
     * @return list<string>
     */
    public function intentKeywords(): array
    {
        return HumanPhrases::PHRASES;
    }

    /** @return list<string> */
    public function intentExemplars(): array
    {
        return [
            'I want to talk to a human',
            'connect me with support',
            'can a real person help me',
            'I need to speak with an agent about my problem',
        ];
    }
}
