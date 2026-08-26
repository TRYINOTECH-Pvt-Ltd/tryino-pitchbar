<?php

use App\Models\Agent;
use App\Models\Conversation;
use App\Models\WorkspaceUser;
use App\Services\Widget\WidgetJwt;
use Illuminate\Support\Facades\Broadcast;

/**
 * private-conversation.{conversation_id} — dual-authorized channel.
 *
 *   - Visitor side: the widget presents its WidgetJwt; we accept if the
 *     token's conversation_id claim matches.
 *   - Operator side: an authenticated workspace member with `update`
 *     permission on the conversation's agent is also accepted, so the
 *     inbox UI can subscribe to the visitor's incoming messages live.
 */
Broadcast::channel('conversation.{conversation_id}', function ($user, string $conversation_id) {
    // Operator path — authenticated session.
    if ($user !== null) {
        $conversation = Conversation::query()->withoutGlobalScopes()->find($conversation_id);
        if ($conversation === null) {
            return false;
        }
        $agent = $conversation->agent()->withoutGlobalScopes()->first();
        if ($agent === null) {
            return false;
        }

        return $user->can('update', $agent);
    }

    // Visitor path — JWT.
    $jwt = request()->bearerToken() ?? request()->header('X-Widget-Token');
    if ($jwt === null) {
        return false;
    }

    try {
        $claims = app(WidgetJwt::class)->verify($jwt);
    } catch (Throwable) {
        return false;
    }

    return ($claims['conversation_id'] ?? null) === $conversation_id;
});

/**
 * private-agent.{agent_id}.events — admin-side live inbox.
 */
Broadcast::channel('agent.{agent_id}.events', function ($user, string $agent_id) {
    if ($user === null) {
        return false;
    }

    return Agent::query()
        ->withoutGlobalScopes()
        ->whereKey($agent_id)
        ->whereHas('workspace.members', fn ($q) => $q->whereKey($user->id))
        ->exists();
});

/**
 * private-workspace.{workspace_id}.leads — fan-out to every workspace
 * member. Used for live lead-capture toasts in the admin sidebar so an
 * owner with the dashboard open sees a captured lead the same second
 * the visitor submits the inline form. Subscribed once per session
 * regardless of which workspace agent is in scope, so the watcher
 * doesn't have to poke per-agent channels.
 */
Broadcast::channel('workspace.{workspace_id}.leads', function ($user, string $workspace_id) {
    if ($user === null) {
        return false;
    }

    return WorkspaceUser::query()
        ->where('workspace_id', $workspace_id)
        ->where('user_id', $user->id)
        ->whereNotNull('accepted_at')
        ->exists();
});
