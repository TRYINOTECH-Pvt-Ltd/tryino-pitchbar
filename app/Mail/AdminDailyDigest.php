<?php

namespace App\Mail;

use App\Support\AppBranding;
use App\Support\MailHeader;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Daily summary of business events for super_admins. Opt-in via the
 * `admin_daily_digest_enabled` flag on app_settings.
 *
 * @phpstan-type DigestStats array{
 *   period_start: string,
 *   period_end: string,
 *   new_users: int,
 *   new_workspaces: int,
 *   new_subscriptions: int,
 *   new_leads: int,
 *   active_conversations: int,
 *   admin_url: string,
 * }
 */
class AdminDailyDigest extends Mailable
{
    use Queueable, SerializesModels;

    /** @param DigestStats $stats */
    public function __construct(public array $stats) {}

    public function envelope(): Envelope
    {
        $brand = AppBranding::siteTitle();

        return new Envelope(
            subject: MailHeader::subject(
                __('[:brand] Daily admin digest — :date', [
                    'brand' => $brand,
                    'date' => $this->stats['period_end'],
                ])
            ),
        );
    }

    public function content(): Content
    {
        // markdown: registers the `mail::` view namespace so the
        // @component('mail::message') / @component('mail::button')
        // blocks resolve. Pre-fix the bare `view:` path produced
        // "View [message] not found" on installs where the mail view
        // was published verbatim. (Same root cause as
        // WorkspaceInvitation, fixed 2026-05-18.)
        return new Content(
            markdown: 'mail.admin-daily-digest',
            with: ['stats' => $this->stats],
        );
    }
}
