<?php

namespace App\Services\Gdpr;

use App\Models\Conversation;
use App\Models\Event;
use App\Models\Lead;
use App\Models\Visitor;
use Illuminate\Support\Facades\DB;

/**
 * Hard-erase visitor PII while keeping anonymized conversation history.
 * conversations.visitor_id is FK ON DELETE SET NULL, so deleting the
 * visitor preserves the conversation rows with visitor_id=NULL. Message
 * content is retained — RAG corpus has no personalised text by design,
 * and admins may need conversation transcripts for audit/dispute.
 */
class Eraser
{
    public function erase(Visitor $visitor): void
    {
        DB::transaction(function () use ($visitor): void {
            $conversationIds = Conversation::query()
                ->withoutGlobalScopes()
                ->where('visitor_id', $visitor->id)
                ->pluck('id');

            if ($conversationIds->isNotEmpty()) {
                Lead::query()
                    ->withoutGlobalScopes()
                    ->whereIn('conversation_id', $conversationIds)
                    ->update([
                        'email' => null,
                        'phone' => null,
                        'name' => null,
                        'fields' => json_encode([]),
                    ]);

                Event::query()
                    ->whereIn('conversation_id', $conversationIds)
                    ->delete();
            }

            $visitor->delete();
        });
    }
}
