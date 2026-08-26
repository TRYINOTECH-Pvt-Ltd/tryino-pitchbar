import { Head } from '@inertiajs/react';
import AdminLayout from '@/layouts/admin-layout';
import { useT } from '@/lib/i18n';
import { PlanForm } from './plan-form';

type Plan = {
    id: string;
    name: string;
    slug: string;
    monthly_conversations: number;
    monthly_messages: number | null;
    max_tokens_per_response: number | null;
    agents_limit: number | null;
    sources_limit: number | null;
    workflows_limit: number | null;
    integrations_limit: number | null;
    members_limit: number | null;
    workspaces_limit: number | null;
    api_access: boolean;
    price_cents: number;
    yearly_price_cents: number | null;
    interval: 'month' | 'year';
    stripe_product_id: string | null;
    stripe_price_id: string | null;
    features: Record<string, boolean>;
    is_active: boolean;
    is_default_for_signup: boolean;
    show_on_pricing_page: boolean;
    is_trial: boolean;
    trial_days: number | null;
};

type Props = { plan: Plan; currency: string };

export default function EditPlan({ plan, currency }: Props) {
    const { t } = useT();

    return (
        <AdminLayout
            breadcrumbs={[
                { title: t('Admin'), href: '/admin' },
                { title: t('Plans'), href: '/admin/plans' },
                {
                    title: plan.name,
                    href: `/admin/plans/${plan.id}/edit`,
                },
            ]}
        >
            <Head title={t('Edit :name · Admin', { name: plan.name })} />
            <div className="flex flex-1 flex-col gap-4 p-4">
                <div>
                    <h1 className="text-2xl font-semibold tracking-tight">
                        {plan.name}
                    </h1>
                    <p className="mt-1 text-sm text-muted-foreground">
                        {t('Slug:')}{' '}
                        <span className="font-mono">{plan.slug}</span> ·{' '}
                        {t(
                            'Saving a price change archives the prior Stripe Price and creates a new one. Existing subscriptions stay grandfathered on the old price.',
                        )}
                    </p>
                </div>

                <PlanForm
                    initial={{
                        name: plan.name,
                        monthly_conversations: plan.monthly_conversations,
                        monthly_messages: plan.monthly_messages,
                        max_tokens_per_response: plan.max_tokens_per_response,
                        agents_limit: plan.agents_limit ?? null,
                        sources_limit: plan.sources_limit ?? null,
                        workflows_limit: plan.workflows_limit ?? null,
                        integrations_limit: plan.integrations_limit ?? null,
                        members_limit: plan.members_limit ?? null,
                        workspaces_limit: plan.workspaces_limit ?? null,
                        api_access: plan.api_access !== false,
                        price_cents: plan.price_cents,
                        yearly_price_cents: plan.yearly_price_cents ?? null,
                        interval: plan.interval ?? 'month',
                        is_active: plan.is_active,
                        is_default_for_signup:
                            plan.is_default_for_signup ?? false,
                        show_on_pricing_page: plan.show_on_pricing_page ?? true,
                        is_trial: plan.is_trial ?? false,
                        trial_days: plan.trial_days ?? null,
                        features: {
                            remove_branding: Boolean(
                                plan.features.remove_branding,
                            ),
                        },
                    }}
                    method="patch"
                    action={`/admin/plans/${plan.id}`}
                    submitLabel={t('Save plan')}
                    currency={currency}
                    readonly={{
                        stripe_product_id: plan.stripe_product_id,
                        stripe_price_id: plan.stripe_price_id,
                    }}
                />
            </div>
        </AdminLayout>
    );
}
