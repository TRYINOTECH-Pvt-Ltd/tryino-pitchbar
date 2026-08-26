<?php

namespace App\Services\Billing;

use App\Models\Workspace;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Read-only facade over each payment gateway's history endpoint. Used
 * by the customer-facing /app/billing page so a buyer can see their
 * invoices + one-time charges without bouncing to Stripe's portal.
 *
 * Each method is defensive: gateway API errors degrade to an empty
 * list rather than throwing all the way up to the controller — the
 * billing page is critical surface and must always render.
 */
final class BillingHistory
{
    /**
     * @return array<int, array{id: string, number: ?string, total_cents: int, currency: string, status: string, hosted_invoice_url: ?string, created_at: ?string, source: string}>
     */
    public function invoicesFor(Workspace $workspace): array
    {
        $invoices = [];

        // Stripe: Cashier ships `->invoices()` returning Cashier\Invoice
        // wrappers. Available on every Stripe workspace that ever
        // checked out.
        if ($workspace->hasStripeId()) {
            try {
                foreach ($workspace->invoices() as $invoice) {
                    $invoices[] = [
                        'id' => (string) $invoice->id,
                        'number' => $invoice->number ?? null,
                        'total_cents' => (int) $invoice->total,
                        'currency' => strtolower((string) ($invoice->currency ?? 'usd')),
                        'status' => (string) ($invoice->status ?? 'unknown'),
                        'hosted_invoice_url' => $invoice->hosted_invoice_url ?? null,
                        'created_at' => $invoice->date()?->toIso8601String(),
                        'source' => 'stripe',
                    ];
                }
            } catch (Throwable $e) {
                Log::warning('billing.history.stripe_failed', [
                    'workspace_id' => (string) $workspace->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // PayPal / Razorpay invoice lists land in v1.3.1 alongside the
        // dedicated payment-gateway round. Stripe carries the bulk of
        // installs today.

        return $invoices;
    }

    /**
     * Lifetime / one-time charges. Today only Stripe surfaces these
     * separately from invoices (in payment-intent mode); future
     * gateways will plug in the same shape.
     *
     * @return array<int, array{id: string, total_cents: int, currency: string, description: string, created_at: ?string, source: string}>
     */
    public function chargesFor(Workspace $workspace): array
    {
        $charges = [];

        if ($workspace->hasLifetimeAccess()) {
            $plan = $workspace->lifetimePlan;
            $charges[] = [
                'id' => 'lifetime-'.$workspace->id,
                'total_cents' => (int) ($plan?->price_cents ?? 0),
                'currency' => strtolower((string) ($workspace->preferred_currency ?? 'usd')),
                'description' => sprintf(
                    '%s — Lifetime access',
                    $plan?->name ?? 'Lifetime plan',
                ),
                'created_at' => $workspace->lifetime_purchased_at?->toIso8601String(),
                'source' => 'lifetime',
            ];
        }

        return $charges;
    }
}
