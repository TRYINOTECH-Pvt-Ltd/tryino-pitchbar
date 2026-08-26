<?php

namespace App\Http\Controllers\Widget;

use App\Models\Conversation;
use App\Services\Widget\WidgetJwt;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Visitor typing-indicator hint. Sets the conversation's
 * `visitor_typing_until` to now + 5s. The operator's polling read
 * surfaces the indicator without us needing a separate broadcast
 * channel.
 *
 * Frontend debounces typing fires to one POST every 2s while
 * characters are being entered; the throttle on the route is a
 * safety net.
 */
class TypingController
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
        $conversation = Conversation::query()
            ->withoutGlobalScopes()
            ->find($conversationId);
        if ($conversation === null) {
            return response()->json(['error' => ['code' => 'conversation_not_found']], 404);
        }

        $conversation->forceFill([
            'visitor_typing_until' => now()->addSeconds(5),
        ])->save();

        return response()->json(['data' => ['ok' => true]]);
    }
}
