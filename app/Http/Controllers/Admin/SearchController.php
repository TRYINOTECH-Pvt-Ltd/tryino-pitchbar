<?php

namespace App\Http\Controllers\Admin;

use App\Models\Agent;
use App\Models\Conversation;
use App\Models\Lead;
use App\Models\Message;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Workspace-wide search — agents, conversations, leads, and message
 * bodies, all in one query. Returns up to 5 hits per category. Cheap
 * indexed LIKE queries; suitable for an interactive header bar.
 *
 * Multi-tenancy is enforced by the BelongsToWorkspace / BelongsToAgent
 * traits on each model — no extra filtering needed here.
 */
class SearchController
{
    public function __invoke(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));

        if ($q === '' || mb_strlen($q) < 2) {
            return response()->json(['data' => $this->emptyEnvelope()]);
        }

        $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $q).'%';

        $agents = Agent::query()
            ->where(fn ($qq) => $qq
                ->where('name', 'like', $like)
                ->orWhere('system_prompt', 'like', $like))
            ->limit(5)
            ->get(['id', 'name', 'is_published'])
            ->map(fn (Agent $a) => [
                'id' => $a->id,
                'name' => $a->name,
                'is_published' => $a->is_published,
                'href' => "/app/agents/{$a->id}",
            ]);

        // Find conversations whose page_url matches OR that have a message
        // matching. Two-step lookup keeps each query simple + indexable.
        $convoIdsByMessage = Message::query()
            ->where('content', 'like', $like)
            ->limit(20)
            ->pluck('conversation_id');

        $conversations = Conversation::query()
            ->where(fn ($qq) => $qq
                ->where('page_url', 'like', $like)
                ->orWhereIn('id', $convoIdsByMessage))
            ->with('agent:id,name')
            ->latest('started_at')
            ->limit(5)
            ->get(['id', 'agent_id', 'page_url', 'started_at'])
            ->map(fn (Conversation $c) => [
                'id' => $c->id,
                'agent_name' => $c->agent?->name,
                'page_url' => $c->page_url,
                'started_at' => $c->started_at?->toIso8601String(),
                'href' => "/app/conversations/{$c->id}",
            ]);

        $leads = Lead::query()
            ->where(fn ($qq) => $qq
                ->where('email', 'like', $like)
                ->orWhere('name', 'like', $like)
                ->orWhere('phone', 'like', $like))
            ->latest()
            ->limit(5)
            ->get(['id', 'email', 'name', 'status'])
            ->map(fn (Lead $l) => [
                'id' => $l->id,
                'email' => $l->email,
                'name' => $l->name,
                'status' => $l->status,
                'href' => "/app/inbox/{$l->id}",
            ]);

        return response()->json([
            'data' => [
                'q' => $q,
                'agents' => $agents,
                'conversations' => $conversations,
                'leads' => $leads,
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyEnvelope(): array
    {
        return [
            'q' => '',
            'agents' => [],
            'conversations' => [],
            'leads' => [],
        ];
    }
}
