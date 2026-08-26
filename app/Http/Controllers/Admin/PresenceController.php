<?php

namespace App\Http\Controllers\Admin;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Admin presence heartbeat. The admin tab pings this endpoint every
 * 60 seconds while it's the active foreground tab so the live-chat
 * routing layer can tell who's actually around.
 *
 * Single indexed UPDATE — cheap on every dimension. Throttled at the
 * route level so a misbehaving tab can't flood the database.
 *
 * Members who haven't opted into live chat (live_chat_available = false)
 * still ping; the column just isn't read for routing decisions in that
 * case. Keeping a single heartbeat path means the admin shell doesn't
 * have to know who's opted in vs out.
 */
class PresenceController
{
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user === null) {
            abort(401);
        }

        $user->forceFill(['last_active_at' => now()])->save();

        return response()->json(['data' => ['ok' => true]]);
    }
}
