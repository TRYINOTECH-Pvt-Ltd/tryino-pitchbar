<?php

namespace App\Support;

use App\Models\AuditLog;
use Illuminate\Http\Request;

/**
 * Thin wrapper around AuditLog::create that fills the audit columns
 * every caller needs: workspace id, current user, IP + UA, timestamp.
 *
 * Centralised so individual controllers stop reinventing the same
 * 8-line array. Buyer reported (2026-05-20) the /app/audit page was
 * empty in production — root cause was thin write coverage; this
 * helper makes adding new audit hooks a one-liner so we get broad
 * coverage without the boilerplate tempting devs to skip the log.
 */
class AuditLogger
{
    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    public static function log(
        string $workspaceId,
        string $action,
        ?string $entityType = null,
        ?string $entityId = null,
        array $before = [],
        array $after = [],
        ?Request $request = null,
    ): void {
        $request ??= request();
        $userId = $request?->user()?->id;

        // When no acting user resolves (queue worker, webhook callback,
        // scheduled command, console invocation) tag the row with a
        // sentinel marker in `after.system_origin` so reviewers can
        // tell apart "system did this" from "we forgot to capture the
        // actor". Don't leak the marker when a real user is present.
        if ($userId === null) {
            $after = array_merge(
                ['system_origin' => self::resolveSystemOrigin($request)],
                $after,
            );
        }

        AuditLog::create([
            'workspace_id' => $workspaceId,
            'user_id' => $userId,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'before' => $before,
            'after' => $after,
            'ip' => $request?->ip(),
            'ua' => substr((string) $request?->userAgent(), 0, 240),
            'created_at' => now(),
        ]);
    }

    private static function resolveSystemOrigin(?Request $request): string
    {
        if (app()->runningInConsole()) {
            return 'console';
        }

        if ($request === null) {
            return 'background';
        }

        // Distinguish webhook callbacks (origin header set by a third
        // party, no auth user) from queue-driven writes (no request
        // object at all). Both still tag, but the marker helps when
        // grepping for who-did-what after the fact.
        return 'webhook_or_queue';
    }
}
