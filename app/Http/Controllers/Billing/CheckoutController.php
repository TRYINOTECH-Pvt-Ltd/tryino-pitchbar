<?php

namespace App\Http\Controllers\Billing;

use App\Models\Plan;
use App\Services\Billing\PaymentGatewayRegistry;
use App\Services\Billing\PayPalClient;
use App\Services\Billing\PayPalProductSync;
use App\Services\Billing\RazorpayClient;
use App\Services\Billing\RazorpayProductSync;
use App\Services\Billing\StripeProductSync;
use App\Support\CurrentWorkspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Cashier\Cashier;
use Stripe\Exception\InvalidRequestException;
use Symfony\Component\HttpFoundation\RedirectResponse as SymfonyRedirectResponse;

/**
 * Kicks off a hosted checkout session for the workspace's chosen plan.
 *
 * One endpoint, three flavors: the gateway is picked by the `gateway`
 * field on the request (or defaults to whichever gateway is enabled +
 * configured first — Stripe for existing installs).
 *
 * - Stripe: redirect to Stripe Checkout, completion fires the existing
 *   `customer.subscription.*` webhook → workspace.plan_id flip.
 * - PayPal: create a Billing Subscription, redirect to PayPal's approval
 *   URL. Completion fires `BILLING.SUBSCRIPTION.ACTIVATED` to our
 *   webhook → plan_id flip.
 * - Razorpay: create a Subscription server-side, render an Inertia page
 *   that boots Razorpay Checkout.js with the subscription_id. Completion
 *   fires `subscription.activated` to our webhook → plan_id flip.
 *
 * Idempotent on the gateway plan: if the plan has no `*_plan_id` for the
 * chosen gateway yet, the gateway-specific ProductSync provisions one
 * on demand (same lazy pattern Stripe uses).
 */
class CheckoutController
{
    public function __construct(
        private readonly CurrentWorkspace $current,
        private readonly StripeProductSync $stripeSync,
        private readonly PayPalProductSync $paypalSync,
        private readonly PayPalClient $paypal,
        private readonly RazorpayProductSync $razorpaySync,
        private readonly RazorpayClient $razorpay,
        private readonly PaymentGatewayRegistry $registry,
    ) {}

    public function __invoke(Request $request): RedirectResponse|SymfonyRedirectResponse|HttpResponse|Response
    {
        $workspace = $this->current->get();
        abort_if($workspace === null, 404);
        $request->user()->can('manageBilling', $workspace) || abort(403);

        $data = $request->validate([
            'plan_slug' => ['required', 'string'],
            'gateway' => ['nullable', 'string', 'in:stripe,paypal,razorpay'],
        ]);

        $plan = Plan::query()->where('slug', $data['plan_slug'])->firstOrFail();

        if ($plan->price_cents <= 0) {
            return back()->with('error', 'This plan is not purchasable. Contact sales for Custom plans.');
        }

        $gateway = $data['gateway'] ?? $this->registry->defaultGateway();

        if (! $this->registry->isAvailable($gateway)) {
            return back()->with(
                'error',
                ucfirst($gateway).' is not configured or has been disabled. Pick another payment method.',
            );
        }

        // Cross-gateway double-charge guard. A customer with an active
        // subscription on gateway A (e.g. Stripe) who clicks checkout on
        // gateway B (e.g. PayPal) without cancelling A first would end
        // up paying both gateways every billing cycle — A keeps charging
        // until they cancel it manually inside Stripe/PayPal/Razorpay.
        // Pre-flight check: if the workspace's CURRENT gateway differs
        // from the one they're checking out with AND a subscription id
        // is still on file, refuse the new checkout and tell them what
        // to cancel.
        $activeGateway = (string) ($workspace->payment_gateway ?? '');
        if ($activeGateway !== '' && $activeGateway !== $gateway) {
            $hasActive = match ($activeGateway) {
                PaymentGatewayRegistry::STRIPE => $workspace->subscribed('default'),
                PaymentGatewayRegistry::PAYPAL => ! empty($workspace->paypal_subscription_id),
                PaymentGatewayRegistry::RAZORPAY => ! empty($workspace->razorpay_subscription_id),
                default => false,
            };

            if ($hasActive) {
                return back()->with(
                    'error',
                    'Your workspace already has an active subscription on '.ucfirst($activeGateway).
                    '. Cancel it from your '.ucfirst($activeGateway).' account before subscribing on '.
                    ucfirst($gateway).' so you aren\'t charged twice.',
                );
            }
        }

        // Lifetime plans bypass the subscription flow entirely. Today
        // only the Stripe gateway carries the lifetime one-time-charge
        // path; PayPal + Razorpay lifetime support land alongside the
        // dedicated payment-gateway round. Surface a clear error when
        // a buyer picks a non-Stripe gateway for an LTD plan.
        if ($plan->isLifetime()) {
            return $gateway === PaymentGatewayRegistry::STRIPE
                ? $this->checkoutLifetimeWithStripe($workspace, $plan)
                : back()->with(
                    'error',
                    'Lifetime plans currently check out via Stripe only. Select Stripe to continue.',
                );
        }

        return match ($gateway) {
            PaymentGatewayRegistry::STRIPE => $this->checkoutWithStripe($workspace, $plan),
            PaymentGatewayRegistry::PAYPAL => $this->checkoutWithPayPal($workspace, $plan),
            PaymentGatewayRegistry::RAZORPAY => $this->checkoutWithRazorpay($request, $workspace, $plan),
            default => back()->with('error', 'Unknown payment gateway.'),
        };
    }

