<?php

namespace App\Http\Controllers\Widget;

use App\Jobs\Analytics\RecomputeLeadScoreJob;
use App\Jobs\Leads\RouteLeadJob;
use App\Models\Conversation;
use App\Models\Lead;
use App\Services\Widget\WidgetJwt;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LeadController
{
    public function __construct(private WidgetJwt $jwt) {}

    public function __invoke(Request $request): JsonResponse
    {
        $token = $request->bearerToken() ?? $request->header('X-Widget-Token');
        if (! is_string($token)) {
            return response()->json(['error' => ['code' => 'missing_token']], 401);
        }
        try {
            $claims = $this->jwt->verify($token);
        } catch (\Throwable $e) {
            return response()->json(['error' => ['code' => 'invalid_token']], 401);
        }

        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'name' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'fields' => ['nullable', 'array'],
        ]);

        $conversationId = (string) ($claims['conversation_id'] ?? '');
        $conversation = Conversation::query()->withoutWorkspaceScope()->findOrFail($conversationId);

        // Two-tier dedup so a visitor who submits the form twice doesn't
        // pollute the inbox:
        //   1. Same conversation → always update that lead.
        //   2. Different conversation, same (agent_id, normalized email)
        //      → reattach the lead to the new conversation, update fields.
        // Otherwise → fresh Lead.
        $email = strtolower(trim((string) $data['email']));

        $existing = Lead::query()->withoutWorkspaceScope()
            ->where('conversation_id', $conversationId)
            ->first();

        if ($existing === null) {
            $existing = Lead::query()->withoutWorkspaceScope()
                ->where('agent_id', $conversation->agent_id)
                ->whereRaw('LOWER(email) = ?', [$email])
                ->first();
        }

        if ($existing === null) {
            $lead = Lead::create([
                'conversation_id' => $conversation->id,
                'agent_id' => $conversation->agent_id,
                'email' => $email,
                'name' => $data['name'] ?? null,
                'phone' => $data['phone'] ?? null,
                'fields' => $data['fields'] ?? [],
                'status' => 'new',
            ]);
        } else {
            $existing->forceFill([
                'conversation_id' => $conversation->id,
                'email' => $email,
                'name' => $data['name'] ?? $existing->name,
                'phone' => $data['phone'] ?? $existing->phone,
                'fields' => array_merge((array) ($existing->fields ?? []), $data['fields'] ?? []),
            ])->save();
            $lead = $existing;
        }

        $conversation->forceFill(['is_lead' => true])->save();

        // Recompute the lead score now that contact info exists.
        // RouteLeadJob's webhook payload reads the persisted score, so
        // we dispatchSync to make sure the score is up to date by the
        // time the webhook fires. RecomputeLeadScoreJob is cheap (one
        // SELECT + one UPDATE).
        RecomputeLeadScoreJob::dispatchSync($conversation->id);

        RouteLeadJob::dispatch($lead->id);

        return response()->json(['data' => ['id' => $lead->id, 'status' => $lead->status]]);
    }
}
