<?php

namespace App\Support;

/**
 * Helper for safely embedding upstream HTTP response bodies in
 * exception messages and log lines without leaking bearer tokens,
 * cookies, or other auth material that an upstream service may have
 * echoed back in its error payload.
 */
class HttpErrorRedactor
{
    private const MAX_LENGTH = 240;

    /**
     * @var array<int, string>
     */
    private const SECRET_HEADER_NAMES = [
        'authorization',
        'cookie',
        'set-cookie',
        'x-api-key',
        'x-auth-token',
        'x-bearer-token',
        'proxy-authorization',
    ];

    public static function redact(string $body): string
    {
        $clean = mb_substr($body, 0, self::MAX_LENGTH);

        // Strip "Header-Name: secret" lines wholesale.
        foreach (self::SECRET_HEADER_NAMES as $name) {
            $pattern = '/^'.preg_quote($name, '/').':[^\r\n]*/im';
            $clean = (string) preg_replace($pattern, "{$name}: [REDACTED]", $clean);
        }

        // Bearer + Basic auth values inside JSON / prose.
        $clean = (string) preg_replace('/(Bearer|Basic|Token)\s+[A-Za-z0-9._\-+\/=]{8,}/i', '$1 [REDACTED]', $clean);

        // JWT-shaped triple-chunk tokens (header.payload.signature).
        $clean = (string) preg_replace('/[A-Za-z0-9_\-]{6,}\.[A-Za-z0-9_\-]{6,}\.[A-Za-z0-9_\-]{6,}/', '[REDACTED_TOKEN]', $clean);

        // Long opaque token-like substrings (>=32 chars of base64-ish text).
        $clean = (string) preg_replace('/[A-Za-z0-9_\-]{32,}/', '[REDACTED_TOKEN]', $clean);

        if (mb_strlen($body) > self::MAX_LENGTH) {
            $clean .= '…';
        }

        return $clean;
    }
}
