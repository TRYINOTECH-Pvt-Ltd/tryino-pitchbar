<?php

namespace App\Services\Mcp\Support;

/**
 * Sanitise the args the LLM produced before they hit an external
 * MCP server.
 *
 * v1 does NOT run a full JSON Schema validation (that lives in
 * Phase 4 follow-up). What we DO catch:
 *  - Non-array values (LLM produced a scalar where an object was
 *    expected).
 *  - UTF-8 invalid surrogate pairs from visitor text relayed
 *    verbatim — JSON_THROW_ON_ERROR catches and we replace the
 *    offending key with a marker.
 *  - Strip null values that some LLMs emit for unspecified params,
 *    so the server sees clean payloads.
 *
 * SQL/shell injection in args is NOT our concern — the MCP server
 * is responsible for its own input safety. We never pretend to
 * sanitize for downstream systems we don't own.
 */
final class ArgsSanitizer
{
    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    public static function sanitize(array $args): array
    {
        try {
            $reencoded = json_encode($args, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
            $decoded = json_decode((string) $reencoded, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return ['__sanitizer_error' => 'Arguments contained invalid UTF-8 or unrepresentable values.'];
        }

        if (! is_array($decoded)) {
            return [];
        }

        return self::stripNulls($decoded);
    }

    /**
     * @param  array<string, mixed>  $a
     * @return array<string, mixed>
     */
    private static function stripNulls(array $a): array
    {
        $out = [];
        foreach ($a as $k => $v) {
            if ($v === null) {
                continue;
            }
            if (is_array($v) && array_keys($v) !== range(0, count($v) - 1)) {
                $out[$k] = self::stripNulls($v);

                continue;
            }
            $out[$k] = $v;
        }

        return $out;
    }
}
