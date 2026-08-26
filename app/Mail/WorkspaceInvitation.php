<?php

namespace App\Mail;

use App\Models\Invitation;
use App\Support\MailHeader;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class WorkspaceInvitation extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Invitation $invitation) {}

    public function envelope(): Envelope
    {
        $workspaceName = $this->invitation->workspace->name ?? 'a workspace';

        return new Envelope(
            subject: MailHeader::subject(
                __('You have been invited to :workspace', ['workspace' => $workspaceName])
            ),
        );
    }

    public function content(): Content
    {
        // markdown: (not view:) so Laravel registers the `mail::` view
        // namespace before rendering — without that the <x-mail::message>
        // component inside the template throws "View [message] not found".
        // (Buyer-reported 2026-05-18, Lucian on cPanel install.)
        return new Content(
            markdown: 'mail.workspace-invitation',
            with: [
                'invitation' => $this->invitation,
                'acceptUrl' => url('/invitations/'.$this->invitation->token),
            ],
        );
    }
}
