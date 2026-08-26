<?php

namespace App\Mail;

use App\Models\Workspace;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * "Your plan was changed" confirmation, sent to the workspace owner after
 * an in-app plan swap. Stripe sends nothing on a plain Cashier swap() — a
 * downgrade is a proration credit (no payment, no finalized invoice) and
 * Stripe has no plan-change email — so this is the only confirmation the
 * customer receives. Plain HTML view, matching the add-on billing emails.
 *
 * `ShouldQueue`: dispatched from the web swap request, so it must not block
 * the response on SMTP. The caller wraps the send in try/catch — a mail
 * failure can never undo a swap that already succeeded on Stripe.
 */
class PlanChangedMail extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly Workspace $workspace,
        public readonly string $previousPlanName,
        public readonly string $newPlanName,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Your plan was changed to {$this->newPlanName}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.billing.plan-changed',
            with: [
                'workspace' => $this->workspace,
                'previousPlanName' => $this->previousPlanName,
                'newPlanName' => $this->newPlanName,
                'billingUrl' => route('billing.show'),
                'brand' => (string) config('app.name', 'Pitchbar'),
            ],
        );
    }
}
