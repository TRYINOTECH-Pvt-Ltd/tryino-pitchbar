<?php

namespace App\Support;

/**
 * Shared list of explicit "talk to a human" phrases. Two consumers
 * that must stay in lock-step:
 *   - HumanIntentDetector (hot-path shortcut that emits the
 *     escalation_button block and skips the LLM entirely)
 *   - EscalateToHumanTool::intentKeywords() (fast-router keyword gate)
 *
 * Anchored as full phrases so the single word "person" doesn't
 * short-circuit a message like "person who runs the warehouse".
 */
final class HumanPhrases
{
    /** @var list<string> */
    public const PHRASES = [
        'talk to a human',
        'talk to human',
        'speak to a human',
        'speak to human',
        'connect me to a human',
        'connect me to human',
        'connect to a human',
        'connect to human',
        'connect me with a human',
        'connect me with human',
        'chat with a human',
        'chat with human',
        'real person',
        'real human',
        'live agent',
        'live person',
        'live support',
        'human agent',
        'human support',
        'human please',
        'i want a human',
        'i need a human',
        'talk to someone',
        'speak to someone',
        'talk to a real person',
        'speak to a real person',
        'talk to a representative',
        'speak to a representative',
        'connect to support',
        'connect me to support',
        'transfer me to a human',
        'transfer to a human',
        'put me through to a human',
        'get me a human',
        'is anyone there',
        'is anybody there',
        'is there anyone',
        'customer service',
        'customer support agent',
    ];
}
