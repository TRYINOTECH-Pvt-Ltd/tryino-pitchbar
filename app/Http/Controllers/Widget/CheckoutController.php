<?php

namespace App\Http\Controllers\Widget;

use App\Models\Agent;
use App\Models\Conversation;
use App\Models\InChatPayment;
use App\Services\Vertical\VerticalPresetRegistry;
use App\Services\Widget\WidgetJwt;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Stripe\Exception\ApiErrorException;
use Stripe\StripeClient;

/**
 * Mint Stripe Checkout sessions for in-chat <checkout/> blocks. The
 * widget POSTs the parsed block payload + the visitor's JWT; we
 * resolve the workspace, capability-gate on `in_chat_payments`,
 * create a one-time Stripe Checkout session in `mode=payment`, and
 * return its hosted URL.
 *
 * The visitor's browser opens `checkout_url` in a new tab so the
 * chat surface stays mounted — when `checkout.session.completed`
 * fires on the webhook, we broadcast a "Payment received" message
 * back into the same conversation via Reverb.
 */
class CheckoutController
{
    public function __construct(
        private readonly WidgetJwt $jwt,
        private readonly VerticalPresetRegistry $presets,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $token = $request->bearerToken() ?? $request->header('X-Widget-Token');
        if (! is_string($token)) {
            return response()->json(['error' => ['code' => 'missing_token']], 401);
        }

        try {
            $claims = $this->jwt->verify($token);
        } catch (\Throwable) {
            return response()->json(['error' => ['code' => 'invalid_token']], 401);
        }

        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'amount' => ['required', 'numeric', 'min:0.5'],
            'currency' => ['required', 'string', 'size:3'],
            'description' => ['nullable', 'string', 'max:500'],
            'product_id' => ['nullable', 'string', 'max:191'],
            'success_url' => ['nullable', 'string', 'url', 'max:500'],
            'cancel_url' => ['nullable', 'string', 'url', 'max:500'],
        ]);

        $conversationId = (string) ($claims['conversation_id'] ?? '');
        $conversation = Conversation::query()
            ->withoutGlobalScopes()
            ->find($conversationId);
        if ($conversation === null) {
            return response()->json(['error' => ['code' => 'conversation_not_found']], 404);
        }

        $agent = $conversation->agent()->withoutGlobalScopes()->first();
        if ($agent === null) {
            return response()->json(['error' => ['code' => 'agent_not_found']], 404);
        }

        $capabilities = $this->effectiveCapabilities($agent);
        if (! in_array('in_chat_payments', $capabilities, true)) {
            return response()->json([
                'error' => [
                    'code' => 'capability_disabled',
                    'message' => 'In-chat payments are not enabled for this agent.',
                ],
            ], 403);
        }

        $workspaceId = $agent->workspace_id;

        try {
            $stripe = $this->resolveStripeClient();
        } catch (\Throwable $e) {
            Log::warning('widget.checkout.stripe_unconfigured', [
                'agent_id' => $agent->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'error' => [
                    'code' => 'stripe_unconfigured',
                    'message' => 'Payments are not currently available. Please try again later.',
                ],
            ], 503);
        }

        $amountCents = (int) round((float) $data['amount'] * 100);
        $currency = strtolower($data['currency']);
        $appUrl = rtrim((string) config('app.url'), '/');
        $successUrl = $data['success_url'] ?? ($appUrl.'/widget/checkout/success?session_id={CHECKOUT_SESSION_ID}');
        $cancelUrl = $data['cancel_url'] ?? ($appUrl.'/widget/checkout/cancel?session_id={CHECKOUT_SESSION_ID}');

        try {
            $session = $stripe->checkout->sessions->create([
                'mode' => 'payment',
                'line_items' => [[
                    'price_data' => [
                        'currency' => $currency,
                        'unit_amount' => $amountCents,
                        'product_data' => array_filter([
                            'name' => $data['title'],
                            'description' => $data['description'] ?? null,
                        ]),
                    ],
                    'quantity' => 1,
                ]],
                'success_url' => $successUrl,
                'cancel_url' => $cancelUrl,
                'client_reference_id' => $conversation->id,
                'metadata' => [
                    'workspace_id' => $workspaceId,
                    'agent_id' => $agent->id,
                    'conversation_id' => $conversation->id,
                    'pitchbar_kind' => 'in_chat_checkout',
                ],
            ]);
        } catch (ApiErrorException $e) {
            Log::warning('widget.checkout.stripe_create_failed', [
                'agent_id' => $agent->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'error' => [
                    'code' => 'stripe_create_failed',
                    'message' => 'Could not create a payment session. Please try again.',
                ],
            ], 502);
        }

        $payment = InChatPayment::query()->withoutGlobalScopes()->create([
            'workspace_id' => $workspaceId,
            'agent_id' => $agent->id,
            'conversation_id' => $conversation->id,
            'stripe_session_id' => $session->id,
            'amount_cents' => $amountCents,
            'currency' => strtoupper($currency),
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'status' => InChatPayment::STATUS_CREATED,
        ]);

        return response()->json([
            'data' => [
                'id' => $payment->id,
                'checkout_url' => $session->url,
                'session_id' => $session->id,
            ],
        ]);
    }

    /**
     * Mirror ToolRegistry's capability resolution: overrides win when
     * present, else fall back to the agent's vertical preset list.
     *
     * @return array<int, string>
     */
    private function effectiveCapabilities(Agent $agent): array
    {
        $overrides = (array) ($agent->vertical_overrides ?? []);
        if (isset($overrides['capabilities']) && is_array($overrides['capabilities'])) {
            return array_values($overrides['capabilities']);
        }

        if ($agent->site_type === null) {
            return [];
        }

        return $this->presets->for((string) $agent->site_type)->capabilities();
    }

    private function resolveStripeClient(): StripeClient
    {
        $secret = (string) config('cashier.secret', config('services.stripe.secret', ''));
        if ($secret === '') {
            throw new \RuntimeException('Stripe secret is not configured.');
        }

        return new StripeClient($secret);
    }
}
