<?php

namespace App\Http\Controllers\Admin;

use App\Models\Plan;
use App\Services\Billing\BillingHistory;
use App\Services\Billing\MeteredBilling;
use App\Services\Billing\PaymentGatewayRegistry;
use App\Services\Billing\SubscriptionReconciler;
use App\Support\AppBranding;
use App\Support\CurrentWorkspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\RedirectResponse as SymfonyRedirectResponse;

/**
 * Customer-facing billing page — current plan, monthly conversation usage,
 * plan ladder, gateway picker, and (once subscribed) a manage-subscription
 * link routed to whichever gateway owns the active subscription.
 */
class BillingController
{
    public function __construct(
        private readonly CurrentWorkspace $current,
        private readonly MeteredBilling $billing,
        private readonly PaymentGatewayRegistry $registry,
        private readonly SubscriptionReconciler $reconciler,
        private readonly BillingHistory $history,
    ) {}

    public function show(Request $request): Response|RedirectResponse|SymfonyRedirectResponse
    {
        // Customer-only surface. Platform operators manage subscriptions
        // across all workspaces from /admin/subscriptions instead — they
        // shouldn't be funneled into a "subscribe yourself" flow here.
        if ($request->user()?->isSuperAdmin() === true) {
            return redirect('/admin/subscriptions');
        }

        $workspace = $this->current->get();
        abort_if($workspace === null, 404);
        $request->user()->can('manageBilling', $workspace) || abort(403);

        // Webhook safety net. The buyer arrives here after Stripe Checkout
        // (or PayPal approval, or Razorpay Checkout.js) with one of:
        //   ?checkout=success&session_id={CHECKOUT_SESSION_ID}    (Stripe)
        //   ?checkout=success&gateway=paypal                      (PayPal)
        //   ?checkout=success&gateway=razorpay                    (Razorpay)
        // The reconciler pulls live subscription state directly from the
        // gateway and flips workspace.plan_id, so the page renders the
        // correct plan even if the async webhook hasn't fired yet (or
        // never fires because the customer's gateway webhook URL isn't
        // configured). Idempotent — safe to call on every page load,
        // not just the success redirect.
        if ($request->query('checkout') === 'success') {
            $this->reconciler->reconcile(
                $workspace,
                (string) $request->query('session_id', '') !== ''
                    ? (string) $request->query('session_id')
                    : null,
            );
            $workspace = $workspace->fresh() ?? $workspace;
        }

        $summary = $this->billing->summaryFor($workspace);

        $availableGateways = $this->registry->enabledGateways();
        $anyConfigured = $availableGateways !== [];

        $plans = Plan::query()
            ->where('is_active', true)
            ->orderBy('price_cents')
            ->get()
            ->map(fn (Plan $p) => [
                'id' => $p->id,
                'name' => $p->name,
                'slug' => $p->slug,
                'monthly_conversations' => (int) $p->monthly_conversations,
                'monthly_messages' => $p->monthly_messages !== null ? (int) $p->monthly_messages : null,
                'price_cents' => (int) $p->price_cents,
                'yearly_price_cents' => $p->yearly_price_cents !== null ? (int) $p->yearly_price_cents : null,
                'agents_limit' => $p->agents_limit,
                'sources_limit' => $p->sources_limit,
                'workflows_limit' => $p->workflows_limit,
                'integrations_limit' => $p->integrations_limit,
                'members_limit' => $p->members_limit,
                'workspaces_limit' => $p->workspaces_limit,
                'api_access' => (bool) ($p->api_access ?? true),
                'remove_branding' => (bool) ($p->features['remove_branding'] ?? false),
                'features' => $this->billingFeaturesForDisplay($p),
                'is_purchasable' => $anyConfigured && $p->price_cents > 0,
            ])
            ->values();

        // Stripe-specific subscription check still uses Cashier's Billable
        // trait. PayPal / Razorpay subscriptions are tracked via dedicated
        // workspace columns and surface as `has_active_subscription` once
        // the post-checkout webhook flips `payment_gateway`.
        $hasStripeSub = $workspace->subscribed('default');
        $hasOtherSub = in_array(
            $workspace->payment_gateway,
            [PaymentGatewayRegistry::PAYPAL, PaymentGatewayRegistry::RAZORPAY],
            true,
        );

        // The Stripe "scheduled to cancel" flag is what gates the Resume
        // button. Cashier marks the subscription onGracePeriod() once
        // `cancel_at_period_end=true` is observed; we expose that to the
        // UI so the customer can undo a cancel before the period ends.
        $cancelAtPeriodEnd = false;
        if ($hasStripeSub) {
            $stripeSub = $workspace->subscription('default');
            if ($stripeSub !== null) {
                $cancelAtPeriodEnd = (bool) $stripeSub->onGracePeriod();
            }
        }

        return Inertia::render('app/billing', [
            'plans' => $plans,
            'summary' => $summary,
            // Backwards-compat key for the existing UI/tests; resolves true
            // whenever ANY gateway is enabled + configured (not strictly Stripe).
            'stripe_configured' => $this->registry->isAvailable(PaymentGatewayRegistry::STRIPE),
            'available_gateways' => $availableGateways,
            'has_active_subscription' => $hasStripeSub || $hasOtherSub,
            'active_gateway' => $workspace->payment_gateway,
            'subscription_cancel_at_period_end' => $cancelAtPeriodEnd,
            'flash_checkout' => [
                'status' => $request->query('checkout'),
                'gateway' => $request->query('gateway'),
            ],
            // C7: native billing history. Each entry carries enough to
            // render in the React tab; the download URL for Stripe
            // invoices goes via the dedicated download endpoint so we
            // can stamp white-label branding onto the PDF.
            'invoices' => $this->history->invoicesFor($workspace),
            'charges' => $this->history->chargesFor($workspace),
            'lifetime' => $workspace->hasLifetimeAccess() ? [
                'plan_name' => $workspace->lifetimePlan?->name,
                'purchased_at' => $workspace->lifetime_purchased_at?->toIso8601String(),
            ] : null,
        ]);
    }