    /**
     * Lifetime Deal flow — one-time Stripe Checkout in payment mode
     * (not subscription). On success the webhook handler stamps
     * `workspaces.lifetime_plan_id` so plan-feature gates honor the
     * unlock permanently.
     */
    private function checkoutLifetimeWithStripe($workspace, Plan $plan): RedirectResponse|SymfonyRedirectResponse|HttpResponse
    {
        try {
            $priceId = $this->stripeSync->ensurePriceFor($plan);
        } catch (\Throwable $e) {
            return back()->with('error', 'Could not initialize Stripe price: '.$e->getMessage());
        }

        $this->ensureUsableStripeCustomer($workspace);

        // Cashier's `checkout()` defaults to subscription mode. Use the
        // underlying Stripe SDK directly to mint a one-time Checkout
        // Session — same shape, different `mode`.
        $session = Cashier::stripe()->checkout->sessions->create([
            'mode' => 'payment',
            'customer' => $workspace->stripeId() ?: $workspace->createOrGetStripeCustomer()->id,
            'line_items' => [[
                'price' => $priceId,
                'quantity' => 1,
            ]],
            'success_url' => url('/app/billing?checkout=success&lifetime=1&session_id={CHECKOUT_SESSION_ID}'),
            'cancel_url' => url('/app/billing?checkout=cancelled'),
            'metadata' => [
                'workspace_id' => (string) $workspace->id,
                'plan_id' => (string) $plan->id,
                'plan_slug' => (string) $plan->slug,
                'billing_model' => Plan::BILLING_LIFETIME,
            ],
            'payment_intent_data' => [
                'metadata' => [
                    'workspace_id' => (string) $workspace->id,
                    'plan_id' => (string) $plan->id,
                    'billing_model' => Plan::BILLING_LIFETIME,
                ],
            ],
        ]);

        $workspace->forceFill(['payment_gateway' => PaymentGatewayRegistry::STRIPE])->save();

        return Inertia::location($session->url);
    }

    /**
     * Heal the test->live key-switch footgun before any Stripe call.
     * A workspace whose stripe_id was minted under TEST keys 500s on
     * every checkout once LIVE keys are active ("No such customer:
     * cus_…; a similar object exists in test mode"). When the saved
     * customer doesn't exist under the CURRENT key, drop the stale
     * billing identity (+ its meaningless subscription rows) so
     * Cashier mints a fresh customer for this checkout. Any other
     * Stripe error propagates untouched.
     */
    private function ensureUsableStripeCustomer($workspace): void
    {
        $stripeId = $workspace->stripeId();
        if ($stripeId === null) {
            return;
        }

        try {
            $customer = Cashier::stripe()->customers->retrieve($stripeId);
            if (! $customer->isDeleted()) {
                return;
            }
        } catch (InvalidRequestException $e) {
            if (! str_contains($e->getMessage(), 'No such customer')) {
                return;
            }
        } catch (\Throwable) {
            // Auth/network/etc — this probe is best-effort; never turn a
            // previously-working checkout into a new failure. The real
            // checkout call below will surface any genuine problem.
            return;
        }

        \Log::warning('billing.stale_stripe_customer_healed', [
            'workspace_id' => $workspace->id,
            'stale_stripe_id' => $stripeId,
        ]);

        $workspace->forceFill([
            'stripe_id' => null,
            'pm_type' => null,
            'pm_last_four' => null,
        ])->save();
        // Local rows referencing the other-mode customer's subscriptions
        // are unreachable under the current key — drop them so Cashier's
        // subscribed()/newSubscription bookkeeping starts clean.
        $workspace->subscriptions()->delete();
    }

