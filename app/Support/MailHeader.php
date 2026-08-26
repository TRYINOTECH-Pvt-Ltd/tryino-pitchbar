<?php

namespace App\Support;

/**
 * Defense-in-depth sanitiser for email subjects composed from
 * user-supplied data. Strips header-injection chars + clips length.
 */
final class MailHeader
{
    private const MAX_SUBJECT_LEN = 200;

    public static function subject(string $value): string
    {
        $clean = str_replace(["\r", "\n", "\0"], '', $value);
        $clean = preg_replace('/\s+/u', ' ', trim($clean)) ?? '';

        if (mb_strlen($clean) > self::MAX_SUBJECT_LEN) {
            $clean = mb_substr($clean, 0, self::MAX_SUBJECT_LEN - 1).'…';
        }

        return $clean;
    }
}
