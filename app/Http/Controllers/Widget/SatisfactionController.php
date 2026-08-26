<?php

namespace App\Http\Controllers\Widget;

use App\Models\Conversation;
use App\Services\Widget\WidgetJwt;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Visitor-side post-conversation rating. Captures a thumbs up / down
 * + optional comment after a human operator has handled the chat.
 *
 * Idempotent on the rating itself — the first rating sticks. Later
 * submissions update the comment but don't flip thumbs up to thumbs
 * down (or vice versa). Buyers complained about Intercom-style
 * "rating overwritten" surprises; we lock the rating once given.
 */
class SatisfactionController
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

        $data = $request->validate([
            'rating' => ['required', Rule::in(['positive', 'negative'])],
            'comment' => ['nullable', 'string', 'max:2000'],
        ]);

        $conversationId = (string) ($claims['conversation_id'] ?? '');
        $conversation = Conversation::query()
            ->withoutGlobalScopes()
            ->find($conversationId);
        if ($conversation === null) {
            return response()->json(['error' => ['code' => 'conversation_not_found']], 404);
        }

        $patch = ['satisfaction_comment' => $data['comment'] ?? $conversation->satisfaction_comment];

        // Lock the first rating in. Later POSTs update the comment
        // only.
        if ($conversation->satisfaction === null) {
            $patch['satisfaction'] = $data['rating'];
            $patch['satisfaction_at'] = now();
        }

        $conversation->forceFill($patch)->save();

        return response()->json([
            'data' => [
                'conversation_id' => $conversation->id,
                'rating' => $conversation->satisfaction,
                'rated_at' => $conversation->satisfaction_at?->toIso8601String(),
            ],
        ]);
    }
}
