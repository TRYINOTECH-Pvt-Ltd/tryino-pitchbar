import { Head } from '@inertiajs/react';
import AdminLayout from '@/layouts/admin-layout';
import { useT } from '@/lib/i18n';
import { PlanForm } from './plan-form';

type Props = { currency: string };

export default function CreatePlan({ currency }: Props) {
    const { t } = useT();

    return (
        <AdminLayout
            breadcrumbs={[
                { title: t('Admin'), href: '/admin' },
                { title: t('Plans'), href: '/admin/plans' },
                { title: t('New plan'), href: '/admin/plans/create' },
            ]}
        >
            <Head title={t('New plan · Admin')} />
            <div className="flex flex-1 flex-col gap-4 p-4">
                <div>
                    <h1 className="text-2xl font-semibold tracking-tight">
                        {t('New plan')}
                    </h1>
                    <p className="mt-1 text-sm text-muted-foreground">
                        {t(
                            'After saving, the plan is mirrored to Stripe as a Product + Price (paid plans only). The slug is auto-generated from the display name and locked once created.',
                        )}
                    </p>
                </div>

                <PlanForm
                    initial={{
                        name: '',
                        monthly_conversations: 500,
                        monthly_messages: null,
                        max_tokens_per_response: null,
                        agents_limit: null,
                        sources_limit: null,
                        workflows_limit: null,
                        integrations_limit: null,
                        members_limit: null,
                        workspaces_limit: null,
                        api_access: true,
                        price_cents: 4900,
                        yearly_price_cents: null,
                        interval: 'month',
                        is_active: true,
                        is_default_for_signup: false,
                        show_on_pricing_page: true,
                        is_trial: false,
                        trial_days: null,
                        features: { remove_branding: true },
                    }}
                    method="post"
                    action="/admin/plans"
                    submitLabel={t('Create plan')}
                    currency={currency}
                />
            </div>
        </AdminLayout>
    );
}
