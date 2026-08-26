<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Best-effort agent-id resolution for a queued job. Used by RecordJobRun
 * to populate `job_runs.agent_id` so the platform-admin "Queue health"
 * widget can render an Agent name pill next to each row.
 *
 * Strategy:
 *   1. Regex the PHP-serialized command for an id-like property
 *      (`agentId`, `documentId`, `sourceId`, `conversationId`,
 *      `leadId`, `contentGapId`).
 *   2. Resolve via cheap indexed lookups when the id points to a
 *      sibling model (Document/Source/Conversation/Lead/ContentGap →
 *      agent_id).
 *
 * The extractor NEVER unserializes the command — that would risk
 * triggering the job's own __wakeup, model eager loads, or other
 * side-effects on a listener path that fires on every dispatch.
 * Regex is good enough for the well-known job shapes in this app.
 *
 * Returns null when no id is found OR the id doesn't resolve. Callers
 * must treat null as "agent unknown", not as an error.
 */
final class AgentIdExtractor
{
    /** UUIDv7 is 36 chars; allow uuid-v4 + other 32-36 hex/dash shapes. */
    private const UUID_RE = '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}';

    public static function fromCommand(string $serializedCommand): ?string
    {
        if ($serializedCommand === '') {
            return null;
        }

        // Direct agentId on the job (DetectGapJob, etc.)
        if (self::matchUuid($serializedCommand, 'agentId') !== null) {
            return self::matchUuid($serializedCommand, 'agentId');
        }

        // Chain through Conversation -> agent_id.
        $conversationId = self::matchUuid($serializedCommand, 'conversationId');
        if ($conversationId !== null) {
            $agentId = self::lookupAgent('conversations', 'id', $conversationId);
            if ($agentId !== null) {
                return $agentId;
            }
        }

        // Document -> agent_id (IndexDocumentJob).
        $documentId = self::matchUuid($serializedCommand, 'documentId');
        if ($documentId !== null) {
            $agentId = self::lookupAgent('documents', 'id', $documentId);
            if ($agentId !== null) {
                return $agentId;
            }
        }

        // Source -> agent_id (CrawlPageJob, sitemap fetcher).
        $sourceId = self::matchUuid($serializedCommand, 'sourceId');
        if ($sourceId !== null) {
            $agentId = self::lookupAgent('sources', 'id', $sourceId);
            if ($agentId !== null) {
                return $agentId;
            }
        }

        // Lead -> agent_id (lead-email side jobs).
        $leadId = self::matchUuid($serializedCommand, 'leadId');
        if ($leadId !== null) {
            $agentId = self::lookupAgent('leads', 'id', $leadId);
            if ($agentId !== null) {
                return $agentId;
            }
        }

        // ContentGap -> agent_id (gap-resolution jobs).
        $gapId = self::matchUuid($serializedCommand, 'contentGapId');
        if ($gapId !== null) {
            $agentId = self::lookupAgent('content_gaps', 'id', $gapId);
            if ($agentId !== null) {
                return $agentId;
            }
        }

        return null;
    }

    private static function matchUuid(string $serialized, string $property): ?string
    {
        $propLen = strlen($property);
        $pattern = '/s:'.$propLen.':"'.preg_quote($property, '/').'";s:\d+:"('.self::UUID_RE.')"/i';
        if (preg_match($pattern, $serialized, $matches) === 1) {
            return strtolower($matches[1]);
        }

        return null;
    }

    private static function lookupAgent(string $table, string $idCol, string $id): ?string
    {
        try {
            $row = DB::table($table)->where($idCol, $id)->value('agent_id');

            return is_string($row) && $row !== '' ? $row : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
