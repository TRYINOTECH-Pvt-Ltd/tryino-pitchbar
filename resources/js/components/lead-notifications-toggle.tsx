import { Bell, BellOff, BellRing } from 'lucide-react';
import { useEffect, useState } from 'react';
import { toast } from 'sonner';
import { Button } from '@/components/ui/button';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { requestLeadNotificationsPermission } from '@/hooks/use-lead-toasts';
import { useT } from '@/lib/i18n';

type Permission = NotificationPermission | 'unsupported';

function readPermission(): Permission {
    if (typeof window === 'undefined' || !('Notification' in window)) {
        return 'unsupported';
    }

    return Notification.permission;
}

/**
 * Small header pill that lets the workspace member opt-in to browser
 * notifications for new leads. The Notification API requires a user
 * gesture to prompt, so we can't auto-request on page-load — this
 * button is the explicit gesture.
 *
 * Three states: unsupported (hidden), default (Bell w/ off), granted
 * (BellRing), denied (BellOff). Denied state shows a tooltip telling
 * the admin to flip it from the browser settings — we cannot
 * re-prompt once they've blocked.
 */
export function LeadNotificationsToggle() {
    const { t } = useT();
    const [permission, setPermission] = useState<Permission>(() =>
        readPermission(),
    );

    useEffect(() => {
        // Notification.permission can change underneath us if the user
        // toggles it from browser settings; recheck on focus.
        const onFocus = () => setPermission(readPermission());
        window.addEventListener('focus', onFocus);

        return () => window.removeEventListener('focus', onFocus);
    }, []);

    if (permission === 'unsupported') {
        return null;
    }

    const handleClick = async () => {
        if (permission === 'granted') {
            toast.message(t('Browser alerts already enabled'));

            return;
        }

        if (permission === 'denied') {
            toast.error(
                t(
                    'Browser alerts are blocked. Enable them from your browser settings to get pinged on new leads.',
                ),
            );

            return;
        }

        const next = await requestLeadNotificationsPermission();
        setPermission(next);

        if (next === 'granted') {
            toast.success(t('Browser alerts on — new leads will ping you.'));
        }
    };

    const Icon =
        permission === 'granted'
            ? BellRing
            : permission === 'denied'
              ? BellOff
              : Bell;

    const tip =
        permission === 'granted'
            ? t('Lead alerts are on')
            : permission === 'denied'
              ? t('Lead alerts blocked — enable in browser settings')
              : t('Enable browser alerts for new leads');

    return (
        <Tooltip>
            <TooltipTrigger asChild>
                <Button
                    type="button"
                    variant="outline"
                    size="icon"
                    onClick={handleClick}
                    aria-label={tip}
                    className="size-9 rounded-xl border-border/70 bg-background/80 shadow-xs"
                >
                    <Icon
                        className={`size-4 ${
                            permission === 'granted'
                                ? 'text-emerald-600'
                                : permission === 'denied'
                                  ? 'text-muted-foreground/60'
                                  : ''
                        }`}
                    />
                    <span className="sr-only">{tip}</span>
                </Button>
            </TooltipTrigger>
            <TooltipContent side="bottom">{tip}</TooltipContent>
        </Tooltip>
    );
}
