import { router } from '@inertiajs/react';
import { useEffect } from 'react';
import { toast } from 'sonner';
import type { FlashToast } from '@/types/ui';

type InertiaVisit = {
    method?: string;
    prefetch?: boolean;
    cancelled?: boolean;
    interrupted?: boolean;
};

type InertiaPageWithFlash = {
    flash?: {
        toast?: FlashToast;
    };
};

type PendingSubmitToast = {
    timerId: number | null;
    toastId: number | string | null;
    hasTerminalToast: boolean;
    hasFlashToast: boolean;
};

const LOADING_TOAST_DELAY_MS = 350;
const mutatingMethods = new Set(['post', 'put', 'patch', 'delete']);

function isMutatingVisit(
    visit: InertiaVisit | null | undefined,
): visit is InertiaVisit {
    return (
        !!visit &&
        !visit.prefetch &&
        mutatingMethods.has((visit.method ?? '').toLowerCase())
    );
}

function loadingMessageForVisit(visit: InertiaVisit): string {
    switch ((visit.method ?? '').toLowerCase()) {
        case 'delete':
            return 'Deleting...';
        case 'put':
        case 'patch':
            return 'Saving...';
        default:
            return 'Submitting...';
    }
}

function successMessageForVisit(visit: InertiaVisit): string {
    switch ((visit.method ?? '').toLowerCase()) {
        case 'delete':
            return 'Deleted successfully.';
        case 'put':
        case 'patch':
            return 'Saved successfully.';
        default:
            return 'Submitted successfully.';
    }
}

function showFlashToast(data: FlashToast): void {
    toast[data.type](data.message);
}

export function useFlashToast(): void {
    useEffect(() => {
        let activeVisit: InertiaVisit | null = null;
        const pendingToasts = new Map<InertiaVisit, PendingSubmitToast>();

        const getPendingToast = (visit: InertiaVisit): PendingSubmitToast => {
            const existing = pendingToasts.get(visit);

            if (existing) {
                return existing;
            }

            const next: PendingSubmitToast = {
                timerId: null,
                toastId: null,
                hasTerminalToast: false,
                hasFlashToast: false,
            };

            pendingToasts.set(visit, next);

            return next;
        };

        const showTerminalToast = (
            visit: InertiaVisit,
            type: FlashToast['type'],
            message: string,
        ): void => {
            const pending = getPendingToast(visit);

            if (pending.timerId !== null) {
                window.clearTimeout(pending.timerId);
                pending.timerId = null;
            }

            const options =
                pending.toastId === null ? undefined : { id: pending.toastId };
            const toastId = toast[type](message, options);

            pending.toastId = toastId;
            pending.hasTerminalToast = true;
        };

        const cleanupVisit = (visit: InertiaVisit): void => {
            const pending = pendingToasts.get(visit);

            if (!pending) {
                return;
            }

            if (pending.timerId !== null) {
                window.clearTimeout(pending.timerId);
            }

            if (pending.toastId !== null && !pending.hasTerminalToast) {
                toast.dismiss(pending.toastId);
            }

            pendingToasts.delete(visit);
        };

        const removeStartListener = router.on('start', (event) => {
            const visit = (event as CustomEvent<{ visit?: InertiaVisit }>)
                .detail?.visit;

            if (!isMutatingVisit(visit)) {
                return;
            }

            activeVisit = visit;

            const pending = getPendingToast(visit);

            pending.timerId = window.setTimeout(() => {
                pending.toastId = toast.loading(loadingMessageForVisit(visit));
                pending.timerId = null;
            }, LOADING_TOAST_DELAY_MS);
        });

        const removeFlashListener = router.on('flash', (event) => {
            const flash = (
                event as CustomEvent<{ flash?: { toast?: FlashToast } }>
            ).detail?.flash;
            const data = flash?.toast;

            if (!data) {
                return;
            }

            if (isMutatingVisit(activeVisit)) {
                getPendingToast(activeVisit).hasFlashToast = true;
            }

            showFlashToast(data);
        });

        const removeErrorListener = router.on('error', () => {
            if (!isMutatingVisit(activeVisit)) {
                return;
            }

            showTerminalToast(
                activeVisit,
                'error',
                'Please review the highlighted fields and try again.',
            );
        });

        const removeNetworkErrorListener = router.on('networkError', () => {
            if (!isMutatingVisit(activeVisit)) {
                return;
            }

            showTerminalToast(
                activeVisit,
                'error',
                'Network error. Please try again.',
            );
        });

        const removeHttpExceptionListener = router.on('httpException', () => {
            if (!isMutatingVisit(activeVisit)) {
                return;
            }

            showTerminalToast(
                activeVisit,
                'error',
                'Something went wrong. Please try again.',
            );
        });

        const removeSuccessListener = router.on('success', (event) => {
            if (!isMutatingVisit(activeVisit)) {
                return;
            }

            const page = (event as CustomEvent<{ page?: InertiaPageWithFlash }>)
                .detail?.page;
            const pending = getPendingToast(activeVisit);

            if (page?.flash?.toast || pending.hasFlashToast) {
                return;
            }

            showTerminalToast(
                activeVisit,
                'success',
                successMessageForVisit(activeVisit),
            );
        });

        const removeFinishListener = router.on('finish', (event) => {
            const visit = (event as CustomEvent<{ visit?: InertiaVisit }>)
                .detail?.visit;

            if (!isMutatingVisit(visit)) {
                return;
            }

            cleanupVisit(visit);

            if (activeVisit === visit) {
                activeVisit = null;
            }
        });

        return () => {
            removeStartListener();
            removeFlashListener();
            removeErrorListener();
            removeNetworkErrorListener();
            removeHttpExceptionListener();
            removeSuccessListener();
            removeFinishListener();
        };
    }, []);
}
