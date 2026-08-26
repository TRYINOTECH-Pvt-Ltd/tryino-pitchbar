import { Link, router, usePage } from '@inertiajs/react';
import { LogOut, Settings, ShieldOff } from 'lucide-react';
import {
    DropdownMenuGroup,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
} from '@/components/ui/dropdown-menu';
import { UserInfo } from '@/components/user-info';
import { useMobileNavigation } from '@/hooks/use-mobile-navigation';
import { useT } from '@/lib/i18n';
import { logout } from '@/routes';
import { stop as impersonateStop } from '@/routes/impersonate';
import { edit } from '@/routes/profile';
import type { User } from '@/types';

type Props = {
    user: User;
};

export function UserMenuContent({ user }: Props) {
    const cleanup = useMobileNavigation();
    const { t } = useT();
    const { impersonating } = usePage<{
        impersonating: { id: number; name: string; email: string } | null;
    }>().props;

    const handleLogout = () => {
        cleanup();
        router.flushAll();
    };

    const handleStopImpersonating = () => {
        cleanup();
        router.post(impersonateStop.url());
    };

    return (
        <>
            <DropdownMenuLabel className="p-0 font-normal">
                <div className="flex items-center gap-2 px-1 py-1.5 text-start text-sm">
                    <UserInfo user={user} showEmail={true} />
                </div>
            </DropdownMenuLabel>
            <DropdownMenuSeparator />
            <DropdownMenuGroup>
                <DropdownMenuItem asChild>
                    <Link
                        className="block w-full cursor-pointer"
                        href={edit()}
                        prefetch
                        onClick={cleanup}
                    >
                        <Settings className="me-2" />
                        {t('Settings')}
                    </Link>
                </DropdownMenuItem>
            </DropdownMenuGroup>
            {impersonating && (
                <>
                    <DropdownMenuSeparator />
                    <DropdownMenuItem
                        onSelect={(e) => {
                            e.preventDefault();
                            handleStopImpersonating();
                        }}
                        className="text-amber-700 dark:text-amber-300"
                        data-test="stop-impersonating-button"
                    >
                        <ShieldOff className="me-2" />
                        {t('Switch back to :name', {
                            name: impersonating.name,
                        })}
                    </DropdownMenuItem>
                </>
            )}
            <DropdownMenuSeparator />
            <DropdownMenuItem asChild>
                <Link
                    className="block w-full cursor-pointer"
                    href={logout()}
                    as="button"
                    onClick={handleLogout}
                    data-test="logout-button"
                >
                    <LogOut className="me-2" />
                    {t('Log out')}
                </Link>
            </DropdownMenuItem>
        </>
    );
}
