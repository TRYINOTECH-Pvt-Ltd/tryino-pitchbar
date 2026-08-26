<?php

namespace App\Http\Controllers\Widget;

use App\Models\Event;
use App\Models\WidgetEvent;
use App\Services\Widget\WidgetEventRecorder;
use App\Services\Widget\WidgetJwt;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EventsController
{
    /**
     * Client-reported event kinds that are reliability problems, mapped to
     * the Widget Monitor type they bridge to. A `widget.stream_stalled` means
     * the visitor's stream froze (no SSE for 35s, or the 120s ceiling) and
     * the widget gave up — exactly the freeze symptom we want surfaced for an
     * operator, even when the server-side turn looks fine.
     */
    private const BRIDGED_KINDS = [
        'widget.stream_stalled' => WidgetEventRecorder::TYPE_CLIENT_STALLED,
    ];

    public function __construct(
        private WidgetJwt $jwt,
        private WidgetEventRecorder $widgetEvents,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $token = $request->bearerToken() ?? $request->header('X-Widget-Token');
        if (! is_string($token)) {
            return response()->json(['error' => ['code' => 'missing_token']], 401);
        }
        try {
            $claims = $this->jwt->verify($token);
        } catch (\Throwable) {
            return response()->json(['error' => ['code' => 'invalid_token']], 401);
        }

        $data = $request->validate([
            'events' => ['required', 'array', 'max:100'],
            'events.*.kind' => ['required', 'string', 'max:64'],
            'events.*.payload' => ['nullable', 'array'],
        ]);

        $agentId = (string) ($claims['agent_id'] ?? '');
        $conversationId = (string) ($claims['conversation_id'] ?? '');

        foreach ($data['events'] as $event) {
            Event::create([
                'agent_id' => $agentId,
                'conversation_id' => $conversationId ?: null,
                'kind' => $event['kind'],
                'payload' => $event['payload'] ?? [],
                'created_at' => now(),
            ]);

            // Mirror reliability-relevant client signals into the monitor.
            $bridgedType = self::BRIDGED_KINDS[$event['kind']] ?? null;
            if ($bridgedType !== null) {
                $this->widgetEvents->record(
                    type: $bridgedType,
                    severity: WidgetEvent::SEVERITY_WARNING,
                    message: $event['kind'],
                    context: is_array($event['payload'] ?? null) ? $event['payload'] : [],
                    agentId: $agentId,
                    conversationId: $conversationId,
                );
            }
        }

        return response()->json(['data' => ['accepted' => count($data['events'])]]);
    }
}
