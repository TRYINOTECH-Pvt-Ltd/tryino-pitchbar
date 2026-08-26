<?php

namespace App\Support;

use App\Models\Agent;
use App\Models\AppSetting;

/**
 * Resolves the marketing-site widget payload — which agent should
 * mount on the public marketing pages, whether it's a demo agent,
 * the cache-bust version, and the JS asset URL.
 *
 * Single source of truth so the Blade root template AND the Inertia
 * shared-prop reactive remount agree on what to render. The reactive
 * remount path is what fixes the long-standing "admin toggles widget
 * off but it stays in the marketing tab until Ctrl+R" complaint: the
 * payload goes through Inertia's shared props, so any client-side
 * Inertia visit refreshes it.
 *
 * @phpstan-type Payload array{
 *     enabled: bool,
 *     agent_id: string|null,
 *     is_demo: bool,
 *     version: string,
 *     src: string,
 * }
 */
final class MarketingWidget
{
    /**
     * @return Payload
     */
    public static function payload(bool $isAuthenticated): array
    {
        // Authenticated requests (admin / customer surfaces) never
        // render the marketing widget — short-circuit before the
        // AppSetting + Agent DB lookups so the shared-prop closure
        // costs nothing for the vast majority of in-app traffic.
        if ($isAuthenticated) {
            return [
                'enabled' => false,
                'agent_id' => null,
                'is_demo' => false,
                'version' => 'dev',
                'src' => rtrim((string) config('app.url'), '/').'/widget/widget.js',
            ];
        }

        $resolution = self::resolveAgentId($isAuthenticated);

        return [
            'enabled' => $resolution['agent_id'] !== null,
            'agent_id' => $resolution['agent_id'],
            'is_demo' => $resolution['is_demo'],
            'version' => self::version(),
            'src' => rtrim((string) config('app.url'), '/').'/widget/widget.js',
        ];
    }

    /**
     * Resolution mirrors the gate in `resources/views/app.blade.php`:
     *   1. Operator's explicit choice in /settings/system (marketing_widget_enabled
     *      + marketing_widget_agent_id pointing at a published agent).
     *   2. Else the seeded demo agent when DEMO=true AND the visitor is
     *      not signed in (we never embed a sandbox bot watching a
     *      logged-in admin).
     *   3. Else nothing — and the React side tears the widget down.
     *
     * When the operator made an explicit choice but the pick is invalid
     * (unpublished / deleted), we render NOTHING rather than silently
     * substituting the demo agent. Otherwise a stranger's persona name
     * surfaces on the buyer's marketing site (client report 2026-05-23).
     *
     * @return array{agent_id: string|null, is_demo: bool}
     */
    private static function resolveAgentId(bool $isAuthenticated): array
    {
        $adminMadeExplicitChoice = false;
        $explicitAgentId = null;

        try {
            $settings = AppSetting::singleton();

            if (
                ($settings->marketing_widget_enabled ?? false)
                && ! empty($settings->marketing_widget_agent_id)
            ) {
                $adminMadeExplicitChoice = true;

                $configuredAgent = Agent::query()
                    ->withoutGlobalScopes()
                    ->where('id', $settings->marketing_widget_agent_id)
                    ->where('is_published', true)
                    ->first();

                if ($configuredAgent !== null) {
                    $explicitAgentId = $configuredAgent->id;
                }
            }
        } catch (\Throwable) {
            // Fresh install without the migration — fall through.
        }

        if ($explicitAgentId !== null) {
            return ['agent_id' => $explicitAgentId, 'is_demo' => false];
        }

        if ($adminMadeExplicitChoice) {
            // Admin's pick is invalid — render nothing, don't fall back to demo.
            return ['agent_id' => null, 'is_demo' => false];
        }

        if ((bool) config('demo.enabled', false) && ! $isAuthenticated) {
            $demoId = MarketingDemoAgent::id();
            if ($demoId !== null) {
                return ['agent_id' => $demoId, 'is_demo' => true];
            }
        }

        return ['agent_id' => null, 'is_demo' => false];
    }

    /**
     * Cache-bust token. Prefer the build hash from widget/manifest.json
     * (always present post-build), fall back to md5 of the on-disk
     * unhashed file, else "dev".
     */
    private static function version(): string
    {
        $manifestPath = public_path('widget/manifest.json');

        if (is_file($manifestPath)) {
            $manifest = json_decode((string) file_get_contents($manifestPath), true);
            if (is_array($manifest) && ! empty($manifest['hash'])) {
                return substr((string) $manifest['hash'], 0, 8);
            }
        }

        $widgetPath = public_path('widget/widget.js');
        if (is_file($widgetPath)) {
            return substr((string) md5_file($widgetPath), 0, 8);
        }

        return 'dev';
    }
}
