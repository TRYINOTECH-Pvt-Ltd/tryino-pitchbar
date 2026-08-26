<?php

namespace App\Jobs\Widget;

use App\Models\WidgetEvent;
use App\Services\Widget\WidgetEventRecorder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Persists one widget-reliability event into `widget_events`. Dispatched
 * by {@see WidgetEventRecorder} from failure sites on
 * (or adjacent to) the visitor hot path — the actual INSERT rides the queue
 * so recording a failure never adds a synchronous DB write to a request.
 *
 * Idempotency isn't required: each failure is a distinct event. Kept on the
 * `analytics` queue alongside the other post-turn side-effect jobs.
 */
class RecordWidgetEventJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * @param  array<string, mixed>  $attributes  Already-shaped WidgetEvent attributes.
     */
    public function __construct(public array $attributes)
    {
        $this->onQueue('analytics');
    }

    public function handle(): void
    {
        WidgetEvent::query()->create($this->attributes);
    }
}
