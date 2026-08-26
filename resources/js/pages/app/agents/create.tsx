import { Head } from '@inertiajs/react';
import { AgentForm } from '@/components/agents/agent-form';
import AppLayout from '@/layouts/app-layout';
import { useT } from '@/lib/i18n';
import { create as createAgent, index as agentsIndex } from '@/routes/agents';
import type { BreadcrumbItem } from '@/types';

export default function CreateAgent() {
    const { t } = useT();

    const breadcrumbs: BreadcrumbItem[] = [
        { title: t('Agents'), href: agentsIndex.url() },
        { title: t('New'), href: createAgent.url() },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="New agent" />
            <div className="flex flex-1 flex-col gap-4 p-4">
                <h1 className="text-2xl font-semibold tracking-tight">
                    {t('Create agent')}
                </h1>
                <AgentForm mode="create" />
            </div>
        </AppLayout>
    );
}
