<?php

namespace App\Http\Controllers\Admin;

use App\Models\Conversation;
use App\Models\ConversationTag;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Apply / detach a tag on a single conversation. The conversation +
 * the tag must both belong to the current workspace; route-model
 * binding handles tenancy via the BelongsToWorkspace and BelongsToAgent
 * global scopes.
 */
class ConversationTagAssignmentController
{
    public function attach(Request $request, Conversation $conversation, ConversationTag $tag): JsonResponse
    {
        $this->authorize($request, $conversation);

        $conversation->tags()->syncWithoutDetaching([
            $tag->id => [
                'applied_by' => $request->user()->id,
                'created_at' => now(),
            ],
        ]);

        return response()->json([
            'data' => ['conversation_id' => $conversation->id, 'tag_id' => $tag->id],
        ]);
    }

    public function detach(Request $request, Conversation $conversation, ConversationTag $tag): JsonResponse
    {
        $this->authorize($request, $conversation);

        $conversation->tags()->detach($tag->id);

        return response()->json([
            'data' => ['conversation_id' => $conversation->id, 'tag_id' => $tag->id],
        ]);
    }

    private function authorize(Request $request, Conversation $conversation): void
    {
        $agent = $conversation->agent()->withoutWorkspaceScope()->first();
        abort_if($agent === null, 404);
        $request->user()->can('update', $agent) || abort(403);
    }
}
