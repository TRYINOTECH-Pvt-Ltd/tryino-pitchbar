<?php

use App\Models\Message;

/**
 * The SSE controller mints a turn's message ids up front and hands the
 * assistant one to the widget; PersistTurnJob later writes the row with that
 * id. If `id` ever leaves $fillable, create() silently drops it, HasUuidV7
 * mints a different one, and the id the visitor holds never exists in the
 * database — every later reference to that message_id fails its foreign key.
 */
test('a message persists under the exact id the stream handed out', function () {
    $message = new Message;
    $message->fill(['id' => '01912345-0000-7000-8000-000000000001']);

    expect($message->id)->toBe('01912345-0000-7000-8000-000000000001');
});
