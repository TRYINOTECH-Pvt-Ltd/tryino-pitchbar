import { Head, useForm } from '@inertiajs/react';
import {
    Building,
    CheckCircle2,
    Clock,
    MessageSquare,
    User as UserIcon,
} from 'lucide-react';
import { useMemo } from 'react';
import { BusinessHoursGrid } from '@/components/live-chat/business-hours-grid';
import type { BusinessHoursValue } from '@/components/live-chat/business-hours-grid';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { useT } from '@/lib/i18n';
import type { BreadcrumbItem } from '@/types';

type Props = {
    workspace: {
        id: string;
        live_chat_personalize: boolean;
        slack_webhook_url: string | null;
        teams_webhook_url: string | null;
        business_hours: BusinessHoursValue;
    };
    preview: {
        is_open_now: boolean;
        next_open_at: string | null;
        server_now: string;
    };
};

export default function LiveChatSettings({ workspace, preview }: Props) {
    const { t } = useT();
    const form = useForm({
        live_chat_personalize: workspace.live_chat_personalize,
        slack_webhook_url: workspace.slack_webhook_url ?? '',
        teams_webhook_url: workspace.teams_webhook_url ?? '',
        business_hours: workspace.business_hours as BusinessHoursValue,
    });

    const breadcrumbs: BreadcrumbItem[] = [
        { title: t('Live chat settings'), href: '/app/settings/live-chat' },
    ];

    const onSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        form.patch('/app/settings/live-chat', { preserveScroll: true });
    };

    const previewLine = useMemo(() => {
        if (preview.is_open_now) {
            return t(
                'Workspace is currently OPEN. Visitors who request a human will be queued.',
            );
        }

        if (preview.next_open_at) {
            return t('Workspace is currently CLOSED. Next open at :time.', {
                time: new Date(preview.next_open_at).toLocaleString(),
            });
        }

        return t(
            'Workspace is currently CLOSED (no upcoming open slots configured).',
        );
    }, [preview.is_open_now, preview.next_open_at, t]);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('Live chat settings')} />
            <div className="flex flex-1 flex-col gap-4 p-4">
                <div>
                    <h1 className="text-2xl font-semibold tracking-tight">
                        {t('Live chat settings')}
                    </h1>
                    <p className="mt-1 text-sm text-muted-foreground">
                        {t(
                            "Configure how visitors reach a human, where operators get pinged, and when you're available.",
                        )}
                    </p>
                </div>

                <form onSubmit={onSubmit} className="grid gap-4">
                    {/* Personalization */}
                    <Card className="p-5">
                        <div className="flex items-start gap-3">
                            <UserIcon className="mt-0.5 size-5 text-muted-foreground" />
                            <div className="grid flex-1 gap-1.5">
                                <Label className="text-base font-semibold">
                                    {t('Show operator name to visitors')}
                                </Label>
                                <p className="text-sm text-muted-foreground">
                                    {t(
                                        'On — visitors see "Sarah joined the chat". Off — visitors see "An agent joined" with no individual identity exposed (recommended for legal, healthcare, finance).',
                                    )}
                                </p>
                                <div className="mt-1 flex items-center gap-2">
                                    <Checkbox
                                        id="live_chat_personalize"
                                        checked={
                                            form.data.live_chat_personalize
                                        }
                                        onCheckedChange={(v) =>
                                            form.setData(
                                                'live_chat_personalize',
                                                v === true,
                                            )
                                        }
                                    />
                                    <Label
                                        htmlFor="live_chat_personalize"
                                        className="text-sm font-medium"
                                    >
                                        {t('Personalize live chat')}
                                    </Label>
                                </div>
                            </div>
                        </div>
                    </Card>

                    {/* Webhooks */}
                    <Card className="p-5">
                        <div className="flex items-start gap-3">
                            <MessageSquare className="mt-0.5 size-5 text-muted-foreground" />
                            <div className="grid flex-1 gap-3">
                                <div>
                                    <Label className="text-base font-semibold">
                                        {t('Slack / Teams notifications')}
                                    </Label>
                                    <p className="text-sm text-muted-foreground">
                                        {t(
                                            "Paste an incoming-webhook URL. When a visitor asks for a human, we'll fire a compact ping with the conversation link so an operator can jump in from wherever they are.",
                                        )}
                                    </p>
                                </div>

                                <div className="grid gap-1.5">
                                    <Label htmlFor="slack_webhook_url">
                                        {t('Slack webhook URL')}
                                    </Label>
                                    <Input
                                        id="slack_webhook_url"
                                        type="url"
                                        autoComplete="off"
                                        value={form.data.slack_webhook_url}
                                        onChange={(e) =>
                                            form.setData(
                                                'slack_webhook_url',
                                                e.target.value,
                                            )
                                        }
                                        placeholder="https://hooks.slack.com/services/..."
                                    />
                                    {form.errors.slack_webhook_url && (
                                        <p className="text-xs text-red-600">
                                            {form.errors.slack_webhook_url}
                                        </p>
                                    )}
                                </div>

                                <div className="grid gap-1.5">
                                    <Label htmlFor="teams_webhook_url">
                                        {t('Microsoft Teams webhook URL')}
                                    </Label>
                                    <Input
                                        id="teams_webhook_url"
                                        type="url"
                                        autoComplete="off"
                                        value={form.data.teams_webhook_url}
                                        onChange={(e) =>
                                            form.setData(
                                                'teams_webhook_url',
                                                e.target.value,
                                            )
                                        }
                                        placeholder="https://outlook.office.com/webhook/..."
                                    />
                                    {form.errors.teams_webhook_url && (
                                        <p className="text-xs text-red-600">
                                            {form.errors.teams_webhook_url}
                                        </p>
                                    )}
                                </div>
                            </div>
                        </div>
                    </Card>

                    {/* Business hours */}
                    <Card className="p-5">
                        <div className="flex items-start gap-3">
                            <Clock className="mt-0.5 size-5 text-muted-foreground" />
                            <div className="grid flex-1 gap-3">
                                <div className="flex items-start justify-between gap-3">
                                    <div>
                                        <Label className="text-base font-semibold">
                                            {t('Business hours')}
                                        </Label>
                                        <p className="text-sm text-muted-foreground">
                                            {t(
                                                'When configured + outside the schedule, the visitor sees "We\'re closed right now" and the lead form. Leave the JSON as-is with enabled: false to stay always-on.',
                                            )}
                                        </p>
                                    </div>
                                    <Badge
                                        variant={
                                            preview.is_open_now
                                                ? 'default'
                                                : 'secondary'
                                        }
                                        className={
                                            preview.is_open_now
                                                ? 'bg-emerald-600 text-white'
                                                : ''
                                        }
                                    >
                                        {preview.is_open_now ? (
                                            <>
                                                <CheckCircle2 className="me-1 size-3" />
                                                {t('Open')}
                                            </>
                                        ) : (
                                            t('Closed')
                                        )}
                                    </Badge>
                                </div>

                                <div className="rounded-md border bg-muted/30 p-2 text-xs text-muted-foreground">
                                    <Building className="me-1 inline size-3" />
                                    {previewLine}
                                </div>

                                <BusinessHoursGrid
                                    value={form.data.business_hours}
                                    onChange={(next) =>
                                        form.setData('business_hours', next)
                                    }
                                />
                            </div>
                        </div>
                    </Card>

                    <div className="flex justify-end">
                        <Button type="submit" disabled={form.processing}>
                            {form.processing ? t('Saving…') : t('Save')}
                        </Button>
                    </div>
                </form>
            </div>
        </AppLayout>
    );
}
