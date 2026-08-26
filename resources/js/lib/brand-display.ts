export type BrandDisplayMode = 'logo_text' | 'logo_only' | 'text_only';

export const BRAND_DISPLAY_OPTIONS: Array<{
    value: BrandDisplayMode;
    label: string;
    description: string;
}> = [
    {
        value: 'logo_text',
        label: 'Logo + text',
        description: 'Show the uploaded logo together with the brand name.',
    },
    {
        value: 'logo_only',
        label: 'Only logo',
        description: 'Show the uploaded logo only.',
    },
    {
        value: 'text_only',
        label: 'Only text',
        description: 'Hide the logo and show the brand name only.',
    },
];

export function getBrandDisplayParts(mode: BrandDisplayMode): {
    showLogo: boolean;
    showText: boolean;
} {
    switch (mode) {
        case 'logo_only':
            return { showLogo: true, showText: false };
        case 'text_only':
            return { showLogo: false, showText: true };
        default:
            return { showLogo: true, showText: true };
    }
}
