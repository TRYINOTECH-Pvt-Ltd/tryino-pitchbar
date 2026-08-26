import AppLogoIcon from '@/components/app-logo-icon';
import BrandLockup from '@/components/brand-lockup';
import { useBranding } from '@/hooks/use-branding';
import { getBrandDisplayParts } from '@/lib/brand-display';
import { cn } from '@/lib/utils';

export default function AppLogo() {
    const branding = useBranding();
    const mode = branding.dashboard_brand_display;
    const { showLogo, showText } = getBrandDisplayParts(mode);

    return (
        <BrandLockup
            siteTitle={branding.site_title}
            logoUrl={branding.dashboard_logo_url}
            logoDarkUrl={branding.dashboard_logo_dark_url}
            mode={mode}
            className={cn(
                'h-8 max-w-full items-center',
                showLogo && showText ? 'gap-2.5' : 'gap-0',
            )}
            logoClassName={cn(
                'h-8 w-auto shrink-0 object-contain',
                showText ? 'max-w-[180px]' : 'max-w-[220px]',
            )}
            textClassName="block min-w-0 truncate text-start text-sm font-semibold leading-none"
            fallbackLogo={
                <div className="flex aspect-square size-8 items-center justify-center rounded-md bg-foreground text-background">
                    <AppLogoIcon className="size-4" />
                </div>
            }
        />
    );
}
