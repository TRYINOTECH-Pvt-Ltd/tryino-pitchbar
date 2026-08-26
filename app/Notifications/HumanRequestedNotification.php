<?php

namespace App\Notifications;

use App\Http\Controllers\Widget\RequestHumanController;
use App\Models\Agent;
use App\Models\Conversation;
use App\Models\Lead;
use App\Models\Workspace;
use App\Support\AppBranding;
use App\Support\MailHeader;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent to every operator with `live_chat_available = true` when a
 * visitor clicks "Talk to a human" on the widget. Channels:
 *
 *  - `database` so the bell icon in the dashboard increments + we keep
 *    a per-user audit trail of every visitor request the operator
 *    could have picked up.
 *  - `mail` so an operator who isn't watching the inbox tab gets a
 *    real email (they explicitly opted in to live-chat duty via
 *    Profile → Notifications → "Available for live chat").
 *
 * Idempotency on the visitor side lives in the RequestHumanController
 * (no second notify while `human_requested_at` is non-null and the
 * conversation is unclaimed) — this notification itself doesn't
 * dedupe; the controller does.
 */
class HumanRequestedNotification extends Notification
{
    use Queueable;

    /** @var array{conversation: ?Conversation, agent: ?Agent, workspace: ?Workspace, lead: ?Lead}|null */
    private ?array $context = null;

    public function __construct(
        public string $conversationId,
        public string $workspaceId,
    ) {}

    /**
     * @return array{conversation: ?Conversation, agent: ?Agent, workspace: ?Workspace, lead: ?Lead}
     */
    private function context(): array
    {
        if ($this->context !== null) {
            return $this->context;
        }

        $conversation = Conversation::query()
            ->withoutGlobalScopes()
            ->find($this->conversationId);
        $agent = $conversation
            ? Agent::query()->withoutGlobalScopes()->find($conversation->agent_id)
            : null;
        $workspace = Workspace::query()
            ->withoutGlobalScopes()
            ->find($this->workspaceId);
        $lead = $conversation
            ? Lead::query()->withoutGlobalScopes()
                ->where('conversation_id', $conversation->id)
                ->latest()
                ->first()
            : null;

        return $this->context = compact('conversation', 'agent', 'workspace', 'lead');
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        ['conversation' => $conversation, 'agent' => $agent, 'workspace' => $workspace, 'lead' => $lead] = $this->context();

        $agentName = $agent?->name ?? 'an agent';
        $workspaceName = $workspace?->name ?? 'your workspace';
        $pageUrl = $conversation?->page_url ?: 'unknown page';
        $email = $lead?->email ?: 'not captured yet';
        $inboxUrl = \Route::has('conversations.show')
            ? route('conversations.show', ['conversation' => $this->conversationId])
            : url('/app/conversations/'.$this->conversationId);

        return (new MailMessage)
            ->subject(MailHeader::subject("Live chat request — {$agentName}"))
            ->greeting("A visitor wants to chat with a human on {$workspaceName}.")
            ->line("Agent: {$agentName}")
            ->line("Page: {$pageUrl}")
            ->line("Visitor email: {$email}")
            ->action('Open conversation', $inboxUrl)
            ->line(sprintf(
                'They will see "no one is available" if no one joins within %d seconds, so jump in fast.',
                RequestHumanController::WAIT_TIMEOUT_SECONDS,
            ))
            ->salutation('— '.AppBranding::siteTitle());
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        ['conversation' => $conversation, 'lead' => $lead] = $this->context();

        return [
            'kind' => 'human_requested',
            'conversation_id' => $this->conversationId,
            'workspace_id' => $this->workspaceId,
            'agent_id' => $conversation?->agent_id,
            'page_url' => $conversation?->page_url,
            'visitor_email' => $lead?->email,
            'url' => '/app/conversations/'.$this->conversationId,
            'requested_at' => now()->toIso8601String(),
        ];
    }
}
