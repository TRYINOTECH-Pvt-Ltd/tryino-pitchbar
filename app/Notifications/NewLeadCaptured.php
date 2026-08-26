<?php

namespace App\Notifications;

use App\Models\Lead;
use App\Support\AppBranding;
use App\Support\MailHeader;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent to every owner/admin of the lead's workspace right after Lead capture.
 * Triggered from RouteLeadJob (queued), so the visitor's HTTP request never
 * waits on SMTP.
 *
 * Uses a custom Blade template (resources/views/emails/leads/captured.blade.php)
 * so the email actually looks like a Pitchbar email rather than the stock
 * Laravel one.
 */
class NewLeadCaptured extends Notification
{
    use Queueable;

    public function __construct(public Lead $lead) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $lead = $this->lead;
        $agent = $lead->conversation?->agent;
        $workspace = $agent?->workspace;
        $agentName = $agent?->name ?? 'your agent';
        $workspaceName = $workspace?->name ?? 'your workspace';
        $inboxUrl = \Route::has('inbox.show')
            ? route('inbox.show', ['lead' => $lead->id])
            : url('/app/inbox/'.$lead->id);

        $rows = [
            'Email' => $lead->email,
            'Name' => $lead->name,
            'Phone' => $lead->phone,
            'Captured' => $lead->created_at?->toDayDateTimeString(),
        ];

        return (new MailMessage)
            ->subject(MailHeader::subject('New lead — '.($lead->email ?? 'unknown')))
            ->view('emails.leads.captured', [
                'lead' => $lead,
                'agentName' => $agentName,
                'workspaceName' => $workspaceName,
                'inboxUrl' => $inboxUrl,
                'rows' => $rows,
                'brandName' => AppBranding::siteTitle(),
            ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'lead_id' => $this->lead->id,
            'email' => $this->lead->email,
        ];
    }
}
