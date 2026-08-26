<?php

namespace App\Http\Controllers\Admin\Platform;

use App\Models\Plan;
use App\Services\Billing\PaymentGatewayRegistry;
use App\Services\Billing\PayPalProductSync;
use App\Services\Billing\RazorpayProductSync;
use App\Services\Billing\StripeProductSync;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Platform-admin CRUD for subscription plans. Every save runs a
 * provisioning sync against EVERY enabled-and-configured payment gateway
 * (Stripe + PayPal + Razorpay) so admins never touch the gateway
 * dashboards to provision Products / Prices / Plans by hand.
 *
 * Free / Custom plans (price_cents=0) are local-only and skip every
 * gateway sync — paid plans get a Product + Price reflected on each
 * gateway the workspace lets visitors pay through.
 *
 * Delete is soft (is_active=false + archive on every gateway), never
 * destructive, because workspaces.plan_id is a real FK and
 * revenue-bearing subscriptions still need their plan row resolvable
 * for invoices.
 */
class PlanController
{
    public function __construct(
        private readonly StripeProductSync $stripe,
        private readonly PayPalProductSync $paypal,
        private readonly RazorpayProductSync $razorpay,
        private readonly PaymentGatewayRegistry $gateways,
    ) {}

    public function index(Request $request): Response
    {
        $rows = Plan::query()
            ->withCount('workspaces')
            ->orderBy('price_cents')
            ->orderBy('name')
            ->get()
            ->map(fn (Plan $plan) => $this->serialize($plan));

        return Inertia::render('admin/plans/index', [
            'plans' => $rows,
            'currency' => strtolower((string) config('cashier.currency', 'usd')),
            'gateways' => $this->gatewayStatuses(),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('admin/plans/create', [
            'currency' => strtolower((string) config('cashier.currency', 'usd')),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validatedFor($request);
        $data['slug'] = $this->ensureUniqueSlug($data['name']);
        $data = $this->normalisePrices($data);

        $plan = Plan::create($data);

        $this->trySyncAll($plan);

        return redirect()
            ->route('admin.plans.index')
            ->with('success', "Plan '{$plan->name}' created.");
    }

    public function edit(Plan $plan): Response
    {
        return Inertia::render('admin/plans/edit', [
            'plan' => $this->serialize($plan),
            'currency' => strtolower((string) config('cashier.currency', 'usd')),
        ]);
    }

    public function update(Request $request, Plan $plan): RedirectResponse
    {
        $data = $this->validatedFor($request, $plan);
        $data = $this->normalisePrices($data);

        // Single-default invariant: setting this plan as the signup
        // default demotes every other row. Without this, a second
        // toggle-on would leave two defaults and CreateWorkspaceForUser
        // would pick the lowest-id arbitrarily — bad UX surprise for
        // the operator.
        if (! empty($data['is_default_for_signup'])) {
            Plan::query()
                ->where('id', '!=', $plan->id)
                ->where('is_default_for_signup', true)
                ->update(['is_default_for_signup' => false]);
        }

        $plan->update($data);

        $this->trySyncAll($plan->fresh() ?? $plan);

        return redirect()
            ->route('admin.plans.index')
            ->with('success', "Plan '{$plan->name}' updated.");
    }

    /**
     * Ensure the `prices` JSON map carries USD = price_cents and
     * normalises every key to lowercase. Operator submits arbitrary
     * casing — DB stores canonical lowercase ISO 4217 codes.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalisePrices(array $data): array
    {
        $prices = $data['prices'] ?? [];
        $normalised = [];
        foreach ((array) $prices as $code => $amount) {
            $key = strtolower((string) $code);
            $value = (int) $amount;
            if ($value > 0) {
                $normalised[$key] = $value;
            }
        }
        // USD mirrors the canonical price_cents value so the legacy
        // single-Price readers keep finding it.
        if (isset($data['price_cents']) && (int) $data['price_cents'] > 0) {
            $normalised['usd'] = (int) $data['price_cents'];
        }
        $data['prices'] = $normalised;

        return $data;
    }

    /**
     * Manually re-run the gateway sync for one plan + one gateway.
     * Wired to the per-row gateway sync buttons on the index page.
     * Returns JSON so the UI can render the result inline + show the
     * freshly-stored gateway IDs without a full page reload.
     */
    public function sync(Plan $plan, string $gateway = PaymentGatewayRegistry::STRIPE): JsonResponse
    {
        if (! in_array($gateway, [PaymentGatewayRegistry::STRIPE, PaymentGatewayRegistry::PAYPAL, PaymentGatewayRegistry::RAZORPAY], true)) {
            return response()->json([
                'ok' => false,
                'message' => "Unknown gateway '{$gateway}'.",
            ], 422);
        }

        // Free / custom plans don't need any gateway-side entity. Use
        // gateway-specific message wording so existing tests + UX text
        // stay readable. Backwards-compat top-level Stripe IDs preserved
        // for the legacy /sync endpoint that doesn't carry a gateway
        // path segment.
        if ($plan->price_cents <= 0) {
            $label = match ($gateway) {
                PaymentGatewayRegistry::STRIPE => 'no Stripe sync needed',
                PaymentGatewayRegistry::PAYPAL => 'no PayPal sync needed',
                PaymentGatewayRegistry::RAZORPAY => 'no Razorpay sync needed',
            };

            return response()->json($this->syncResponse(
                ok: true,
                message: "Free / custom plans are local-only — {$label}.",
                plan: $plan,
            ));
        }

        if (! $this->gateways->isEnabled($gateway)) {
            return response()->json($this->syncResponse(
                ok: false,
                message: ucfirst($gateway).' is disabled in Settings → System → Billing.',
                plan: $plan,
            ));
        }

        if (! $this->gateways->isConfigured($gateway)) {
            return response()->json($this->syncResponse(
                ok: false,
                message: ucfirst($gateway).' is enabled but missing credentials. Check Settings → System.',
                plan: $plan,
            ));
        }

        try {
            match ($gateway) {
                PaymentGatewayRegistry::STRIPE => $this->stripe->syncPlan($plan),
                PaymentGatewayRegistry::PAYPAL => $this->paypal->syncPlan($plan),
                PaymentGatewayRegistry::RAZORPAY => $this->razorpay->syncPlan($plan),
            };

            return response()->json($this->syncResponse(
                ok: true,
                message: 'Synced — '.ucfirst($gateway).' Product / Plan up to date.',
                plan: $plan->fresh() ?? $plan,
            ));
        } catch (\Throwable $e) {
            return response()->json($this->syncResponse(
                ok: false,
                message: $e->getMessage(),
                plan: $plan,
            ), 200);
        }
    }

    /**
     * Shape JSON for the /sync endpoint with backwards-compatible
     * top-level Stripe IDs (the legacy contract older clients +
     * existing tests rely on) plus the full multi-gateway snapshot
     * under `plan` for the new index page.
     *
     * @return array<string, mixed>
     */
    private function syncResponse(bool $ok, string $message, Plan $plan): array
    {
        return [
            'ok' => $ok,
            'message' => $message,
            'stripe_product_id' => $plan->stripe_product_id,
            'stripe_price_id' => $plan->stripe_price_id,
            'plan' => $this->serialize($plan),
        ];
    }

    public function destroy(Plan $plan): RedirectResponse
    {
        // Soft-delete: never break the foreign keys workspaces.plan_id
        // depends on. The plan row stays for invoice / audit lookups;
        // only is_active flips and every enabled gateway gets archived.
        $plan->forceFill(['is_active' => false])->save();

        $errors = [];
        foreach ($this->gateways->enabledGateways() as $gateway) {
            try {
                match ($gateway) {
                    PaymentGatewayRegistry::STRIPE => $this->stripe->archivePlan($plan),
                    PaymentGatewayRegistry::PAYPAL => $this->paypal->archivePlan($plan),
                    PaymentGatewayRegistry::RAZORPAY => $this->razorpay->archivePlan($plan),
                };
            } catch (\Throwable $e) {
                $errors[] = ucfirst($gateway).': '.$e->getMessage();
            }
        }

        if ($errors !== []) {
            return redirect()
                ->route('admin.plans.index')
                ->with('error', 'Plan deactivated locally but archive failed: '.implode(' | ', $errors));
        }

        return redirect()
            ->route('admin.plans.index')
            ->with('success', "Plan '{$plan->name}' deactivated.");
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedFor(Request $request, ?Plan $plan = null): array
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'monthly_conversations' => ['required', 'integer', 'min:0', 'max:1000000'],
            'monthly_messages' => ['nullable', 'integer', 'min:0', 'max:10000000'],
            'max_tokens_per_response' => ['nullable', 'integer', 'min:100', 'max:8000'],
            // Per-resource caps. NULL = unlimited (the back-compat default
            // for every pre-existing plan). 0 = hard block (e.g. Free
            // tier with no integrations). Positive = absolute cap.
            'agents_limit' => ['nullable', 'integer', 'min:0', 'max:10000'],
            'sources_limit' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'workflows_limit' => ['nullable', 'integer', 'min:0', 'max:10000'],
            'integrations_limit' => ['nullable', 'integer', 'min:0', 'max:1000'],
            'members_limit' => ['nullable', 'integer', 'min:0', 'max:10000'],
            'workspaces_limit' => ['nullable', 'integer', 'min:0', 'max:10000'],
            'api_access' => ['sometimes', 'boolean'],
            'price_cents' => ['required', 'integer', 'min:0', 'max:99999900'],
            'yearly_price_cents' => ['nullable', 'integer', 'min:0', 'max:99999900'],
            'interval' => ['sometimes', 'string', Rule::in(['month', 'year'])],
            'is_active' => ['sometimes', 'boolean'],
            'show_on_pricing_page' => ['sometimes', 'boolean'],
            'is_default_for_signup' => ['sometimes', 'boolean'],
            // Trial plans: a no-card, time-limited window started at
            // sign-up. `trial_days` is only meaningful when is_trial is on.
            'is_trial' => ['sometimes', 'boolean'],
            'trial_days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'features' => ['nullable', 'array'],
            'features.remove_branding' => ['sometimes', 'boolean'],
            // C6: billing model selector. Defaults to subscription.
            'billing_model' => ['sometimes', 'string', Rule::in([Plan::BILLING_SUBSCRIPTION, Plan::BILLING_LIFETIME, Plan::BILLING_ONE_TIME])],
            // C2: per-currency prices. Operator-supplied minor-unit
            // integers keyed by ISO 4217 code. USD is always written
            // from `price_cents` regardless of what comes in here.
            'prices' => ['sometimes', 'nullable', 'array'],
            'prices.*' => ['sometimes', 'integer', 'min:0', 'max:99999900'],
            // Slug isn't admin-editable on update — it locks once a row
            // exists so workspaces.plan_id lookups by slug stay stable.
            'slug' => $plan === null
                ? ['nullable', 'string', 'max:80', 'alpha_dash', Rule::unique('plans', 'slug')]
                : ['nullable', 'string'],
        ]);

        // A non-trial plan never carries a trial length — clear any stale
        // value so toggling is_trial off can't leave a phantom window.
        if (empty($validated['is_trial'])) {
            $validated['trial_days'] = null;
        }

        return $validated;
    }

    /**
     * Slug autogeneration on create. Falls back to a numeric suffix on
     * collision so an admin retyping "Standard" twice doesn't 500.
     */
    private function ensureUniqueSlug(string $name): string
    {
        $base = Str::slug($name);

        if ($base === '') {
            $base = 'plan-'.Str::random(6);
        }

        $slug = $base;
        $i = 2;

        while (Plan::query()->where('slug', $slug)->exists()) {
            $slug = "{$base}-{$i}";
            $i++;
        }

        return $slug;
    }

    /**
     * Run every enabled-and-configured gateway's sync. Failures on one
     * gateway don't roll back the local plan write or block the others —
     * the admin gets a flash message naming the gateways that failed and
     * can re-run them individually from the index page.
     */
    private function trySyncAll(Plan $plan): void
    {
        if ($plan->price_cents <= 0) {
            return;
        }

        $errors = [];
        foreach ($this->gateways->enabledGateways() as $gateway) {
            try {
                match ($gateway) {
                    PaymentGatewayRegistry::STRIPE => $this->stripe->syncPlan($plan),
                    PaymentGatewayRegistry::PAYPAL => $this->paypal->syncPlan($plan),
                    PaymentGatewayRegistry::RAZORPAY => $this->razorpay->syncPlan($plan),
                };
            } catch (\Throwable $e) {
                $errors[] = ucfirst($gateway).': '.$e->getMessage();
            }
        }

        if ($errors !== []) {
            session()->flash(
                'error',
                'Plan saved locally, but gateway sync failed → '.implode(' | ', $errors),
            );
        }
    }

    /**
     * Build the wire payload for one plan, including the gateway-side
     * IDs the index page renders into per-gateway badges.
     *
     * @return array<string, mixed>
     */
    private function serialize(Plan $plan): array
    {
        return [
            'id' => $plan->id,
            'name' => $plan->name,
            'slug' => $plan->slug,
            'monthly_conversations' => $plan->monthly_conversations,
            'monthly_messages' => $plan->monthly_messages,
            'max_tokens_per_response' => $plan->max_tokens_per_response,
            'agents_limit' => $plan->agents_limit,
            'sources_limit' => $plan->sources_limit,
            'workflows_limit' => $plan->workflows_limit,
            'integrations_limit' => $plan->integrations_limit,
            'members_limit' => $plan->members_limit,
            'workspaces_limit' => $plan->workspaces_limit,
            'api_access' => (bool) ($plan->api_access ?? true),
            'price_cents' => $plan->price_cents,
            'yearly_price_cents' => $plan->yearly_price_cents,
            'interval' => $plan->interval ?? 'month',
            'features' => $plan->features ?? [],
            'is_active' => (bool) $plan->is_active,
            'show_on_pricing_page' => (bool) ($plan->show_on_pricing_page ?? true),
            'is_default_for_signup' => (bool) ($plan->is_default_for_signup ?? false),
            'is_trial' => (bool) ($plan->is_trial ?? false),
            'trial_days' => $plan->trial_days,
            'billing_model' => $plan->billing_model ?? Plan::BILLING_SUBSCRIPTION,
            'prices' => $plan->prices ?? [],
            'workspaces_count' => (int) ($plan->workspaces_count ?? 0),
            'created_at' => $plan->created_at?->toIso8601String(),
            'gateway_ids' => [
                'stripe' => [
                    'product' => $plan->stripe_product_id,
                    'price' => $plan->stripe_price_id,
                ],
                'paypal' => [
                    'product' => $plan->paypal_product_id ?? null,
                    'plan' => $plan->paypal_plan_id ?? null,
                ],
                'razorpay' => [
                    'plan' => $plan->razorpay_plan_id ?? null,
                ],
            ],
        ];
    }

    /**
     * Per-gateway availability snapshot the index page uses to decide
     * which sync columns to render and which buttons to disable.
     *
     * @return array<string, array{enabled: bool, configured: bool, available: bool}>
     */
    private function gatewayStatuses(): array
    {
        $out = [];
        foreach ([PaymentGatewayRegistry::STRIPE, PaymentGatewayRegistry::PAYPAL, PaymentGatewayRegistry::RAZORPAY] as $gateway) {
            $out[$gateway] = [
                'enabled' => $this->gateways->isEnabled($gateway),
                'configured' => $this->gateways->isConfigured($gateway),
                'available' => $this->gateways->isAvailable($gateway),
            ];
        }

        return $out;
    }
}
