import { Form, Head, usePage } from '@inertiajs/react';
import { Download, Hammer, Package } from 'lucide-react';
import WordPressDistributionController from '@/actions/App/Http/Controllers/Admin/Platform/WordPressDistributionController';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { useBranding } from '@/hooks/use-branding';
import AdminLayout from '@/layouts/admin-layout';
import { useT } from '@/lib/i18n';

type Build = {
    version: string;
    filename: string;
    size: number;
    modified_at: string;
    download_url: string;
};

type Props = {
    builds: Build[];
    source_version: string | null;
};

function formatBytes(bytes: number): string {
    if (bytes <= 0) {
        return '0 B';
    }

    const units = ['B', 'KB', 'MB', 'GB'];
    const exp = Math.min(
        units.length - 1,
        Math.floor(Math.log(bytes) / Math.log(1024)),
    );

    return `${(bytes / Math.pow(1024, exp)).toFixed(1)} ${units[exp]}`;
}

function formatDate(iso: string): string {
    return new Date(iso).toLocaleString();
}

export default function WordPressDistributionPage({
    builds,
    source_version,
}: Props) {
    const { t } = useT();
    const branding = useBranding();
    const brand = branding.site_title || 'Pitchbar';
    const { flash } = usePage<{
        flash: { success?: string; error?: string };
    }>().props;

    return (
        <>
            <Head title={t('WordPress plugin distribution')} />

            <div className="space-y-6 p-6">
                <div className="space-y-1">
                    <h1 className="text-2xl font-semibold">
                        {t('WordPress plugin distribution')}
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        {t(
                            'Build and download the :brand WordPress + WooCommerce plugin. Upload the resulting zip to any WP install under Plugins → Add New → Upload Plugin.',
                            { brand },
                        )}
                    </p>
                </div>

                {flash?.success && (
                    <div className="rounded-md border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-900 dark:border-emerald-900/50 dark:bg-emerald-950/30 dark:text-emerald-100">
                        {flash.success}
                    </div>
                )}
                {flash?.error && (
                    <div className="rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-900 dark:border-red-900/50 dark:bg-red-950/30 dark:text-red-100">
                        {flash.error}
                    </div>
                )}

                <Card className="border-border/80 p-5">
                    <div className="flex flex-wrap items-center gap-4">
                        <Package className="h-6 w-6 text-muted-foreground" />
                        <div className="flex-1 space-y-0.5">
                            <p className="text-sm font-medium">
                                {t('Source version')}
                            </p>
                            <p className="text-sm text-muted-foreground">
                                {source_version
                                    ? t(
                                          'wp-plugin/pitchbar/ currently reports v{version}',
                                          { version: source_version },
                                      )
                                    : t(
                                          'wp-plugin/pitchbar/ source tree is missing or malformed.',
                                      )}
                            </p>
                        </div>
                        <Form
                            {...WordPressDistributionController.build.form()}
                            options={{ preserveScroll: true }}
                        >
                            {({ processing }) => (
                                <Button
                                    type="submit"
                                    disabled={
                                        processing || source_version === null
                                    }
                                >
                                    <Hammer className="mr-1 h-3.5 w-3.5" />
                                    {processing
                                        ? t('Building…')
                                        : t('Build latest')}
                                </Button>
                            )}
                        </Form>
                    </div>
                </Card>

                <div className="space-y-3">
                    <h2 className="text-sm font-semibold text-muted-foreground">
                        {t('Available builds')}
                    </h2>

                    {builds.length === 0 ? (
                        <Card className="border-dashed p-6 text-center text-sm text-muted-foreground">
                            {t(
                                'No builds yet. Click "Build latest" above to package the plugin.',
                            )}
                        </Card>
                    ) : (
                        <div className="grid gap-2">
                            {builds.map((build) => (
                                <Card
                                    key={build.filename}
                                    className="flex items-center gap-4 border-border/70 p-4"
                                >
                                    <div className="flex-1 space-y-1">
                                        <div className="flex items-center gap-2">
                                            <p className="font-mono text-sm">
                                                {build.filename}
                                            </p>
                                            <Badge variant="secondary">
                                                v{build.version}
                                            </Badge>
                                        </div>
                                        <p className="text-xs text-muted-foreground">
                                            {formatBytes(build.size)} ·{' '}
                                            {t('Built')}{' '}
                                            {formatDate(build.modified_at)}
                                        </p>
                                    </div>
                                    <Button asChild variant="default" size="sm">
                                        <a href={build.download_url}>
                                            <Download className="mr-1 h-3.5 w-3.5" />
                                            {t('Download')}
                                        </a>
                                    </Button>
                                </Card>
                            ))}
                        </div>
                    )}
                </div>

                <Card className="border-border/80 p-5">
                    <h2 className="text-sm font-semibold">
                        {t('Install the plugin in WordPress')}
                    </h2>
                    <ol className="mt-3 list-decimal space-y-1 pl-5 text-sm text-muted-foreground">
                        <li>
                            {t(
                                'Download the latest build above. The file is named pitchbar-{version}.zip with pitchbar.php at its root.',
                            )}
                        </li>
                        <li>
                            {t(
                                'In WordPress: Plugins → Add New → Upload Plugin → select the downloaded zip → Install Now → Activate.',
                            )}
                        </li>
                        <li>
                            {t(
                                'Open Settings → :brand, paste this :brand URL and an API token from Settings → API tokens, click Test connection, pick an agent, save.',
                                { brand },
                            )}
                        </li>
                    </ol>
                </Card>
            </div>
        </>
    );
}

WordPressDistributionPage.layout = (page: React.ReactElement) => (
    <AdminLayout
        breadcrumbs={[
            { title: 'Platform admin', href: '/admin' },
            {
                title: 'WordPress plugin',
                href: '/admin/integrations/wordpress',
            },
        ]}
    >
        {page}
    </AdminLayout>
);
