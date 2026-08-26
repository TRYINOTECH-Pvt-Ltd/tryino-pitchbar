<?php

namespace App\Services\LiveChat;

use App\Models\Workspace;
use Carbon\Carbon;
use Carbon\CarbonImmutable;

/**
 * Per-workspace business-hours resolver.
 *
 * The `workspaces.business_hours` JSON column carries the schedule:
 *
 *   {
 *     "enabled": true,
 *     "timezone": "America/New_York",
 *     "schedule": {
 *       "monday":    [{"start": "09:00", "end": "17:00"}],
 *       "tuesday":   [{"start": "09:00", "end": "17:00"}],
 *       "wednesday": [{"start": "09:00", "end": "12:00"},
 *                     {"start": "13:00", "end": "17:00"}],
 *       ...
 *       "saturday":  [],
 *       "sunday":    []
 *     }
 *   }
 *
 * Missing config or `enabled: false` = always open. Time strings are
 * 24-hour `HH:mm`. A day with `[]` is closed all day. Multiple windows
 * per day are supported (e.g. lunch break).
 */
class BusinessHours
{
    public function isOpen(Workspace $workspace, ?Carbon $when = null): bool
    {
        $config = $workspace->business_hours;

        // No config or disabled → always-on. Buyers who don't bother
        // configuring this should not get an unexpected "We're closed"
        // surface; opt-in is intentional.
        if (! is_array($config) || ! ($config['enabled'] ?? false)) {
            return true;
        }

        $timezone = is_string($config['timezone'] ?? null) && $config['timezone'] !== ''
            ? (string) $config['timezone']
            : 'UTC';

        $now = ($when ?? Carbon::now())->copy()->setTimezone($timezone);
        $dayKey = strtolower($now->format('l')); // monday, tuesday, …
        $slots = $config['schedule'][$dayKey] ?? null;

        if (! is_array($slots) || $slots === []) {
            return false;
        }

        $minutesNow = ($now->hour * 60) + $now->minute;
        foreach ($slots as $slot) {
            $start = $this->parseHHmm((string) ($slot['start'] ?? ''));
            $end = $this->parseHHmm((string) ($slot['end'] ?? ''));
            if ($start === null || $end === null) {
                continue;
            }
            if ($minutesNow >= $start && $minutesNow < $end) {
                return true;
            }
        }

        return false;
    }

    /**
     * The next time the workspace will be open as an ISO-8601 string in
     * the workspace's timezone. Returns null when always-open or when
     * the schedule has no positive slots at all.
     */
    public function nextOpenAt(Workspace $workspace, ?Carbon $when = null): ?string
    {
        $config = $workspace->business_hours;
        if (! is_array($config) || ! ($config['enabled'] ?? false)) {
            return null;
        }
        if ($this->isOpen($workspace, $when)) {
            return null;
        }

        $timezone = is_string($config['timezone'] ?? null) && $config['timezone'] !== ''
            ? (string) $config['timezone']
            : 'UTC';

        $cursor = CarbonImmutable::instance($when ?? Carbon::now())->setTimezone($timezone);

        // Look up to 8 days ahead. We need 8 (not 7) because today's
        // slots may have already passed — in that case the answer is
        // "next week, same day", which lands at offset 7 from today.
        for ($i = 0; $i < 8; $i++) {
            $day = $cursor->addDays($i);
            $slots = $config['schedule'][strtolower($day->format('l'))] ?? [];
            if (! is_array($slots) || $slots === []) {
                continue;
            }

            foreach ($slots as $slot) {
                $start = $this->parseHHmm((string) ($slot['start'] ?? ''));
                if ($start === null) {
                    continue;
                }
                $candidate = $day->setTime(intdiv($start, 60), $start % 60);

                // Skip slots earlier than the reference cursor — compare
                // to $cursor (the caller-supplied moment), NOT real time;
                // tests inject historical $when values and we'd return
                // null for everything past.
                if ($candidate->lessThanOrEqualTo($cursor)) {
                    continue;
                }

                return $candidate->toIso8601String();
            }
        }

        return null;
    }

    /**
     * Parse "HH:mm" → minutes-since-midnight, returning null on
     * malformed input. Defensive — the schedule is JSON the admin
     * types in, anything could land here.
     */
    private function parseHHmm(string $value): ?int
    {
        if (! preg_match('/^(\d{1,2}):(\d{2})$/', $value, $m)) {
            return null;
        }
        $h = (int) $m[1];
        $mm = (int) $m[2];
        if ($h < 0 || $h > 24 || $mm < 0 || $mm >= 60) {
            return null;
        }

        return ($h * 60) + $mm;
    }
}
