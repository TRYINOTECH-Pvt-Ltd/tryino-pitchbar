<?php

namespace App\Services\Billing;

use App\Models\Plan;
use Illuminate\Support\Collection;

/**
 * Canonical 10-row feature matrix for plan comparison. Single source
 * of truth so admin /billing cards and public /pricing matrix stay
 * in lock-step — buyer reported (2026-05-25) two rows had drifted
 * off the public side. Now any new row added here surfaces on both
 * surfaces automatically.
 *
 * Row order matters: the admin card and the public matrix render
 * top-to-bottom in this order. Cell formatting (null = Unlimited,
 * 0 = Not included, else number) is mirrored from the React side
 * (`resources/js/pages/app/billing.tsx::renderLimit`).
 *
 * @phpstan-type RowDef array{label: string, kind: 'int'|'bool', resolver: callable, unlimited_label?: string}
 * @phpstan-type MatrixRow array{label: string} & array<string, mixed>
 */
final class PlanFeatureMatrix
{
    /**
     * @return list<RowDef>
     */
    public static function rowDefinitions(): array
    {
        return [
            [
                'label' => 'Published agents',
                'kind' => 'int',
                'resolver' => static fn (Plan $p) => $p->agents_limit,
                'unlimited_label' => 'Unlimited',
            ],
            [
                'label' => 'Monthly conversations',
                'kind' => 'int',
                'resolver' => static fn (Plan $p) => $p->monthly_conversations,
                'unlimited_label' => 'Unlimited',
            ],
            [
                'label' => 'AI messages per month',
                'kind' => 'int',
                'resolver' => static fn (Plan $p) => $p->monthly_messages,
                'unlimited_label' => 'Unlimited',
            ],
            [
                'label' => 'Workspace members',
                'kind' => 'int',
                'resolver' => static fn (Plan $p) => $p->members_limit,
                'unlimited_label' => 'Unlimited',
            ],
            [
                'label' => 'Workspaces per owner',
                'kind' => 'int',
                'resolver' => static fn (Plan $p) => $p->workspaces_limit,
                'unlimited_label' => 'Unlimited',
            ],
            [
                'label' => 'Knowledge sources',
                'kind' => 'int',
                'resolver' => static fn (Plan $p) => $p->sources_limit,
                'unlimited_label' => 'Unlimited',
            ],
            [
                'label' => 'Workflows',
                'kind' => 'int',
                'resolver' => static fn (Plan $p) => $p->workflows_limit,
                'unlimited_label' => 'Unlimited',
            ],
            [
                'label' => 'Integrations',
                'kind' => 'int',
                'resolver' => static fn (Plan $p) => $p->integrations_limit,
                'unlimited_label' => 'Unlimited',
            ],
            [
                'label' => 'API access',
                'kind' => 'bool',
                'resolver' => static fn (Plan $p) => (bool) ($p->api_access ?? true),
            ],
            [
                'label' => 'Branding removed',
                'kind' => 'bool',
                'resolver' => static fn (Plan $p) => (bool) ($p->features['remove_branding'] ?? false),
            ],
        ];
    }

    /**
     * Side-by-side matrix for the public /pricing page. Each row is
     * `{label, <plan_slug> => display_value}`.
     *
     * @param  Collection<int, Plan>  $plans
     * @return list<MatrixRow>
     */
    public static function matrixFor(Collection $plans): array
    {
        return array_map(static function (array $def) use ($plans): array {
            $row = ['label' => $def['label']];
            foreach ($plans as $plan) {
                $key = self::planKey($plan);
                $value = ($def['resolver'])($plan);
                $row[$key] = $def['kind'] === 'bool'
                    ? (bool) $value
                    : self::formatIntCell($value, $def['unlimited_label'] ?? 'Unlimited');
            }

            return $row;
        }, self::rowDefinitions());
    }

    /**
     * Just the labels in the canonical order — used by the parity
     * regression test and any UI that wants the row list without
     * per-plan values.
     *
     * @return list<string>
     */
    public static function labels(): array
    {
        return array_map(static fn (array $def) => $def['label'], self::rowDefinitions());
    }

    /**
     * Per-plan stable key used as the column header in matrix rows.
     * Matches the lowercase/underscore convention the existing
     * pricing matrix used so the React reader's column lookup keeps
     * working unchanged.
     */
    private static function planKey(Plan $plan): string
    {
        return strtolower(preg_replace('/[^a-z0-9]+/i', '_', (string) $plan->name) ?? 'plan');
    }

    private static function formatIntCell(mixed $value, string $unlimitedLabel): string
    {
        if ($value === null) {
            return $unlimitedLabel;
        }

        $int = (int) $value;
        if ($int === 0) {
            return '—';
        }

        return number_format($int);
    }
}
