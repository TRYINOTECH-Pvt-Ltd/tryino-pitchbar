import { Head, router } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { useBranding } from '@/hooks/use-branding';
import AppLayout from '@/layouts/app-layout';
import { useT } from '@/lib/i18n';

type Props = {
    razorpay_key_id: string;
    subscription_id: string;
    plan: { name: string; price_cents: number; currency: string };
    customer: { name: string; email: string };
    success_url: string;
    cancel_url: string;
};

declare global {
    interface Window {
        Razorpay?: new (options: Record<string, unknown>) => {
            open: () => void;
            on: (event: string, handler: (...args: unknown[]) => void) => void;
        };
    }
}

const SCRIPT_SRC = 'https://checkout.razorpay.com/v1/checkout.js';

/**
 * Thin launcher page that boots Razorpay's hosted Checkout.js with the
 * server-created subscription. The webhook is the source of truth for
 * the actual subscription state — this page just hands the customer off
 * to Razorpay's hosted UI and forwards them back to /app/billing on
 * success or cancel.
 */
export default function RazorpayCheckout({
    razorpay_key_id,
    subscription_id,
    plan,
    customer,
    success_url,
    cancel_url,
}: Props) {
    const { t } = useT();
    const branding = useBranding();
    const brandName = branding.site_title || t('Sales AI');
    const [scriptError, setScriptError] = useState<string | null>(null);
    const opened = useRef(false);

    useEffect(() => {
        if (opened.current) {
            return;
        }

        const open = () => {
            if (opened.current || !window.Razorpay) {
                return;
            }

            opened.current = true;

            const rzp = new window.Razorpay({
                key: razorpay_key_id,
                subscription_id,
                name: brandName,
                description: t(':name subscription', { name: plan.name }),
                prefill: {
                    name: customer.name,
                    email: customer.email,
                },
                theme: { color: '#111827' },
                handler: () => {
                    router.visit(success_url);
                },
                modal: {
                    ondismiss: () => {
                        router.visit(cancel_url);
                    },
                },
            });

            rzp.on('payment.failed', () => {
                router.visit(cancel_url);
            });

            rzp.open();
        };

        if (window.Razorpay) {
            open();

            return;
        }

        const existing = document.querySelector<HTMLScriptElement>(
            `script[src="${SCRIPT_SRC}"]`,
        );

        if (existing) {
            existing.addEventListener('load', open);
            existing.addEventListener('error', () =>
                setScriptError(t('Could not load Razorpay Checkout.')),
            );

            return () => {
                existing.removeEventListener('load', open);
            };
        }

        const script = document.createElement('script');
        script.src = SCRIPT_SRC;
        script.async = true;
        script.onload = open;
        script.onerror = () =>
            setScriptError(t('Could not load Razorpay Checkout.'));
        document.body.appendChild(script);
    }, [
        razorpay_key_id,
        subscription_id,
        plan.name,
        customer.name,
        customer.email,
        success_url,
        cancel_url,
        brandName,
        t,
    ]);

    return (
        <AppLayout
            breadcrumbs={[{ title: t('Billing'), href: '/app/billing' }]}
        >
            <Head title={t('Razorpay checkout')} />
            <div className="flex flex-1 items-center justify-center p-6">
                <Card className="w-full max-w-md p-6 text-center">
                    <h1 className="text-xl font-semibold tracking-tight">
                        {t('Opening Razorpay…')}
                    </h1>
                    <p className="mt-2 text-sm text-muted-foreground">
                        {t('Subscribing to')}{' '}
                        <span className="font-medium">{plan.name}</span>{' '}
                        {t('for')} {plan.currency}{' '}
                        {(plan.price_cents / 100).toFixed(2)} {t('/ month.')}
                    </p>
                    {scriptError && (
                        <p className="mt-4 text-sm text-rose-600">
                            {scriptError}
                        </p>
                    )}
                    <div className="mt-6 flex justify-center gap-2">
                        <Button asChild variant="outline">
                            <a href={cancel_url}>{t('Cancel')}</a>
                        </Button>
                    </div>
                </Card>
            </div>
        </AppLayout>
    );
}
