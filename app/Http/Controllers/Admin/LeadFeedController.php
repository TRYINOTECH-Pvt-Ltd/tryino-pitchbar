<?php

namespace App\Http\Controllers\Admin;

use App\Models\Conversation;
use App\Models\Lead;
use App\Models\Message;
use App\Support\CurrentWorkspace;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Tiny polling endpoint the admin shell hits every ~30s to detect new
 * leads since the previous poll. Two reasons it is JSON instead of an
 * Inertia partial visit:
 *
 *   1. The shell mounts on every page; we don't want to overwrite
 *      the page's own props with a sidebar concern.
 *   2. The hook tracks the latest lead id locally, so the response
 *      stays tiny — just enough to render a sonner toast and (when
 *      the user has granted permission) a native browser notification.
 *
 * Auth is the same as every workspace-scoped controller — workspace.require
 * middleware runs before this hits the controller, so we can trust
 * CurrentWorkspace.
 */
class LeadFeedController
{
    public function __invoke(Request $request, CurrentWorkspace $current): JsonResponse
    {
        $workspace = $current->get();
        if ($workspace === null) {
            return response()->json([
                'data' => [
                    'leads' => [],
                    'handoff_requests' => [],
                    'count_24h' => 0,
                ],
            ]);
        }

        // Two cursors travel together on the URL: ?since= for leads,
        // ?handoff_since= for handoff requests. Independent so a fast-
        // moving lead stream can't suppress a stale handoff event (or
        // vice versa).
        $since = (string) $request->query('since', '');
        $sinceTs = $since !== '' ? CarbonImmutable::parse($since) : null;

        $handoffSince = (string) $request->query('handoff_since', '');
        $handoffSinceTs = $handoffSince !== ''
            ? CarbonImmutable::parse($handoffSince)
            : null;

        // Leads — same shape as before.
        $leadsQuery = Lead::query()->latest();
        if ($sinceTs !== null) {
            $leadsQuery->where('created_at', '>', $sinceTs);
        }
        $leadRows = $leadsQuery
            ->limit(10)
            ->get(['id', 'email', 'name', 'phone', 'agent_id', 'created_at'])
            ->map(fn (Lead $lead) => [
                'id' => $lead->id,
                'email' => $lead->email,
                'name' => $lead->name,
                'phone' => $lead->phone,
                'agent_name' => $lead->agent?->name ?? 'agent',
                'inbox_url' => '/app/inbox/'.$lead->id,
                'created_at' => $lead->created_at?->toIso8601String(),
            ])
            ->values();

        // Handoff requests — conversations where a visitor clicked
        // "Connect me with a human" since the client's last poll AND
        // no operator has claimed yet. Bounded by 10 to keep the
        // payload tiny — pathological cases (a flood of requests
        // landing while admin is on lunch) won't blow up the JSON.
        $handoffQuery = Conversation::query()
            ->whereNotNull('human_requested_at')
            ->whereNull('claimed_by_user_id')
            ->where('is_playground', false)
            ->with('agent:id,name')
            ->latest('human_requested_at');
        if ($handoffSinceTs !== null) {
            $handoffQuery->where('human_requested_at', '>', $handoffSinceTs);
        }
        $handoffRows = $handoffQuery
            ->limit(10)
            ->get(['id', 'agent_id', 'page_url', 'human_requested_at'])
            ->map(function (Conversation $conv) {
                // First visitor message is the "what they're asking
                // about" hint that turns the toast from generic
                // "someone wants help" into actually-actionable copy.
                $firstUserMsg = Message::query()
                    ->where('conversation_id', $conv->id)
                    ->where('role', 'user')
                    ->orderBy('created_at')
                    ->value('content');

                return [
                    'id' => $conv->id,
                    'agent_name' => $conv->agent?->name ?? 'agent',
                    'page_url' => $conv->page_url,
                    'preview' => $firstUserMsg
                        ? mb_substr((string) $firstUserMsg, 0, 140)
                        : null,
                    'conversation_url' => '/app/conversations/'.$conv->id,
                    'requested_at' => $conv->human_requested_at?->toIso8601String(),
                ];
            })
            ->values();

        $count24h = (int) Lead::query()
            ->where('created_at', '>=', now()->subDay())
            ->count();

        return response()->json([
            'data' => [
                'leads' => $leadRows,
                'handoff_requests' => $handoffRows,
                'count_24h' => $count24h,
                'now' => now()->toIso8601String(),
            ],
        ]);
    }
}
