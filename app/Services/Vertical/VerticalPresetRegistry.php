<?php

namespace App\Services\Vertical;

use App\Services\Vertical\Presets\Contracts\VerticalPreset;
use App\Services\Vertical\Presets\DocumentationPreset;
use App\Services\Vertical\Presets\EcommercePreset;
use App\Services\Vertical\Presets\GenericPreset;
use App\Services\Vertical\Presets\HelpCenterPreset;
use App\Services\Vertical\Presets\InternalKbPreset;
use App\Services\Vertical\Presets\MarketingPreset;
use App\Services\Vertical\Presets\SaasPreset;

/**
 * In-memory registry of every vertical preset. Bound `scoped` in
 * AppServiceProvider so the array of preset objects is reused across
 * a request without leaking between Octane workers.
 *
 * Lookups are O(1) array access — adding `for()` to the hot path
 * (e.g. InitController) costs nothing in queries or I/O.
 */
class VerticalPresetRegistry
{
    /** @var array<string, VerticalPreset> */
    private array $presets;

    public function __construct()
    {
        $this->presets = [
            'ecommerce' => new EcommercePreset,
            'documentation' => new DocumentationPreset,
            'saas' => new SaasPreset,
            'help_center' => new HelpCenterPreset,
            'marketing' => new MarketingPreset,
            'internal_kb' => new InternalKbPreset,
            'generic' => new GenericPreset,
        ];
    }

    /**
     * Look up a preset by slug. Unknown slugs fall back to the generic
     * preset so the system never errors on a stale or misspelled value.
     */
    public function for(string $slug): VerticalPreset
    {
        return $this->presets[$slug] ?? $this->presets['generic'];
    }

    /**
     * @return array<string, VerticalPreset>
     */
    public function all(): array
    {
        return $this->presets;
    }
}
