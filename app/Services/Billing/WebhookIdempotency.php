<?php

namespace App\Services\Billing;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Cross-gateway webhook idempotency log. Records (gateway, event_id) the
 * first time a webhook fires; subsequent retries (Stripe, PayPal, and
 * Razorpay all retry on slow / non-2xx responses) short-circuit at the
 * controller without re-applying state.
 *
 * Backed by a single `gateway_webhook_events` table with a unique
 * `(gateway, event_id)` constraint. Insert is atomic — if a parallel
 * worker is mid-processing the same event, the second INSERT throws a
 * UniqueConstraintViolation, the catch returns false, and the caller
 * returns 200 without doing the work twice.
 */
final class WebhookIdempotency
{
    public const STRIPE = 'stripe';

    public const PAYPAL = 'paypal';

    public const RAZORPAY = 'razorpay';

    /**
     * Returns true if THIS request is the first time we're seeing the
     * event id for the given gateway. Returns false if it has already
     * been recorded (so the caller can return a fast 200 without
     * re-processing).
     *
     * An empty $eventId is treated as "cannot dedupe" → returns true so
     * the request still runs. The caller should already have validated
     * the event id is present.
     */
    public function recordOrSkip(string $gateway, string $eventId, ?string $eventType = null): bool
    {
        if ($eventId === '') {
            return true;
        }

        try {
            DB::table('gateway_webhook_events')->insert([
                'gateway' => $gateway,
                'event_id' => $eventId,
                'event_type' => $eventType,
                'processed_at' => now(),
            ]);

            return true;
        } catch (QueryException $e) {
            // 23000 = SQLSTATE for integrity constraint violation
            // (MySQL: 1062 duplicate entry; Postgres: unique_violation
            // 23505 / pg sqlstate '23505'). Either means the event id
            // already lives in the table — replay, swallow.
            if ($this->isDuplicateKey($e)) {
                return false;
            }

            throw $e;
        }
    }

    private function isDuplicateKey(QueryException $e): bool
    {
        $sqlState = (string) ($e->errorInfo[0] ?? '');
        $driverCode = (int) ($e->errorInfo[1] ?? 0);

        if ($sqlState === '23000' || $sqlState === '23505') {
            return true;
        }

        // MySQL 1062, MariaDB 1062, SQLite 19 (constraint failed).
        return in_array($driverCode, [1062, 19], true);
    }
}
