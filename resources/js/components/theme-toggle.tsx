import { Monitor, Moon, Sun } from 'lucide-react';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { useAppearance } from '@/hooks/use-appearance';
import type { Appearance } from '@/hooks/use-appearance';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';

/**
 * Header icon button + dropdown for switching the theme. Shows a Sun
 * when the resolved appearance is light and a Moon when dark; the
 * dropdown lets the user pick System / Light / Dark explicitly so the
 * "follows OS" preference stays accessible.
 *
 * Uses the existing `useAppearance` hook so the choice persists in
 * cookie + localStorage and applies to every Inertia page.
 */
export function ThemeToggle({ className }: { className?: string }) {
    const { t } = useT();
    const { appearance, resolvedAppearance, updateAppearance } =
        useAppearance();
    const ResolvedIcon = resolvedAppearance === 'dark' ? Moon : Sun;
    const options: Array<{
        value: Appearance;
        label: string;
        icon: typeof Sun;
    }> = [
        { value: 'system', label: t('System'), icon: Monitor },
        { value: 'light', label: t('Light'), icon: Sun },
        { value: 'dark', label: t('Dark'), icon: Moon },
    ];
    const appearanceLabel =
        appearance === 'system'
            ? t('System')
            : appearance === 'dark'
              ? t('Dark')
              : t('Light');
    const label = t('Theme: :name', { name: appearanceLabel });

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button
                    type="button"
                    variant="outline"
                    size="icon"
                    aria-label={label}
                    className={cn(
                        'size-9 rounded-xl border-border/70 bg-background/80 shadow-xs',
                        className,
                    )}
                >
                    <ResolvedIcon className="size-4" />
                    <span className="sr-only">{label}</span>
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="w-40">
                <DropdownMenuLabel className="text-xs font-medium text-muted-foreground">
                    {t('Appearance')}
                </DropdownMenuLabel>
                <DropdownMenuSeparator />
                {options.map((opt) => {
                    const Icon = opt.icon;
                    const active = appearance === opt.value;

                    return (
                        <DropdownMenuItem
                            key={opt.value}
                            onSelect={() => updateAppearance(opt.value)}
                            className={cn(
                                'flex items-center gap-2 text-sm',
                                active && 'font-medium',
                            )}
                        >
                            <Icon className="size-4" />
                            <span className="flex-1">{opt.label}</span>
                            {active && (
                                <span
                                    aria-hidden="true"
                                    className="size-1.5 rounded-full bg-emerald-500"
                                />
                            )}
                        </DropdownMenuItem>
                    );
                })}
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
