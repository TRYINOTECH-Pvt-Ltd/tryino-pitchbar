import type { BrandDisplayMode } from '@/lib/brand-display';

export type Branding = {
    site_title: string;
    header_logo_url: string | null;
    footer_logo_url: string | null;
    dashboard_logo_url: string | null;
    // Dark-mode variants. Null when the operator hasn't uploaded a
    // separate dark logo; surfaces fall back to the light logo in that
    // case (works fine for transparent or duotone wordmarks).
    header_logo_dark_url: string | null;
    footer_logo_dark_url: string | null;
    dashboard_logo_dark_url: string | null;
    favicon_url: string | null;
    header_brand_display: BrandDisplayMode;
    footer_brand_display: BrandDisplayMode;
    dashboard_brand_display: BrandDisplayMode;
    widget_brand_url: string;
    widget_brand_label: string;
    // Optional flag for surfaces that need to know whether the
    // public-facing marketing site is enabled (defaults to true).
    marketing_site_enabled?: boolean;
    // Admin-editable copy for the auth-page side panel (Aurora /
    // Prism). Null on any field = theme falls back to its bundled
    // default so a fresh install is still pre-styled.
    auth_aside_eyebrow?: string | null;
    auth_aside_heading?: string | null;
    auth_aside_lede?: string | null;
    auth_aside_bullets?: string[] | null;
};
