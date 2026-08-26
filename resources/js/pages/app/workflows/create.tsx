import { Head } from '@inertiajs/react';
import AppLayout from '@/layouts/app-layout';
import { useT } from '@/lib/i18n';
import type { BreadcrumbItem } from '@/types';
import { WorkflowForm } from './workflow-form';

type Props = {
    agents: Array<{ id: string; name: string }>;
};

export default function CreateWorkflow({ agents }: Props) {
    const { t } = useT();

    const breadcrumbs: BreadcrumbItem[] = [
        { title: t('Workflows'), href: '/app/workflows' },
        { title: t('New'), href: '/app/workflows/create' },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('New workflow')} />
            <div className="flex flex-1 flex-col gap-4 p-4">
                <div>
                    <h1 className="text-2xl font-semibold tracking-tight">
                        {t('New workflow')}
                    </h1>
                    <p className="mt-1 text-sm text-muted-foreground">
                        {t(
                            'Save as Draft to keep editing, or Active to start firing on matching visitor messages.',
                        )}
                    </p>
                </div>

                <WorkflowForm
                    initial={{
                        name: '',
                        status: 'draft',
                        agent_id: null,
                        trigger_kind: 'on_keyword',
                        keywords: [],
                        steps: [],
                    }}
                    method="post"
                    action="/app/workflows"
                    submitLabel={t('Create workflow')}
                    agents={agents}
                />
            </div>
        </AppLayout>
    );
}
