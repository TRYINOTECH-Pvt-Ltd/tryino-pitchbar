<?php

namespace App\Http\Controllers\Admin;

use App\Models\Agent;
use App\Models\Conversation;
use App\Models\Visitor;
use App\Services\Rag\RagPipeline;
use App\Services\Vertical\VerticalPresetRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class PlaygroundController
{
    public function show(Request $request, Agent $agent, VerticalPresetRegistry $presets): Response
    {
        $request->user()->can('update', $agent) || abort(403);

        // Pre-compute the per-vertical preset preview (label,
        // description, starter prompts, capabilities) so the right-pane
        // scenario panel can show what each override will do without a
        // second HTTP roundtrip.
        $verticalsPreview = [];
        foreach ($presets->all() as $slug => $preset) {
            $verticalsPreview[$slug] = [
                'slug' => $preset->slug(),
                'label' => $preset->label(),
                'short_description' => $preset->shortDescription(),
                'starter_prompts' => $preset->starterPrompts(),
                'capabilities' => $preset->capabilities(),
            ];
        }

        return Inertia::render('app/agents/playground', [
            'agent' => [
                'id' => $agent->id,
                'name' => $agent->name,
                'site_type' => $agent->site_type,
                'persona' => $agent->persona,
                'theme' => $agent->theme,
                'language_default' => $agent->language_default,
            ],
            'verticals' => $verticalsPreview,
        ]);
    }

    /**
     * Legacy non-streaming send. Kept so the old playground.tsx path
     * still works during the rollout, but the new playground.tsx
     * exclusively uses /playground/stream. Will be removed once the
     * dashboard ships.
     */
    public function send(Request $request, Agent $agent, RagPipeline $rag): JsonResponse
    {
        $request->user()->can('update', $agent) || abort(403);

        $data = $request->validate([
            'message' => ['required', 'string', 'max:4000'],
            'conversation_id' => ['nullable', 'string'],
        ]);

        $conversationId = $data['conversation_id'] ?? null;
        if ($conversationId === null) {
            $visitor = Visitor::create([
                'agent_id' => $agent->id,
                'anonymous_id' => 'pg_'.Str::random(16),
                'first_seen_at' => now(),
                'last_seen_at' => now(),
            ]);
            $conversation = Conversation::create([
                'agent_id' => $agent->id,
                'visitor_id' => $visitor->id,
                'page_url' => '/playground',
                'started_at' => now(),
                'is_playground' => true,
            ]);
            $conversationId = $conversation->id;
        }

        $result = $rag->handle($conversationId, $data['message'], isPlayground: true);

        return response()->json([
            'data' => [
                'conversation_id' => $conversationId,
                ...$result,
            ],
        ]);
    }

    /**
     * Wipe the cached history for a playground conversation so the next
     * send starts from an empty context. Doesn't touch the DB row —
     * we leave it around so the conversation_id stays stable across
     * resets and admins can inspect the latency metrics in the
     * diagnostics pane between turns.
     */
    public function reset(Request $request, Agent $agent): JsonResponse
    {
        $request->user()->can('update', $agent) || abort(403);

        $data = $request->validate([
            'conversation_id' => ['required', 'string'],
        ]);

        // Confirm the conversation belongs to this agent — never trust
        // the body to point at someone else's conversation.
        $conversation = Conversation::query()
            ->where('id', $data['conversation_id'])
            ->where('agent_id', $agent->id)
            ->first();
        if ($conversation !== null) {
            Cache::forget("conv:{$conversation->id}:history");
        }

        return response()->json(['data' => ['ok' => true]]);
    }
}
