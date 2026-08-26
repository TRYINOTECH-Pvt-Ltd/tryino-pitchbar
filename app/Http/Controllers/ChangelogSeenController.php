<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * One-shot "I've seen the changelog" mark. Stamps
 * users.last_changelog_seen_at so the What's-new banner stops
 * showing for this user until the next published entry lands.
 */
class ChangelogSeenController
{
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user === null) {
            return response()->json(['ok' => false], 401);
        }

        $user->forceFill(['last_changelog_seen_at' => now()])->save();

        return response()->json(['ok' => true]);
    }
}
