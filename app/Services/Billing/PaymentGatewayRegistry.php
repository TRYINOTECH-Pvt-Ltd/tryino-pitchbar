<?php

namespace App\Services\Billing;

/**
 * Resolves which payment gateways are enabled and ready for checkout.
 *
 * A gateway shows up to customers only when it's both
 *
 *   1. enabled by the admin (`*_enabled` toggle in app_settings), AND
 *   2. configured (the credentials it actually needs are present).
 *
 * That avoids a half-wired install ever leaking a broken "Choose plan"
 * button — the customer either sees a working gateway or none at all.
 */
class PaymentGatewayRegistry
{
    public const STRIPE = 'stripe';

    public const PAYPAL = 'paypal';

    public const RAZORPAY = 'razorpay';

    /**
     * @return list<string>
     */
    public function enabledGateways(): array
    {
        return array_values(array_filter(
            [self::STRIPE, self::PAYPAL, self::RAZORPAY],
            fn (string $gateway): bool => $this->isAvailable($gateway),
        ));
    }

    public function isAvailable(string $gateway): bool
    {
        return $this->isEnabled($gateway) && $this->isConfigured($gateway);
    }

    public function isEnabled(string $gateway): bool
    {
        return match ($gateway) {
            self::STRIPE => (bool) config('cashier.enabled', true),
            self::PAYPAL => (bool) config('services.paypal.enabled', false),
            self::RAZORPAY => (bool) config('services.razorpay.enabled', false),
            default => false,
        };
    }

    public function isConfigured(string $gateway): bool
    {
        return match ($gateway) {
            self::STRIPE => (string) (config('cashier.secret') ?? '') !== '',
            self::PAYPAL => (string) config('services.paypal.client_id', '') !== ''
                && (string) config('services.paypal.client_secret', '') !== '',
            self::RAZORPAY => (string) config('services.razorpay.key_id', '') !== ''
                && (string) config('services.razorpay.key_secret', '') !== '',
            default => false,
        };
    }

    /**
     * Default gateway picked when the customer doesn't specify one. Picks
     * the first available, falling back to Stripe so the existing flow
     * still works on installs that haven't toggled the new gateways on.
     */
    public function defaultGateway(): string
    {
        $enabled = $this->enabledGateways();

        if (in_array(self::STRIPE, $enabled, true)) {
            return self::STRIPE;
        }

        return $enabled[0] ?? self::STRIPE;
    }

    /**
     * @return list<string>
     */
    public function allGateways(): array
    {
        return [self::STRIPE, self::PAYPAL, self::RAZORPAY];
    }
}
