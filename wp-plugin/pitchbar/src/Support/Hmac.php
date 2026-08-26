<?php

namespace Pitchbar\Support;

/**
 * Pitchbar HMAC signing + verifying helper. Mirrors the server-side
 * `App\Support\HmacSignature` exactly so signed payloads in either
 * direction interop without surprises.
 *
 *   Header: X-Pitchbar-Signature: t={unix_ts},v1={hex_sig}
 *   sig    = hmac_sha256(secret, "{t}.{raw_body}")
 *
 * Replay window: 5 minutes.
 */
final class Hmac
{
    public const REPLAY_WINDOW_SECONDS = 300;

    public static function sign(string $secret, string $body, ?int $timestamp = null): string
    {
        $ts = (string) ($timestamp ?? time());
        $sig = hash_hmac('sha256', $ts.'.'.$body, $secret);

        return 't='.$ts.',v1='.$sig;
    }

    public static function verify(string $header, string $secret, string $body, ?int $now = null): bool
    {
        $parts = self::parse($header);
        if ($parts === null) {
            return false;
        }
        [$ts, $sig] = $parts;

        $clock = $now ?? time();
        if (abs($clock - $ts) > self::REPLAY_WINDOW_SECONDS) {
            return false;
        }

        $expected = hash_hmac('sha256', $ts.'.'.$body, $secret);

        return hash_equals($expected, $sig);
    }

    /**
     * @return array{0:int,1:string}|null
     */
    private static function parse(string $header): ?array
    {
        $ts = null;
        $sig = null;

        foreach (explode(',', $header) as $segment) {
            $segment = trim($segment);
            if ($segment === '') {
                continue;
            }
            [$key, $value] = array_pad(explode('=', $segment, 2), 2, null);
            if ($value === null) {
                continue;
            }
            if ($key === 't' && ctype_digit($value)) {
                $ts = (int) $value;
            } elseif ($key === 'v1' && ctype_xdigit($value) && strlen($value) === 64) {
                $sig = $value;
            }
        }

        if ($ts === null || $sig === null) {
            return null;
        }

        return [$ts, $sig];
    }
}