    private function checkoutWithStripe($workspace, Plan $plan): RedirectResponse|SymfonyRedirectResponse|HttpResponse
    {
        // C2: pick the buyer's currency. Falls through to USD for the
        // legacy path so single-currency installs keep working.
        $currency = strtolower((string) (request()->query('currency')
            ?: $workspace->preferred_currency
            ?: 'usd'));

        try {
            $priceId = $currency === 'usd'
                ? $this->stripeSync->ensurePriceFor($plan)
                : $this->stripeSync->ensurePriceForCurrency($plan, $currency);
        } catch (\Throwable $e) {
            return back()->with('error', 'Could not initialize Stripe price: '.$e->getMessage());
        }

        $this->ensureUsableStripeCustomer($workspace);

        $sessionOptions = [
            'success_url' => url('/app/billing?checkout=success&session_id={CHECKOUT_SESSION_ID}'),
            'cancel_url' => url('/app/billing?checkout=cancelled'),
            'metadata' => [
                'workspace_id' => $workspace->id,
                'plan_id' => $plan->id,
                'plan_slug' => $plan->slug,
                'currency' => $currency,
            ],
        ];

        // Stripe Tax: Cashier::calculateTaxes() puts automatic_tax on the
        // session, but for an EXISTING Stripe customer without a saved
        // address Stripe then rejects the session ("requires a valid
        // address on the Customer") unless we also let Checkout save the
        // collected billing address back onto the customer. Confirmed in
        // production the first time an operator flipped CASHIER_AUTO_TAX.
        if ((bool) config('services.stripe_tax.enabled', false)) {
            $sessionOptions['customer_update'] = ['address' => 'auto', 'name' => 'auto'];
        }

        $checkout = $workspace
            ->newSubscription('default', $priceId)
            ->checkout($sessionOptions);

        // Remember the buyer's chosen currency on the workspace so the
        // billing page + future invoices speak the same currency.
        $workspace->forceFill([
            'payment_gateway' => PaymentGatewayRegistry::STRIPE,
            'preferred_currency' => $currency,
        ])->save();

        // Inertia v3 + external redirect: returning a plain
        // `redirect($externalUrl)` gets auto-converted by the
        // Inertia middleware to 409 + `X-Inertia-Location` header.
        // Some bundled Inertia clients then XHR the external URL
        // first (CORS fails on Stripe's domain) before falling back
        // to window.location — the visitor sees a CORS error in the
        // console and the navigation occasionally drops. `Inertia::location()`
        // is the explicit external-redirect contract: the client
        // performs window.location.href = url with no intermediate
        // XHR. Same fix applied to the PayPal flow below.
        return Inertia::location($checkout->url);
    }

