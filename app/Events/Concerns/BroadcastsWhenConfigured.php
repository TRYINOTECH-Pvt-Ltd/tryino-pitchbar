<?php

namespace App\Events\Concerns;

/**
 * Skip broadcasting entirely when the configured driver has no real
 * subscribers (`log`, `null`). Saves the wasted work of serialising +
 * dispatching to a sink that nobody is listening on, and — more
 * importantly — prevents the failed-jobs noise the client reported on
 * 2026-05-25 ("Pusher cURL 7 to localhost:8080"): when an operator
 * leaves `BROADCAST_CONNECTION=reverb` set but never starts the Reverb
 * server, every dispatched ShouldBroadcast event blows up the worker.
 *
 * Apply to any `ShouldBroadcast` event. Laravel's broadcasting layer
 * honours `broadcastWhen(): bool` and short-circuits when it returns
 * false.
 */
trait BroadcastsWhenConfigured
{
    public function broadcastWhen(): bool
    {
        $driver = config('broadcasting.default');

        return ! in_array($driver, ['log', 'null', null], true);
    }
}
