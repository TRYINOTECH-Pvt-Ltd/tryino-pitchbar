<?php

namespace App\Http\Controllers\Widget;

use App\Models\Conversation;
use App\Models\Lead;
use App\Models\Message;
use App\Models\Visitor;
use App\Services\Widget\WidgetJwt;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * GDPR Article 17 — Right to Erasure.
 *
 * The widget's visitor pane exposes a "Delete my conversation history"
 * link. Hitting it sends the bearer JWT here; we wipe the visitor row,
 * all of their conversations, all messages, and any captured lead. The
 * vector store has no PII (chunks come from public website content), so
 * nothing to clean there.
 */
class GdprController
{
    public function __construct(private WidgetJwt $jwt) {}

    public function delete(Request $request): JsonResponse
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

        $visitorId = (string) ($claims['visitor_id'] ?? '');
        $agentId = (string) ($claims['agent_id'] ?? '');
        if ($visitorId === '' || $agentId === '') {
            return response()->json(['error' => ['code' => 'invalid_token']], 401);
        }

        $deleted = DB::transaction(function () use ($visitorId, $agentId): array {
            $visitor = Visitor::query()->withoutGlobalScopes()
                ->where('id', $visitorId)
                ->where('agent_id', $agentId)
                ->first();

            if ($visitor === null) {
                return ['conversations' => 0, 'messages' => 0, 'leads' => 0];
            }

            $conversationIds = Conversation::query()->withoutGlobalScopes()
                ->where('visitor_id', $visitor->id)
                ->pluck('id')
                ->all();

            $messageCount = $conversationIds === []
                ? 0
                : Message::query()->whereIn('conversation_id', $conversationIds)->delete();

            $leadCount = $conversationIds === []
                ? 0
                : Lead::query()->withoutGlobalScopes()->whereIn('conversation_id', $conversationIds)->delete();

            $convCount = Conversation::query()->withoutGlobalScopes()
                ->whereIn('id', $conversationIds)
                ->delete();

            $visitor->delete();

            return [
                'conversations' => $convCount,
                'messages' => $messageCount,
                'leads' => $leadCount,
            ];
        });

        return response()->json(['data' => array_merge(['ok' => true], $deleted)]);
    }
}