    private function checkoutWithPayPal($workspace, Plan $plan): RedirectResponse|SymfonyRedirectResponse|HttpResponse
    {
        try {
            $paypalPlanId = $this->paypalSync->ensurePlanFor($plan);
        } catch (\Throwable $e) {
            return back()->with('error', 'Could not initialize PayPal plan: '.$e->getMessage());
        }

        // Orphan cleanup: if the workspace already carries a
        // paypal_subscription_id from a previous checkout attempt
        // (customer abandoned PayPal mid-flow, OR a prior subscription
        // was cancelled but the column was never cleared), cancel
        // it now BEFORE creating a new one. Skips silently on PayPal
        // errors — the worst case is one orphaned subscription on
        // PayPal's side, which is preferable to refusing a legit
        // checkout because we couldn't reach PayPal.
        $existingSub = (string) ($workspace->paypal_subscription_id ?? '');
        if ($existingSub !== '') {
            try {
                $this->paypal->cancelSubscription($existingSub, 'Replaced by new Pitchbar checkout.');
            } catch (\Throwable $e) {
                // best-effort; log only.
                \Log::warning('paypal.orphan_cancel_failed', [
                    'workspace_id' => (string) $workspace->id,
                    'subscription_id' => $existingSub,
                    'error' => $e->getMessage(),
                ]);
            }
            $workspace->forceFill(['paypal_subscription_id' => null])->save();
        }

        try {
            $subscription = $this->paypal->createSubscription([
                'plan_id' => $paypalPlanId,
                'custom_id' => (string) $workspace->id,
                'application_context' => [
                    'brand_name' => (string) config('app.name', 'Pitchbar'),
                    'user_action' => 'SUBSCRIBE_NOW',
                    'return_url' => url('/app/billing?checkout=success&gateway=paypal'),
                    'cancel_url' => url('/app/billing?checkout=cancelled&gateway=paypal'),
                ],
            ]);
        } catch (\Throwable $e) {
            return back()->with('error', 'PayPal could not create the subscription: '.$e->getMessage());
        }

        $approvalUrl = $this->approvalUrlFromPayPal($subscription);

        if ($approvalUrl === null) {
            return back()->with('error', 'PayPal did not return an approval URL.');
        }

        $workspace->forceFill([
            'payment_gateway' => PaymentGatewayRegistry::PAYPAL,
            'paypal_subscription_id' => (string) ($subscription['id'] ?? ''),
        ])->save();

        return Inertia::location($approvalUrl);
    }

    private function checkoutWithRazorpay(Request $request, $workspace, Plan $plan): Response|RedirectResponse
    {
        try {
            $razorpayPlanId = $this->razorpaySync->ensurePlanFor($plan);
        } catch (\Throwable $e) {
            return back()->with('error', 'Could not initialize Razorpay plan: '.$e->getMessage());
        }

        try {
            $subscription = $this->razorpay->createSubscription([
                'plan_id' => $razorpayPlanId,
                'customer_notify' => 1,
                'quantity' => 1,
                'total_count' => 120,
                'notes' => [
                    'workspace_id' => (string) $workspace->id,
                    'plan_id' => (string) $plan->id,
                    'plan_slug' => $plan->slug,
                ],
            ]);
        } catch (\Throwable $e) {
            return back()->with('error', 'Razorpay could not create the subscription: '.$e->getMessage());
        }

        $subscriptionId = (string) ($subscription['id'] ?? '');

        if ($subscriptionId === '') {
            return back()->with('error', 'Razorpay did not return a subscription id.');
        }

        $workspace->forceFill([
            'payment_gateway' => PaymentGatewayRegistry::RAZORPAY,
            'razorpay_subscription_id' => $subscriptionId,
        ])->save();

        // Razorpay's Checkout.js expects to be opened from the customer's
        // browser with a public key + subscription id. We render a thin
        // launcher page that boots the script and forwards success/cancel
        // back to /app/billing.
        return Inertia::render('app/razorpay-checkout', [
            'razorpay_key_id' => $this->razorpay->publicKey(),
            'subscription_id' => $subscriptionId,
            'plan' => [
                'name' => $plan->name,
                'price_cents' => (int) $plan->price_cents,
                'currency' => strtoupper((string) config('cashier.currency', 'usd')),
            ],
            'customer' => [
                'name' => (string) ($request->user()?->name ?? ''),
                'email' => (string) ($request->user()?->email ?? ''),
            ],
            'success_url' => url('/app/billing?checkout=success&gateway=razorpay'),
            'cancel_url' => url('/app/billing?checkout=cancelled&gateway=razorpay'),
        ]);
    }

    /**
     * @param  array<string, mixed>  $subscription
     */
    private function approvalUrlFromPayPal(array $subscription): ?string
    {
        /** @var array<int, array<string, string>> $links */
        $links = $subscription['links'] ?? [];

        foreach ($links as $link) {
            if (($link['rel'] ?? '') === 'approve' && isset($link['href'])) {
                return (string) $link['href'];
            }
        }

        return null;
    }
}
