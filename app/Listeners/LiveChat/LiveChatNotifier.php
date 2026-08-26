<?php

namespace App\Listeners\LiveChat;

use App\Events\Conversations\HumanRequestedEvent;
use App\Models\Agent;
use App\Models\AuditLog;
use App\Models\Conversation;
use App\Models\Lead;
use App\Models\Workspace;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Pings external chat tools (Slack, Microsoft Teams) when a visitor
 * asks for a human. Listener fires on the queue so the visitor's
 * request-human HTTP response stays fast — webhooks aren't on the
 * critical path.
 *
 * Webhook payloads are deliberately compact:
 *   - Slack: a single `text` field with the URL inline (Slack
 *     auto-unfurls the link).
 *   - Teams: a MessageCard with a "View conversation" button.
 *
 * Both formats accept the standard incoming-webhook URL — buyers paste
 * the URL Slack/Teams gives them, no extra OAuth dance.
 */
class LiveChatNotifier implements ShouldQueue
{
    public function handle(HumanRequestedEvent $event): void
    {
        $conversation = Conversation::query()->withoutGlobalScopes()->find($event->conversationId);
        if ($conversation === null) {
            return;
        }
        $agent = Agent::query()->withoutGlobalScopes()->find($conversation->agent_id);
        if ($agent === null) {
            return;
        }
        $workspace = Workspace::query()->withoutGlobalScopes()->find($agent->workspace_id);
        if ($workspace === null) {
            return;
        }

        $slack = (string) ($workspace->slack_webhook_url ?? '');
        $teams = (string) ($workspace->teams_webhook_url ?? '');

        if ($slack === '' && $teams === '') {
            return;
        }

        $lead = Lead::query()->withoutGlobalScopes()
            ->where('conversation_id', $conversation->id)
            ->latest()
            ->first();

        $convUrl = url("/app/conversations/{$conversation->id}");
        $emailLine = $lead?->email ? "Visitor email: {$lead->email}" : 'No email captured yet.';
        $pageLine = $conversation->page_url
            ? "Page: {$conversation->page_url}"
            : 'No page URL.';
        $title = "🔔 New live-chat request — {$agent->name}";

        if ($slack !== '') {
            $this->postSlack($workspace->id, $slack, $title, $convUrl, $emailLine, $pageLine);
        }

        if ($teams !== '') {
            $this->postTeams($workspace->id, $teams, $title, $convUrl, $emailLine, $pageLine, $agent->name);
        }
    }

    /**
     * Operator-facing failure surface — write an AuditLog row so the
     * workspace admin can see broken integrations from /admin/audit.
     */
    private function recordWebhookFailure(string $workspaceId, string $integration, string $reason): void
    {
        try {
            AuditLog::create([
                'workspace_id' => $workspaceId,
                'user_id' => null,
                'action' => 'integration.webhook_failed',
                'entity_type' => 'integration',
                'entity_id' => $integration,
                'before' => [],
                'after' => ['integration' => $integration, 'reason' => mb_substr($reason, 0, 240)],
                'ip' => null,
                'ua' => null,
                'created_at' => now(),
            ]);
        } catch (\Throwable) {
            // AuditLog row failure must never re-break the notifier.
        }
    }

    private function postSlack(
        string $workspaceId,
        string $url,
        string $title,
        string $convUrl,
        string $emailLine,
        string $pageLine,
    ): void {
        $text = "{$title}\n{$emailLine}\n{$pageLine}\n<{$convUrl}|Open conversation>";

        try {
            $response = Http::timeout(5)->post($url, ['text' => $text]);
            if (! $response->successful()) {
                Log::warning('LiveChat slack webhook returned non-2xx', ['status' => $response->status()]);
                $this->recordWebhookFailure($workspaceId, 'slack', 'HTTP '.$response->status());
            }
        } catch (\Throwable $e) {
            Log::warning('LiveChat slack webhook failed', ['error' => $e->getMessage()]);
            $this->recordWebhookFailure($workspaceId, 'slack', $e->getMessage());
        }
    }

    private function postTeams(
        string $workspaceId,
        string $url,
        string $title,
        string $convUrl,
        string $emailLine,
        string $pageLine,
        string $agentName,
    ): void {
        $payload = [
            '@type' => 'MessageCard',
            '@context' => 'https://schema.org/extensions',
            'summary' => $title,
            'themeColor' => 'F59E0B',
            'title' => $title,
            'sections' => [[
                'facts' => [
                    ['name' => 'Agent', 'value' => $agentName],
                    ['name' => 'Email', 'value' => $emailLine],
                    ['name' => 'Page', 'value' => $pageLine],
                ],
            ]],
            'potentialAction' => [[
                '@type' => 'OpenUri',
                'name' => 'Open conversation',
                'targets' => [['os' => 'default', 'uri' => $convUrl]],
            ]],
        ];

        try {
            $response = Http::timeout(5)->post($url, $payload);
            if (! $response->successful()) {
                Log::warning('LiveChat teams webhook returned non-2xx', ['status' => $response->status()]);
                $this->recordWebhookFailure($workspaceId, 'teams', 'HTTP '.$response->status());
            }
        } catch (\Throwable $e) {
            Log::warning('LiveChat teams webhook failed', ['error' => $e->getMessage()]);
            $this->recordWebhookFailure($workspaceId, 'teams', $e->getMessage());
        }
    }
}
