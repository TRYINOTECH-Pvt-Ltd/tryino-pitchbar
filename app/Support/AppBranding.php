<?php

namespace App\Support;

use App\Models\AppSetting;
use Illuminate\Support\Facades\Storage;

class AppBranding
{
    /**
     * @var array<int, string>
     */
    private const DISPLAY_MODES = ['logo_text', 'logo_only', 'text_only'];

    public static function disk(): string
    {
        $defaultDisk = (string) config('filesystems.default', 'public');

        return $defaultDisk === 'local' || $defaultDisk === ''
            ? 'public'
            : $defaultDisk;
    }

    public static function siteTitle(): string
    {
        return self::resolveSiteTitle(self::settings());
    }

    /**
     * @return array<string, string|null>
     */
    public static function shared(): array
    {
        $settings = self::settings();
        $headerLogoUrl = self::assetUrl(self::resolveAssetPath($settings, 'header_logo_path', 'branding.header_logo_path'));
        $footerLogoUrl = self::assetUrl(self::resolveAssetPath($settings, 'footer_logo_path', 'branding.footer_logo_path'));
        $dashboardLogoUrl = self::assetUrl(self::resolveAssetPath($settings, 'dashboard_logo_path', 'branding.dashboard_logo_path'));
        // Dark-mode variants — optional, only persisted when the
        // operator uploads them in Settings → Branding. Surfaces drive
        // their dark <img> off these via Tailwind dark: utilities.
        $headerLogoDarkUrl = self::assetUrl(self::resolveAssetPath($settings, 'header_logo_dark_path', 'branding.header_logo_dark_path'));
        $footerLogoDarkUrl = self::assetUrl(self::resolveAssetPath($settings, 'footer_logo_dark_path', 'branding.footer_logo_dark_path'));
        $dashboardLogoDarkUrl = self::assetUrl(self::resolveAssetPath($settings, 'dashboard_logo_dark_path', 'branding.dashboard_logo_dark_path'));
        $faviconUrl = self::assetUrl(self::resolveAssetPath($settings, 'favicon_path', 'branding.favicon_path'));

        return [
            'site_title' => self::resolveSiteTitle($settings),
            'header_logo_url' => $headerLogoUrl,
            'footer_logo_url' => $footerLogoUrl,
            'dashboard_logo_url' => $dashboardLogoUrl,
            'header_logo_dark_url' => $headerLogoDarkUrl,
            'footer_logo_dark_url' => $footerLogoDarkUrl,
            'dashboard_logo_dark_url' => $dashboardLogoDarkUrl,
            'favicon_url' => $faviconUrl,
            'header_brand_display' => self::resolveDisplayMode($settings?->header_brand_display, $headerLogoUrl !== null),
            'footer_brand_display' => self::resolveDisplayMode($settings?->footer_brand_display, ($footerLogoUrl ?? $headerLogoUrl) !== null),
            'dashboard_brand_display' => self::resolveDisplayMode($settings?->dashboard_brand_display, $dashboardLogoUrl !== null),
            'widget_brand_url' => self::resolveString($settings?->pitchbar_brand_url, (string) config('branding.url')),
            'widget_brand_label' => self::resolveString($settings?->pitchbar_brand_label, (string) config('branding.label')),
            // Public marketing-site kill switch (Settings → Branding).
            // Default true so existing installs are unaffected.
            'marketing_site_enabled' => $settings?->marketing_site_enabled ?? true,
            // Admin-editable side-panel copy for auth pages (Aurora /
            // Prism themes). NULL on any field = theme falls back to
            // its bundled default so first-install installs stay
            // pre-styled.
            'auth_aside_eyebrow' => self::nullableString($settings?->auth_aside_eyebrow),
            'auth_aside_heading' => self::nullableString($settings?->auth_aside_heading),
            'auth_aside_lede' => self::nullableString($settings?->auth_aside_lede),
            'auth_aside_bullets' => is_array($settings?->auth_aside_bullets ?? null)
                ? array_values(array_filter(
                    array_map(static fn ($v) => is_string($v) ? trim($v) : '', $settings->auth_aside_bullets),
                    static fn (string $v): bool => $v !== '',
                ))
                : null,
        ];
    }

    private static function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    public static function assetUrl(mixed $path): ?string
    {
        if (! is_string($path) || trim($path) === '') {
            return null;
        }

        return Storage::disk(self::disk())->url($path);
    }

    private static function settings(): ?AppSetting
    {
        try {
            return AppSetting::query()->find(AppSetting::SINGLETON_ID);
        } catch (\Throwable) {
            return null;
        }
    }

    private static function resolveSiteTitle(?AppSetting $settings): string
    {
        $siteTitle = self::resolveString(
            $settings?->site_title,
            (string) config('branding.site_title', ''),
        );

        return $siteTitle !== ''
            ? $siteTitle
            : (trim((string) config('app.name', 'Pitchbar')) ?: 'Pitchbar');
    }

    private static function resolveAssetPath(
        ?AppSetting $settings,
        string $column,
        string $configKey,
    ): mixed {
        $path = $settings?->{$column};

        if (is_string($path) && trim($path) !== '') {
            return $path;
        }

        return config($configKey);
    }

    private static function resolveString(?string $settingValue, string $fallback): string
    {
        $resolved = trim((string) $settingValue);

        if ($resolved !== '') {
            return $resolved;
        }

        return trim($fallback);
    }

    private static function resolveDisplayMode(?string $settingValue, bool $hasLogo): string
    {
        $resolved = trim((string) $settingValue);

        if (in_array($resolved, self::DISPLAY_MODES, true)) {
            return $resolved;
        }

        return $hasLogo ? 'logo_only' : 'logo_text';
    }
}
