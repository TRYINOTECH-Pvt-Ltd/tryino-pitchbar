<?php

namespace App\Services\Tools\Tools;

use App\Models\Agent;
use App\Models\Ticket;
use App\Services\Tools\Contracts\HasIntentSignals;
use App\Services\Tools\Contracts\Tool;
use Illuminate\Support\Str;

/**
 * Creates a durable Ticket row when the LLM decides the visitor needs
 * human follow-up. The result message returned to the LLM carries the
 * ticket id so the model can quote it back to the visitor; the widget
 * receives a `block` event tagged with the ticket for inline render.
 *
 * Capability: `ticketing`. Enabled by default on the help_center
 * vertical preset; can be opted in for any agent via
 * `vertical_overrides.capabilities`.
 */
class OpenTicketTool implements HasIntentSignals, Tool
{
    public function name(): string
    {
        return 'open_ticket';
    }

    public function description(): string
    {
        return 'Open a durable support ticket for human follow-up. Use this when the visitor reports an issue that can\'t be resolved in chat — billing dispute, account problem, bug report, feature complaint. Pass a concise subject line and the visitor\'s context as the body.';
    }

    public function capability(): string
    {
        return 'ticketing';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'subject' => [
                    'type' => 'string',
                    'description' => 'One-line summary of the issue (≤ 120 chars).',
                ],
                'body' => [
                    'type' => 'string',
                    'description' => 'Full context for the human operator. Include the visitor\'s account / order references, what they tried, what went wrong.',
                ],
                'priority' => [
                    'type' => 'string',
                    'enum' => ['low', 'normal', 'high', 'urgent'],
                    'description' => 'Default normal. Use urgent only for outages or payment failures.',
                ],
            ],
            'required' => ['subject', 'body'],
        ];
    }

    public function execute(array $args, Agent $agent, array $context = []): array
    {
        $subject = mb_substr(trim((string) ($args['subject'] ?? '')), 0, 200);
        $body = trim((string) ($args['body'] ?? ''));
        $priority = (string) ($args['priority'] ?? Ticket::PRIORITY_NORMAL);
        if (! in_array($priority, [
            Ticket::PRIORITY_LOW, Ticket::PRIORITY_NORMAL,
            Ticket::PRIORITY_HIGH, Ticket::PRIORITY_URGENT,
        ], true)) {
            $priority = Ticket::PRIORITY_NORMAL;
        }

        if ($subject === '' || $body === '') {
            return [
                'result' => [
                    'success' => false,
                    'error' => 'subject and body are required',
                ],
            ];
        }

        $conversation = $context['conversation'] ?? null;
        $ticket = Ticket::query()->withoutWorkspaceScope()->create([
            'id' => (string) Str::uuid7(),
            'workspace_id' => $agent->workspace_id,
            'agent_id' => $agent->id,
            'conversation_id' => is_object($conversation) ? $conversation->id : null,
            'subject' => $subject,
            'body' => $body,
            'status' => Ticket::STATUS_OPEN,
            'priority' => $priority,
            'metadata' => [
                'source' => 'open_ticket_tool',
            ],
        ]);

        return [
            'result' => [
                'success' => true,
                'ticket_id' => $ticket->id,
                'status' => $ticket->status,
                'priority' => $ticket->priority,
            ],
            'block' => [
                'type' => 'ticket_opened',
                'payload' => [
                    'ticket_id' => $ticket->id,
                    'subject' => $subject,
                    'priority' => $priority,
                ],
            ],
        ];
    }

    /** @return list<string> */
    public function intentKeywords(): array
    {
        return [
            'open a ticket',
            'file a ticket',
            'submit a ticket',
            'create a ticket',
            'report a bug',
            'report an issue',
            'report a problem',
        ];
    }

    /** @return list<string> */
    public function intentExemplars(): array
    {
        return [
            'please open a support ticket for this',
            'I want to report a bug I found',
            'file a ticket, my account is broken',
        ];
    }
}
