<?php

namespace App\Services\Widget;

use App\Models\AppSetting;
use App\Models\Workspace;

/**
 * Resolves the effective widget defaults for a given workspace, with a
 * three-tier fallback:
 *
 *   1. Workspace's own widget_defaults (set via Settings → Widget)
 *   2. Platform-wide defaults from app_settings.widget_defaults
 *      (set by a super_admin via Settings → System → Widget defaults)
 *   3. Hardcoded ship defaults — the colour palette / persona that
 *      every fresh install starts with so a brand-new platform with
 *      zero config still produces a working agent.
 *
 * Each tier is merged shallowly per top-level key (theme / persona /
 * guardrails / starter_prompts), so a workspace that overrides only
 * `theme` still picks up the platform-set persona.
 */
class WidgetDefaultsResolver
{
    /**
     * @return array{
     *   theme: array<string, mixed>,
     *   persona: array<string, mixed>,
     *   guardrails: array<string, mixed>,
     *   starter_prompts: array<int, string>,
     * }
     */
    public function for(Workspace $workspace): array
    {
        $hardcoded = self::shipDefaults();
        $platform = $this->platformDefaults();
        $workspaceLevel = (array) ($workspace->widget_defaults ?? []);

        return [
            'theme' => array_replace(
                $hardcoded['theme'],
                (array) ($platform['theme'] ?? []),
                (array) ($workspaceLevel['theme'] ?? []),
            ),
            'persona' => array_replace(
                $hardcoded['persona'],
                (array) ($platform['persona'] ?? []),
                (array) ($workspaceLevel['persona'] ?? []),
            ),
            'guardrails' => array_replace(
                $hardcoded['guardrails'],
                (array) ($platform['guardrails'] ?? []),
                (array) ($workspaceLevel['guardrails'] ?? []),
            ),
            'starter_prompts' => $this->pickPrompts(
                $workspaceLevel['starter_prompts'] ?? null,
                $platform['starter_prompts'] ?? null,
                $hardcoded['starter_prompts'],
            ),
        ];
    }

    /**
     * Convenience for super_admin Settings page — returns the platform
     * defaults merged on top of ship defaults, ready to display.
     *
     * @return array<string, mixed>
     */
    public function platform(): array
    {
        $hardcoded = self::shipDefaults();
        $platform = $this->platformDefaults();

        return [
            'theme' => array_replace($hardcoded['theme'], (array) ($platform['theme'] ?? [])),
            'persona' => array_replace($hardcoded['persona'], (array) ($platform['persona'] ?? [])),
            'guardrails' => array_replace($hardcoded['guardrails'], (array) ($platform['guardrails'] ?? [])),
            'starter_prompts' => $this->pickPrompts(
                $platform['starter_prompts'] ?? null,
                null,
                $hardcoded['starter_prompts'],
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function platformDefaults(): array
    {
        try {
            $row = AppSetting::singleton();

            return (array) ($row->widget_defaults ?? []);
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @param  array<int, string>  $fallback
     * @return array<int, string>
     */
    private function pickPrompts(mixed $first, mixed $second, array $fallback): array
    {
        foreach ([$first, $second] as $candidate) {
            if (is_array($candidate) && $candidate !== []) {
                return array_values(array_filter(
                    array_map(static fn ($v) => is_string($v) ? trim($v) : '', $candidate),
                    static fn (string $v): bool => $v !== '',
                ));
            }
        }

        return $fallback;
    }

    /**
     * The hardcoded floor — every install ships with these.
     *
     * @return array{
     *   theme: array<string, mixed>,
     *   persona: array<string, mixed>,
     *   guardrails: array<string, mixed>,
     *   starter_prompts: array<int, string>,
     * }
     */
    public static function shipDefaults(): array
    {
        return [
            'theme' => [
                'primary' => '#111827',
                'accent' => '#10b981',
                'radius' => 12,
            ],
            'persona' => [
                'name' => 'Assistant',
                'tone' => 'friendly',
            ],
            'guardrails' => [
                'avoid' => [],
                'max_chars' => 2500,
            ],
            'starter_prompts' => [],
        ];
    }
}
