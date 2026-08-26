<?php

namespace App\Services\Gdpr;

use App\Models\Conversation;
use App\Models\Event;
use App\Models\Lead;
use App\Models\Message;
use App\Models\Visitor;

/**
 * Assemble the JSON payload for a GDPR Article 15 / 20 export.
 * IP-hash is omitted intentionally (it is itself a PII derivative —
 * the visitor never gave us their IP for purposes other than rate
 * limiting, so we should not surface it back).
 */
class Exporter
{
    /**
     * @return array<string,mixed>
     */
    public function export(Visitor $visitor): array
    {
        $conversations = Conversation::query()
            ->withoutGlobalScopes()
            ->where('visitor_id', $visitor->id)
            ->orderBy('started_at')
            ->get();

        $conversationIds = $conversations->pluck('id');

        $messages = Message::query()
            ->whereIn('conversation_id', $conversationIds)
            ->orderBy('created_at')
            ->get()
            ->groupBy('conversation_id');

        $leads = Lead::query()
            ->withoutGlobalScopes()
            ->whereIn('conversation_id', $conversationIds)
            ->get();

        $events = Event::query()
            ->whereIn('conversation_id', $conversationIds)
            ->orderBy('created_at')
            ->get();

        return [
            'visitor' => [
                'id' => $visitor->id,
                'anonymous_id' => $visitor->anonymous_id,
                'country' => $visitor->country,
                'ua' => $visitor->ua,
                'first_seen_at' => $visitor->first_seen_at?->toIso8601String(),
                'last_seen_at' => $visitor->last_seen_at?->toIso8601String(),
                'visit_count' => $visitor->visit_count,
            ],
            'conversations' => $conversations->map(fn (Conversation $c) => [
                'id' => $c->id,
                'page_url' => $c->page_url,
                'lang' => $c->lang,
                'started_at' => $c->started_at?->toIso8601String(),
                'ended_at' => $c->ended_at?->toIso8601String(),
                'message_count' => $c->message_count,
                'is_lead' => (bool) $c->is_lead,
                'satisfaction' => $c->satisfaction,
                'satisfaction_comment' => $c->satisfaction_comment,
                'messages' => ($messages->get($c->id) ?? collect())->map(fn (Message $m) => [
                    'role' => $m->role,
                    'content' => $m->content,
                    'created_at' => $m->created_at?->toIso8601String(),
                ])->values()->all(),
            ])->values()->all(),
            'leads' => $leads->map(fn (Lead $l) => [
                'id' => $l->id,
                'conversation_id' => $l->conversation_id,
                'email' => $l->email,
                'phone' => $l->phone,
                'name' => $l->name,
                'fields' => $l->fields,
                'status' => $l->status,
                'created_at' => $l->created_at?->toIso8601String(),
            ])->values()->all(),
            'events' => $events->map(fn (Event $e) => [
                'kind' => $e->kind,
                'payload' => $e->payload,
                'created_at' => $e->created_at?->toIso8601String(),
            ])->values()->all(),
            'generated_at' => now()->toIso8601String(),
        ];
    }
}
