<?php

namespace App\Http\Controllers;

use App\Support\AppBranding;
use Illuminate\Http\JsonResponse;

/**
 * Serves the dynamic Web App Manifest for the admin shell.
 *
 * The name, short_name, and theme_color flow from the white-label
 * branding cascade so a re-skinned install never ships "Pitchbar" to
 * install prompts. Icons resolve to the operator's PWA icons if they
 * uploaded any, else fall back to the shipped placeholders under
 * /icons/.
 */
class PwaManifestController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $branding = AppBranding::shared();

        $name = (string) ($branding['site_title'] ?? config('app.name', 'Pitchbar'));
        $shortName = mb_strlen($name) > 12 ? mb_substr($name, 0, 12) : $name;

        $manifest = [
            'name' => $name,
            'short_name' => $shortName,
            'description' => 'Sales-AI chat widget — admin console.',
            // /dashboard is the canonical landing — Fortify redirects
            // unauthed installers to /login, the controller handles
            // no-workspace via an empty state, and authed admins see
            // the Inertia dashboard. The previous /app target had no
            // bare route and 404'd on every install (client report
            // 2026-05-25).
            'start_url' => '/dashboard',
            'scope' => '/',
            'display' => 'standalone',
            'theme_color' => '#0f172a',
            'background_color' => '#ffffff',
            'orientation' => 'any',
            'icons' => $this->iconsFor($branding),
        ];

        return response()->json($manifest, 200, [
            'Content-Type' => 'application/manifest+json',
            'Cache-Control' => 'public, max-age=300',
        ]);
    }

    /**
     * Build the manifest icons list. When the operator uploaded a
     * favicon under /settings/branding, prepend it as a generic
     * (`sizes=any`) icon so launcher prompts pick up the custom brand
     * mark. The 192/512 PNG fallbacks stay behind it for installers
     * that demand exact sized PNGs.
     *
     * Why no `purpose=maskable` on the favicon: a user-uploaded mark
     * isn't safe-zone designed, so Android would crop the corners
     * badly on adaptive icons. Maskable stays on the bundled assets
     * which ARE designed for it.
     *
     * @param  array<string, mixed>  $branding
     * @return list<array<string, string>>
     */
    private function iconsFor(array $branding): array
    {
        $icons = [];

        $favicon = $branding['favicon_url'] ?? null;
        if (is_string($favicon) && $favicon !== '') {
            $icons[] = [
                'src' => $favicon,
                'sizes' => 'any',
                'type' => $this->mimeFromUrl($favicon),
                'purpose' => 'any',
            ];
        }

        $icons[] = [
            'src' => '/icons/icon-192.png',
            'sizes' => '192x192',
            'type' => 'image/png',
            'purpose' => 'any maskable',
        ];
        $icons[] = [
            'src' => '/icons/icon-512.png',
            'sizes' => '512x512',
            'type' => 'image/png',
            'purpose' => 'any maskable',
        ];

        return $icons;
    }

    private function mimeFromUrl(string $url): string
    {
        $ext = strtolower(pathinfo(parse_url($url, PHP_URL_PATH) ?? '', PATHINFO_EXTENSION));

        return match ($ext) {
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'webp' => 'image/webp',
            'svg' => 'image/svg+xml',
            'ico' => 'image/x-icon',
            default => 'image/png',
        };
    }
}
