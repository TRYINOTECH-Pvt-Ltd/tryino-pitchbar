<?php

namespace App\Http\Controllers\Admin\Platform;

use App\Models\UsageEvent;
use App\Models\UsageLog;
use App\Models\Workspace;
use Inertia\Inertia;
use Inertia\Response;

class UsageController
{
    public function index(): Response
    {
        $thisMonth = UsageEvent::query()
            ->where('kind', 'conversation')
            ->where('occurred_at', '>=', now()->startOfMonth())
            ->selectRaw('workspace_id, sum(quantity) as conversations')
            ->groupBy('workspace_id')
            ->get();

        $workspaces = Workspace::query()->withoutGlobalScopes()
            ->whereIn('id', $thisMonth->pluck('workspace_id'))
            ->with('plan:id,name,monthly_conversations')
            ->get()
            ->keyBy('id');

        $rows = $thisMonth->map(function ($u) use ($workspaces) {
            $w = $workspaces->get($u->workspace_id);
            $limit = (int) ($w?->plan?->monthly_conversations ?? 0);
            $used = (int) $u->conversations;

            return [
                'workspace_id' => $u->workspace_id,
                'workspace_name' => $w?->name ?? '(deleted)',
                'plan' => $w?->plan?->name ?? '—',
                'used' => $used,
                'limit' => $limit,
                'percent' => $limit > 0 ? min(100, (int) round(($used / $limit) * 100)) : 0,
            ];
        })->sortByDesc('used')->values();

        return Inertia::render('admin/usage/index', [
            'rows' => $rows,
            'tokens' => $this->tokenRows(),
            'window' => 'this-month',
        ]);
    }

    /**
     * C9: per-workspace token burn this month. Aggregates the silent
     * usage_logs feed PersistUsageJob writes after every chat turn.
     * Always read withoutGlobalScopes — the admin spans every tenant.
     *
     * @return array<int, array<string, mixed>>
     */
    private function tokenRows(): array
    {
        $monthStart = now()->startOfMonth();

        $byWorkspace = UsageLog::query()->withoutWorkspaceScope()
            ->where('created_at', '>=', $monthStart)
            ->selectRaw('workspace_id, sum(tokens_in) as tokens_in, sum(tokens_out) as tokens_out, sum(cost_usd_micro) as cost_usd_micro, count(*) as calls')
            ->groupBy('workspace_id')
            ->orderByDesc('cost_usd_micro')
            ->limit(50)
            ->get();

        if ($byWorkspace->isEmpty()) {
            return [];
        }

        $workspaces = Workspace::query()->withoutGlobalScopes()
            ->whereIn('id', $byWorkspace->pluck('workspace_id'))
            ->get(['id', 'name'])
            ->keyBy('id');

        // Per-(workspace, provider, model) counts so the admin can see
        // why cost might be $0 even when token counts are non-zero —
        // most often the model name is missing from the pricing catalog
        // OR the LLM stream returned zero tokens. Buyer report (Lucian,
        // 2026-05-18): "Cost (USD) always $0.0000".
        $byModel = UsageLog::query()->withoutWorkspaceScope()
            ->where('created_at', '>=', $monthStart)
            ->selectRaw('workspace_id, provider, model, sum(tokens_in) as tokens_in, sum(tokens_out) as tokens_out, sum(cost_usd_micro) as cost_usd_micro, count(*) as calls')
            ->groupBy('workspace_id', 'provider', 'model')
            ->get()
            ->groupBy('workspace_id');

        return $byWorkspace->map(function ($row) use ($workspaces, $byModel) {
            $w = $workspaces->get($row->workspace_id);
            $costMicros = (int) $row->cost_usd_micro;
            $tokensTotal = (int) $row->tokens_in + (int) $row->tokens_out;

            $breakdown = ($byModel->get($row->workspace_id) ?? collect())
                ->map(fn ($m) => [
                    'provider' => (string) ($m->provider ?? 'unknown'),
                    'model' => (string) ($m->model ?? 'unknown'),
                    'tokens_in' => (int) $m->tokens_in,
                    'tokens_out' => (int) $m->tokens_out,
                    'calls' => (int) $m->calls,
                    'cost_usd' => round(((int) $m->cost_usd_micro) / 1_000_000, 6),
                    'no_catalog_match' => (int) $m->cost_usd_micro === 0
                        && ((int) $m->tokens_in + (int) $m->tokens_out) > 0,
                ])
                ->values()
                ->all();

            $cost = round($costMicros / 1_000_000, 6);
            // Diagnostic flag: workspace has token usage but zero cost.
            // Either the catalog doesn't price the model, or the LLM
            // stream returned no tokens in earlier turns. UI surfaces
            // this so the operator doesn't think the dashboard is broken.
            $costMissing = $cost === 0.0 && $tokensTotal > 0;

            return [
                'workspace_id' => $row->workspace_id,
                'workspace_name' => $w?->name ?? '(deleted)',
                'tokens_in' => (int) $row->tokens_in,
                'tokens_out' => (int) $row->tokens_out,
                'tokens_total' => $tokensTotal,
                'calls' => (int) $row->calls,
                // Render USD at micro-cent precision when the workspace
                // burned tokens but the 4-decimal rounding would have
                // collapsed to $0.0000 (low-traffic playgrounds, light
                // BYOK installs). Frontend chooses the right number of
                // decimals based on magnitude.
                'cost_usd' => $cost,
                'cost_missing' => $costMissing,
                'breakdown' => $breakdown,
            ];
        })->all();
    }
}
