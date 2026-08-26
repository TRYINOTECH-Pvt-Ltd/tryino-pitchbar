<?php

namespace App\Models;

use App\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Message extends Model
{
    use HasFactory;
    use HasUuidV7;

    /**
     * `id` is fillable on purpose. The SSE controller mints the turn's
     * message ids up front (MessageStreamController's uuid7 pair) and hands
     * the assistant one to the widget; PersistTurnJob then writes the row
     * with that id so client and server agree on what a turn is called.
     *
     * Without it, `Message::create(['id' => …])` silently dropped the id
     * and HasUuidV7 minted a different one — so the id the visitor was
     * given never existed in the database, and anything referencing that
     * message_id later failed its foreign key.
     *
     * Safe to mass-assign: every `Message::create()` call site builds an
     * explicit array server-side; none of them spread request input.
     */
    protected $fillable = [
        'id',
        'conversation_id', 'role', 'content', 'citations', 'confidence',
        'tokens_in', 'tokens_out', 'latency_ms', 'model',
        'feedback', 'feedback_reason',
    ];

    protected $casts = [
        'citations' => 'array',
        'confidence' => 'float',
        'tokens_in' => 'integer',
        'tokens_out' => 'integer',
        'latency_ms' => 'integer',
        'feedback' => 'integer',
    ];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }
}
