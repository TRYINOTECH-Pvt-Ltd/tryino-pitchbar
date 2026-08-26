import type { Auth } from '@/types/auth';
import type { Branding } from '@/types/branding';

declare global {
    interface Window {
        __PITCHBAR_SITE_TITLE__?: string;
        __PITCHBAR_FAVICON_URL__?: string;
        __PITCHBAR_DEFAULT_FAVICON_URL__?: string;
        __PITCHBAR_DEFAULT_TOUCH_ICON_URL__?: string;
    }
}

declare module '@inertiajs/core' {
    export interface InertiaConfig {
        sharedPageProps: {
            name: string;
            branding: Branding;
            auth: Auth;
            sidebarOpen: boolean;
            [key: string]: unknown;
        };
    }
}

export {};
