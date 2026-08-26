import type { ReactNode } from 'react';
import AppLogoIcon from '@/components/app-logo-icon';
import { getBrandDisplayParts } from '@/lib/brand-display';
import type { BrandDisplayMode } from '@/lib/brand-display';
import { cn } from '@/lib/utils';

type Props = {
    siteTitle: string;
    logoUrl?: string | null;
    logoDarkUrl?: string | null;
    mode: BrandDisplayMode;
    className?: string;
    logoClassName?: string;
    textClassName?: string;
    text?: ReactNode;
    fallbackLogo?: ReactNode;
    alt?: string;
};

export default function BrandLockup({
    siteTitle,
    logoUrl,
    logoDarkUrl,
    mode,
    className,
    logoClassName,
    textClassName,
    text,
    fallbackLogo,
    alt,
}: Props) {
    const { showLogo, showText } = getBrandDisplayParts(mode);
    const altText = showText ? (alt ?? `${siteTitle} logo`) : '';
    const imgClass = cn(
        'h-10 w-auto max-w-[220px] object-contain',
        logoClassName,
    );

    return (
        <span
            className={cn(
                'inline-flex min-w-0 items-center gap-2.5',
                className,
            )}
        >
            {showLogo ? (
                logoUrl ? (
                    logoDarkUrl ? (
                        <>
                            <img
                                src={logoUrl}
                                alt={altText}
                                className={cn(imgClass, 'dark:hidden')}
                            />
                            <img
                                src={logoDarkUrl}
                                alt={altText}
                                className={cn(imgClass, 'hidden dark:block')}
                            />
                        </>
                    ) : (
                        <img src={logoUrl} alt={altText} className={imgClass} />
                    )
                ) : (
                    (fallbackLogo ?? (
                        <span className="flex size-8 items-center justify-center text-current">
                            <AppLogoIcon className="size-full" />
                        </span>
                    ))
                )
            ) : null}

            {showText
                ? (text ?? (
                      <span className={cn('min-w-0', textClassName)}>
                          {siteTitle}
                      </span>
                  ))
                : null}

            {showLogo && !showText ? (
                <span className="sr-only">{siteTitle}</span>
            ) : null}
        </span>
    );
}
