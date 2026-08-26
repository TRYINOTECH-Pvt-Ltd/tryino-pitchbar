import { Head, Link } from '@inertiajs/react';
import { Workflow as WorkflowIcon } from 'lucide-react';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { useT } from '@/lib/i18n';
import type { BreadcrumbItem } from '@/types';
import { WorkflowForm } from './workflow-form';
import type { WorkflowFormValues } from './workflow-form';

type Step = WorkflowFormValues['steps'][number];

type WorkflowProp = {
    id: string;
    name: string;
    status: 'draft' | 'active' | 'disabled';
    agent_id: string | null;
    trigger_kind: 'on_keyword';
    keywords: string[];
    steps: Step[];
};

type Props = {
    workflow: WorkflowProp;
    agents: Array<{ id: string; name: string }>;
};

export default function EditWorkflow({ workflow, agents }: Props) {
    const { t } = useT();
    const breadcrumbs: BreadcrumbItem[] = [
        { title: t('Workflows'), href: '/app/workflows' },
        { title: workflow.name, href: `/app/workflows/${workflow.id}/edit` },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('Edit :name', { name: workflow.name })} />
            <div className="flex flex-1 flex-col gap-4 p-4">
                <div className="flex items-start justify-between gap-3">
                    <h1 className="text-2xl font-semibold tracking-tight">
                        {workflow.name}
                    </h1>
                    <Button asChild variant="outline" size="sm">
                        <Link href={`/app/workflows/${workflow.id}/canvas`}>
                            <WorkflowIcon className="me-1 size-4" />{' '}
                            {t('Open canvas')}
                        </Link>
                    </Button>
                </div>

                <WorkflowForm
                    initial={{
                        name: workflow.name,
                        status: workflow.status,
                        agent_id: workflow.agent_id,
                        trigger_kind: workflow.trigger_kind,
                        keywords: workflow.keywords,
                        steps: workflow.steps,
                    }}
                    method="patch"
                    action={`/app/workflows/${workflow.id}`}
                    submitLabel={t('Save workflow')}
                    agents={agents}
                />
            </div>
        </AppLayout>
    );
}
