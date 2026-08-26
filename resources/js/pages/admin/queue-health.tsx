import { Head, router } from '@inertiajs/react';
import { useEffect } from 'react';
import { QueueHealthCard } from '@/components/admin/queue-health-card';
import type { QueueHealthShape } from '@/components/admin/queue-health-card';
import AdminLayout from '@/layouts/admin-layout';
import { useT } from '@/lib/i18n';
import { dashboard as adminDashboard } from '@/routes/admin';

type Props = {
    health: QueueHealthShape;
};

/**
 * Dedicated full-width Queue Health page. Same payload as the
 * widget on /admin (DashboardController) but rendered with more
 * vertical room — the live job feed gets ~28rem of scroll space
 * here vs. the dashboard's compact slot. Polled every 4s on this
 * page (vs. 8s on the dashboard) because operators landing here
 * are typically debugging in real time.
 */
export default function QueueHealth({ health }: Props) {
    const { t } = useT();

    useEffect(() => {
        const id = globalThis.setInterval(() => {
            router.reload({ only: ['health'] });
        }, 4000);

        return () => globalThis.clearInterval(id);
    }, []);

    return (
        <AdminLayout
            breadcrumbs={[
                { title: t('Dashboard'), href: adminDashboard() },
                { title: t('Queue health'), href: '/admin/queue-health' },
            ]}
        >
            <Head title={t('Queue health')} />
            <div className="flex min-h-0 flex-1 flex-col gap-4 bg-card p-4">
                <div>
                    <h1 className="text-lg font-semibold">
                        {t('Queue health')}
                    </h1>
                    <p className="mt-1 text-sm text-muted-foreground">
                        {t(
                            'Live view of the Cloudflare Cron Worker + queue worker. Refreshes every 4 seconds.',
                        )}
                    </p>
                </div>
                <QueueHealthCard health={health} t={t} />
            </div>
        </AdminLayout>
    );
}