    /**
     * Stream a Stripe invoice PDF to the operator's browser. White-label
     * branding cascade applies — vendor + product name come from
     * config('branding.*') rather than the Pitchbar default.
     */
    public function downloadInvoice(Request $request, string $invoiceId): \Symfony\Component\HttpFoundation\Response
    {
        $workspace = $this->current->get();
        abort_if($workspace === null, 404);
        $request->user()->can('manageBilling', $workspace) || abort(403);

        $vendor = (string) config('branding.site_title', config('app.name', 'Pitchbar'));
        $product = 'Subscription';

        return $workspace->downloadInvoice($invoiceId, [
            'vendor' => $vendor,
            'product' => $product,
        ]);
    }

    /**
     * Manage-subscription link for the currently active gateway.
     *
     * Stripe: redirect to its hosted billing portal (Cashier's helper).
     * PayPal: deep-link to the customer's PayPal subscription detail page.
     * Razorpay: no public hosted portal — fall back to a friendly message
     * pointing the admin at our internal billing page.
     */
    public function portal(Request $request): RedirectResponse|SymfonyRedirectResponse
    {
        $workspace = $this->current->get();
        abort_if($workspace === null, 404);
        $request->user()->can('manageBilling', $workspace) || abort(403);

        $gateway = (string) $workspace->payment_gateway;

        if ($gateway === PaymentGatewayRegistry::PAYPAL) {
            $subId = (string) $workspace->paypal_subscription_id;
            if ($subId === '') {
                return redirect()->route('billing.show')
                    ->with('error', 'No PayPal subscription found yet — start one first.');
            }

            $base = (string) config('services.paypal.mode', 'sandbox') === 'live'
                ? 'https://www.paypal.com'
                : 'https://www.sandbox.paypal.com';

            return redirect()->away("{$base}/myaccount/autopay/connect/{$subId}");
        }

        if ($gateway === PaymentGatewayRegistry::RAZORPAY) {
            return redirect()->route('billing.show')
                ->with('error', 'Razorpay manages subscriptions inside the Pitchbar dashboard. Contact support to cancel or change plans.');
        }

        // Default → Stripe (Cashier portal)
        if (! $workspace->hasStripeId()) {
            return redirect()->route('billing.show')
                ->with('error', 'No Stripe customer yet — start a subscription first.');
        }

        return $workspace->redirectToBillingPortal(url('/app/billing'));
    }

    /**
     * Normalize mixed plan feature storage into a simple list for the
     * customer-facing billing page.
     *
     * @return list<string>
     */
    private function billingFeaturesForDisplay(Plan $plan): array
    {
        $features = $plan->features;

        if (! is_array($features)) {
            return [];
        }

        if (array_is_list($features)) {
            return array_values(array_filter(
                $features,
                static fn (mixed $feature): bool => is_string($feature) && $feature !== '',
            ));
        }

        $displayFeatures = [];

        if (($features['remove_branding'] ?? false) === true) {
            $displayFeatures[] = 'Remove '.AppBranding::siteTitle().' branding';
        }

        return $displayFeatures;
    }
}
