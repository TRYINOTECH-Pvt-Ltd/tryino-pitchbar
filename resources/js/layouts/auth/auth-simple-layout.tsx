import { Link } from '@inertiajs/react';
import {
    LockKeyhole,
    MessageSquare,
    ShieldCheck,
    Sparkles,
} from 'lucide-react';
import AppLogoIcon from '@/components/app-logo-icon';
import BrandLockup from '@/components/brand-lockup';
import { DirArrow } from '@/components/dir-icon';
import { useBranding } from '@/hooks/use-branding';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { home } from '@/routes';
import type { AuthLayoutProps } from '@/types';

export default function AuthSimpleLayout({
    children,
    name,
    title,
    description,
}: AuthLayoutProps) {
    const branding = useBranding();
    const { t } = useT();
    const brandName = branding.site_title || name || 'Pitchbar';
    const headerLogoUrl = branding.header_logo_url;
    const highlights = [
        {
            icon: MessageSquare,
            eyebrow: t('Story'),
            title: t('Stay connected'),
            description: t(
                'Pick up the same thoughtful product story visitors saw on the landing page.',
            ),
            note: t('Same tone from first click to sign in'),
            chips: [t('Landing-aligned'), t('Lower friction')],
            badge: t('Journey'),
            surfaceClass:
                'bg-[linear-gradient(160deg,#fbf8ef_0%,#f3eee2_100%)]',
            iconClass: 'border-[#e3dccb] bg-[#f6f1e7] text-[#214a36]',
            badgeClass: 'border-[#ddd4c4] bg-white/75 text-[#6f7469]',
        },
        {
            icon: ShieldCheck,
            eyebrow: t('Fortify'),
            title: t('Secure access'),
            description: t(
                'Passwords, verification, resets, and 2FA keep the full Fortify flow intact.',
            ),
            note: t('Everything stays protected behind the same trusted flow'),
            chips: [t('2FA ready'), t('Reset flow')],
            badge: t('Intact'),
            surfaceClass:
                'bg-[linear-gradient(160deg,#eef2e8_0%,#f8f5ed_100%)]',
            iconClass: 'border-[#d7e0d0] bg-[#f3f7ee] text-[#214a36]',
            badgeClass: 'border-[#d7dfd0] bg-white/70 text-[#5e6659]',
        },
    ];

    return (
        <div className="min-h-svh bg-[#f7f2e8] text-[#252a24] lg:grid lg:grid-cols-[1.04fr_0.96fr]">
            <aside className="relative hidden overflow-hidden border-e border-[#e6decf] lg:flex">
                <div className="marketing-grid-background absolute inset-0 opacity-25" />
                <div className="absolute start-[18%] top-[-4rem] size-72 rounded-full bg-[#dde6d1]/80 blur-3xl" />
                <div className="absolute end-[10%] bottom-[-5rem] size-80 rounded-full bg-[#f0eadf]/95 blur-3xl" />

                <div className="relative mx-auto flex min-h-svh w-full max-w-[620px] flex-col px-10 py-10 xl:px-14 xl:py-12">
                    <Link href={home()} className="flex items-center gap-2.5">
                        <BrandLockup
                            siteTitle={brandName}
                            logoUrl={headerLogoUrl}
                            mode={branding.header_brand_display}
                            className="gap-2.5"
                            logoClassName="h-10 max-w-[220px]"
                            textClassName="text-2xl font-semibold tracking-normal text-[#263129]"
                            fallbackLogo={
                                <AppLogoIcon className="size-8 text-[#173f2c]" />
                            }
                        />
                    </Link>

                    <div className="mt-16 flex flex-1 flex-col justify-between">
                        <div>
                            <span className="inline-flex items-center gap-2 rounded-md border border-[#e2ddcf] bg-[#eff1e8] px-3 py-1 text-[10px] font-bold text-[#7d826f] uppercase">
                                <LockKeyhole className="size-3.5" />
                                {t('Secure workspace')}
                            </span>

                            <h1 className="marketing-display mt-6 max-w-[500px] text-[4rem] leading-[0.92] text-[#252621]">
                                {t('Welcome back to the conversations.')}
                            </h1>
                            <p className="mt-5 max-w-[500px] text-base leading-7 font-medium text-[#666b62]">
                                {t(
                                    description ||
                                        'Access your workspace with the same calm, conversion-focused experience as the public site.',
                                )}
                            </p>

                            <div className="mt-10 grid max-w-[560px] gap-4 xl:grid-cols-2">
                                {highlights.map((item, index) => {
                                    const Icon = item.icon;

                                    return (
                                        <div
                                            key={item.title}
                                            className={cn(
                                                'group relative overflow-hidden rounded-[28px] border border-[#d9d2c5] p-5 shadow-[0_18px_48px_rgba(48,43,34,0.055)] transition duration-200 xl:last:translate-y-6',
                                                item.surfaceClass,
                                                index === 0 &&
                                                    'xl:rotate-[-1deg]',
                                                index === 1 &&
                                                    'xl:rotate-[1deg]',
                                            )}
                                        >
                                            <div className="absolute inset-x-6 top-0 h-px bg-[linear-gradient(90deg,transparent,#b7c5ad,transparent)]" />

                                            <div className="flex items-start justify-between gap-4">
                                                <div className="min-w-0">
                                                    <span className="inline-flex rounded-full border border-[#ddd6c8] bg-white/65 px-3 py-1 text-[10px] font-bold tracking-[0.18em] text-[#7c816f] uppercase">
                                                        {item.eyebrow}
                                                    </span>

                                                    <div className="mt-4 flex items-start gap-3.5">
                                                        <span
                                                            className={cn(
                                                                'mt-0.5 flex size-11 shrink-0 items-center justify-center rounded-2xl border',
                                                                item.iconClass,
                                                            )}
                                                        >
                                                            <Icon className="size-4.5" />
                                                        </span>

                                                        <h2 className="text-[1.75rem] leading-[1.1] font-semibold text-[#2b322c]">
                                                            {item.title}
                                                        </h2>
                                                    </div>
                                                </div>

                                                <span
                                                    className={cn(
                                                        'inline-flex rounded-full border px-3 py-1 text-[10px] font-bold tracking-[0.18em] uppercase',
                                                        item.badgeClass,
                                                    )}
                                                >
                                                    {item.badge}
                                                </span>
                                            </div>

                                            <p className="mt-5 max-w-[320px] text-[14px] leading-6 font-medium text-[#676d64]">
                                                {item.description}
                                            </p>

                                            <div className="mt-6 flex flex-wrap gap-2">
                                                {item.chips.map((chip) => (
                                                    <span
                                                        key={chip}
                                                        className="rounded-full border border-[#ddd5c6] bg-white/70 px-3 py-1 text-[11px] font-semibold text-[#5f665d]"
                                                    >
                                                        {chip}
                                                    </span>
                                                ))}
                                            </div>

                                            <div className="mt-6 flex items-center gap-2 text-[12px] font-semibold text-[#315f42]">
                                                <span className="size-2 rounded-full bg-current" />
                                                {item.note}
                                            </div>
                                        </div>
                                    );
                                })}
                            </div>
                        </div>

                        <Link
                            href={home()}
                            className="mt-10 inline-flex items-center gap-2 text-sm font-semibold text-[#315f42] transition hover:text-[#173f2c]"
                        >
                            <DirArrow direction="back" className="size-4" />
                            {t('Back to site')}
                        </Link>
                    </div>
                </div>
            </aside>

            <main className="flex min-h-svh flex-col">
                <header className="flex items-center justify-between px-6 py-6 lg:hidden">
                    <Link href={home()} className="flex items-center gap-2.5">
                        <BrandLockup
                            siteTitle={brandName}
                            logoUrl={headerLogoUrl}
                            mode={branding.header_brand_display}
                            className="gap-2.5"
                            logoClassName="h-9 max-w-[180px]"
                            textClassName="text-xl font-semibold tracking-normal text-[#263129]"
                            fallbackLogo={
                                <AppLogoIcon className="size-7 text-[#173f2c]" />
                            }
                        />
                    </Link>

                    <Link
                        href={home()}
                        className="inline-flex items-center gap-2 text-sm font-semibold text-[#315f42] transition hover:text-[#173f2c]"
                    >
                        <DirArrow direction="back" className="size-4" />
                        {t('Site')}
                    </Link>
                </header>

                <section className="flex flex-1 items-center justify-center px-6 pb-10 lg:px-10 lg:py-10">
                    <div className="w-full max-w-[520px]">
                        <div className="overflow-hidden rounded-[28px] border border-[#ddd5c7] bg-[#fbf8ef] shadow-[0_24px_80px_rgba(29,37,28,0.08)]">
                            <div className="border-b border-[#e7dfd2] bg-[linear-gradient(180deg,#f8f4eb_0%,#fbf8ef_100%)] px-7 py-8 sm:px-8">
                                <span className="inline-flex items-center gap-2 rounded-md border border-[#e2ddcf] bg-[#eff1e8] px-3 py-1 text-[10px] font-bold text-[#7d826f] uppercase">
                                    <Sparkles className="size-3.5" />
                                    {t('Account access')}
                                </span>
                                <h1 className="marketing-display mt-5 text-4xl leading-none text-[#252621] sm:text-[2.65rem]">
                                    {t(title ?? '')}
                                </h1>
                                {description && (
                                    <p className="mt-4 max-w-[430px] text-sm leading-6 font-medium text-[#686d64]">
                                        {t(description)}
                                    </p>
                                )}
                            </div>

                            <div className="px-7 py-8 sm:px-8">{children}</div>
                        </div>
                    </div>
                </section>
            </main>
        </div>
    );
}
