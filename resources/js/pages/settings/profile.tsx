import { Form, Head, Link, usePage } from '@inertiajs/react';
import ProfileController from '@/actions/App/Http/Controllers/Settings/ProfileController';
import DeleteUser from '@/components/delete-user';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useT } from '@/lib/i18n';
import { edit } from '@/routes/profile';
import { send } from '@/routes/verification';

export default function Profile({
    mustVerifyEmail,
    status,
}: {
    mustVerifyEmail: boolean;
    status?: string;
}) {
    const { t } = useT();
    const { auth } = usePage().props;

    return (
        <>
            <Head title={t('Profile settings')} />

            <h1 className="sr-only">{t('Profile settings')}</h1>

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title={t('Profile information')}
                    description={t('Update your name and email address')}
                />

                <Card className="border-border/80 p-5">
                    <Form
                        {...ProfileController.update.form()}
                        options={{
                            preserveScroll: true,
                        }}
                        className="grid gap-4"
                    >
                        {({ processing, errors }) => (
                            <>
                                <div className="grid gap-1.5">
                                    <Label htmlFor="name">{t('Name')}</Label>

                                    <Input
                                        id="name"
                                        defaultValue={auth.user.name}
                                        name="name"
                                        required
                                        autoComplete="name"
                                        placeholder={t('Full name')}
                                    />

                                    <InputError message={errors.name} />
                                </div>

                                <div className="grid gap-1.5">
                                    <Label htmlFor="email">
                                        {t('Email address')}
                                    </Label>

                                    <Input
                                        id="email"
                                        type="email"
                                        defaultValue={auth.user.email}
                                        name="email"
                                        required
                                        autoComplete="username"
                                        placeholder={t('Email address')}
                                    />

                                    <InputError message={errors.email} />
                                </div>

                                <div className="grid gap-2 rounded-md border bg-muted/30 p-3">
                                    <div className="flex items-start gap-3">
                                        <Checkbox
                                            id="live_chat_available"
                                            name="live_chat_available"
                                            value="1"
                                            defaultChecked={
                                                auth.user
                                                    .live_chat_available ===
                                                true
                                            }
                                        />
                                        <div className="grid gap-0.5">
                                            <Label
                                                htmlFor="live_chat_available"
                                                className="font-medium"
                                            >
                                                {t('Receive live chats')}
                                            </Label>
                                            <p className="text-xs text-muted-foreground">
                                                {t(
                                                    'When on, visitors who click "Connect me with a human" can be routed to you while this browser tab is open.',
                                                )}
                                            </p>
                                        </div>
                                    </div>
                                </div>

                                {mustVerifyEmail &&
                                    auth.user.email_verified_at === null && (
                                        <div className="rounded-md border bg-muted/20 px-3 py-2 text-sm text-muted-foreground">
                                            {t(
                                                'Your email address is unverified.',
                                            )}{' '}
                                            <Link
                                                href={send()}
                                                as="button"
                                                className="text-foreground underline decoration-neutral-300 underline-offset-4 transition-colors duration-300 ease-out hover:decoration-current! dark:decoration-neutral-500"
                                            >
                                                {t(
                                                    'Click here to resend the verification email.',
                                                )}
                                            </Link>
                                            {status ===
                                                'verification-link-sent' && (
                                                <div className="mt-2 text-xs font-medium text-emerald-700 dark:text-emerald-400">
                                                    {t(
                                                        'A new verification link has been sent to your email address.',
                                                    )}
                                                </div>
                                            )}
                                        </div>
                                    )}

                                <div className="flex items-center justify-end gap-4 border-t pt-4">
                                    <Button
                                        disabled={processing}
                                        data-test="update-profile-button"
                                    >
                                        {t('Save')}
                                    </Button>
                                </div>
                            </>
                        )}
                    </Form>
                </Card>
            </div>

            <DeleteUser />
        </>
    );
}

Profile.layout = {
    breadcrumbs: [
        {
            title: 'Profile settings',
            href: edit(),
        },
    ],
};
