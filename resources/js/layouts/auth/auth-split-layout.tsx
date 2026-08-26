import { Link } from '@inertiajs/react';
import AppLogoIcon from '@/components/app-logo-icon';
import BrandLockup from '@/components/brand-lockup';
import { useBranding } from '@/hooks/use-branding';
import { home } from '@/routes';
import type { AuthLayoutProps } from '@/types';

export default function AuthSplitLayout({
    children,
    title,
    description,
}: AuthLayoutProps) {
    const branding = useBranding();
    const brandName = branding.site_title;
    const headerLogoUrl = branding.header_logo_url;

    return (
        <div className="relative grid h-dvh flex-col items-center justify-center px-8 sm:px-0 lg:max-w-none lg:grid-cols-2 lg:px-0">
            <div className="relative hidden h-full flex-col bg-muted p-10 text-white lg:flex dark:border-e">
                <div className="absolute inset-0 bg-zinc-900" />
                <Link
                    href={home()}
                    className="relative z-20 flex items-center text-lg font-medium"
                >
                    <BrandLockup
                        siteTitle={brandName}
                        logoUrl={headerLogoUrl}
                        mode={branding.header_brand_display}
                        className="gap-2"
                        logoClassName="h-10 max-w-[220px]"
                        textClassName="text-lg font-medium text-white"
                        fallbackLogo={
                            <AppLogoIcon className="me-2 size-8 fill-current text-white" />
                        }
                    />
                </Link>
            </div>
            <div className="w-full lg:p-8">
                <div className="mx-auto flex w-full flex-col justify-center space-y-6 sm:w-[350px]">
                    <Link
                        href={home()}
                        className="relative z-20 flex items-center justify-center lg:hidden"
                    >
                        <BrandLockup
                            siteTitle={brandName}
                            logoUrl={headerLogoUrl}
                            mode={branding.header_brand_display}
                            className="gap-2"
                            logoClassName="h-10 max-w-[180px] sm:h-12"
                            textClassName="text-lg font-semibold text-black"
                            fallbackLogo={
                                <AppLogoIcon className="h-10 fill-current text-black sm:h-12" />
                            }
                        />
                    </Link>
                    <div className="flex flex-col items-start gap-2 text-start sm:items-center sm:text-center">
                        <h1 className="text-xl font-medium">{title}</h1>
                        <p className="text-sm text-balance text-muted-foreground">
                            {description}
                        </p>
                    </div>
                    {children}
                </div>
            </div>
        </div>
    );
}
