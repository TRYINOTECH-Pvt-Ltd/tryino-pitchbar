import { usePage } from '@inertiajs/react';
import type { Branding } from '@/types/branding';

export function useBranding(): Branding {
    return usePage<{ branding: Branding }>().props.branding;
}
