<?php

namespace App\Http\Controllers\Widget;

use App\Models\Conversation;
use App\Services\Widget\WidgetJwt;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Visitor opts to wipe their conversation from view via the widget's
 * "Clear conversation" menu. We don't delete data — analytics, lead
 * linkage, and live-agent claim history all depend on it. Instead we
 * stamp `cleared_at`, and InitController filters messages with
 * created_at <= cleared_at out of the hydrated history on next load.
 *
 * The same conversation_id keeps working for new turns (no JWT swap),
 * since fresh turns always have created_at > the stamp we just wrote.
 */
class ConversationClearController
{
    public function __construct(private WidgetJwt $jwt) {}

    public function __invoke(Request $request): JsonResponse
    {
        $token = $request->bearerToken() ?? $request->header('X-Widget-Token');

        if (! is_string($token)) {
            return response()->json(['error' => ['code' => 'missing_token']], 401);
        }

        try {
            $claims = $this->jwt->verify($token);
        } catch (\Throwable) {
            return response()->json(['error' => ['code' => 'invalid_token']], 401);
        }

        $conversationId = (string) ($claims['conversation_id'] ?? '');
        $conversation = Conversation::query()->withoutWorkspaceScope()->find($conversationId);

        if ($conversation === null) {
            return response()->json(['error' => ['code' => 'conversation_not_found']], 404);
        }

        $conversation->forceFill(['cleared_at' => now()])->save();

        // The LLM prompt reads its history from this cache, not from the
        // messages table — without forgetting it, the very next turn
        // continues the conversation the visitor just cleared.
        Cache::forget("conv:{$conversationId}:history");

        return response()->json([
            'data' => [
                'conversation_id' => $conversationId,
                'cleared_at' => $conversation->cleared_at?->toIso8601String(),
            ],
        ]);
    }
}
