import { Head, router } from '@inertiajs/react';
import { Globe } from 'lucide-react';
import { useState } from 'react';
import Heading from '@/components/heading';
import { LocalePickerDialog } from '@/components/locale-switcher';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { localeFlag, localeLabel, useI18n, useT } from '@/lib/i18n';
import { edit } from '@/routes/locale';
import type { SupportedLocale } from '@/types';

type Props = {
    supported: SupportedLocale[];
};

export default function Locale({ supported }: Props) {
    const { t, locale } = useT();
    const { catalog } = useI18n();
    const [pickerOpen, setPickerOpen] = useState(false);

    const switchTo = (next: SupportedLocale) => {
        setPickerOpen(false);

        if (next === locale) {
            return;
        }

        router.patch(
            '/locale/switch',
            { locale: next },
            { preserveScroll: true, preserveState: false },
        );
    };

    const flag = localeFlag(catalog, locale);
    const label = localeLabel(catalog, locale);
    const english = catalog[locale]?.english ?? locale;

    return (
        <>
            <Head title={t('Language')} />

            <h1 className="sr-only">{t('Language')}</h1>

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title={t('Language')}
                    description={t('Choose your language')}
                />

                <Card className="border-border/80 p-5">
                    <div className="flex items-center gap-4">
                        <span className="text-3xl leading-none" aria-hidden>
                            {flag}
                        </span>
                        <div className="flex-1">
                            <p className="text-sm font-medium">{label}</p>
                            <p className="text-xs text-muted-foreground">
                                {english}{' '}
                                <span className="tracking-widest uppercase">
                                    · {locale}
                                </span>
                            </p>
                        </div>
                        <Button
                            type="button"
                            onClick={() => setPickerOpen(true)}
                            variant="outline"
                        >
                            {t('Change language')}
                        </Button>
                    </div>

                    <p className="mt-4 flex items-center gap-2 text-xs text-muted-foreground">
                        <Globe className="h-3.5 w-3.5" />
                        {t('Applies immediately. Affects only your account.')}
                    </p>
                </Card>
            </div>

            <LocalePickerDialog
                open={pickerOpen}
                onOpenChange={setPickerOpen}
                locale={locale}
                supported={supported}
                catalog={catalog}
                onPick={switchTo}
            />
        </>
    );
}

Locale.layout = {
    breadcrumbs: [
        {
            title: 'Language',
            href: edit(),
        },
    ],
};
