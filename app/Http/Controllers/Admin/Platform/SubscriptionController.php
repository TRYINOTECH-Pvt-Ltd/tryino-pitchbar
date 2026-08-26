<?php

namespace App\Http\Controllers\Admin\Platform;

use App\Models\Plan;
use App\Models\Workspace;
use App\Support\Pagination;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Cashier\Subscription;

/**
 * Platform-level subscription overview — MRR, active subscription count
 * by plan, plus a per-workspace breakdown. This is what super-admins see
 * instead of the customer-side /app/billing page.
 *
 * Read-only for v1: cancelations / refunds / impersonated checkouts go
 * via Stripe Dashboard for now.
 */
class SubscriptionController
{
    public function index(Request $request): Response
    {
        $q = trim((string) $request->query('q', ''));
        $plans = Plan::query()
            ->where('is_active', true)
            ->orderBy('price_cents')
            ->get(['id', 'name', 'slug', 'price_cents', 'monthly_conversations']);

        // Active subscriptions = Cashier rows in 'active' or 'trialing' state.
        // 'past_due' grants access during grace but doesn't pay yet — count
        // it separately so we can show "at risk" revenue.
        $activeStatuses = ['active', 'trialing'];
        $atRiskStatuses = ['past_due'];

        $subscriptions = Subscription::query()
            ->whereIn('stripe_status', array_merge($activeStatuses, $atRiskStatuses))
            ->get(['id', 'workspace_id', 'stripe_status', 'stripe_price', 'created_at', 'ends_at']);

        // Map Stripe price IDs → local Plan rows so we can roll up by plan.
        $plansByStripeId = Plan::query()
            ->whereNotNull('stripe_price_id')
            ->get(['id', 'name', 'slug', 'price_cents', 'stripe_price_id'])
            ->keyBy('stripe_price_id');

        // Local-plan MRR: sum of plan.price_cents for every workspace with
        // a paid plan_id assigned (Cashier-backed Stripe subs OR
        // platform-side manual upgrades OR lifetime+monthly billing models).
        // Buyer report: MRR read $0 even with workspaces on the Pro plan
        // because the Cashier-only loop only counts rows that have a
        // matching Stripe Price ID — Pitchbar installs that haven't run
        // StripeProductSync yet OR are on a non-Stripe gateway (PayPal /
        // Razorpay / lifetime) had every paid workspace silently ignored.
        $planById = Plan::query()
            ->where('is_active', true)
            ->get(['id', 'price_cents', 'billing_model'])
            ->keyBy('id');

        $mrrCents = 0;
        DB::table('workspaces')
            ->whereNull('deleted_at')
            ->whereNotNull('plan_id')
            ->select('plan_id', DB::raw('count(*) as total'))
            ->groupBy('plan_id')
            ->get()
            ->each(function ($row) use ($planById, &$mrrCents) {
                $plan = $planById->get($row->plan_id);
                if ($plan === null) {
                    return;
                }
                // Lifetime / one-time charges don't recur, so they
                // don't contribute to monthly recurring revenue.
                if ($plan->billing_model === Plan::BILLING_LIFETIME
                    || $plan->billing_model === Plan::BILLING_ONE_TIME) {
                    return;
                }
                $mrrCents += (int) $plan->price_cents * (int) $row->total;
            });

        // At-risk MRR: Cashier-tracked subs in past_due. We still need the
        // Stripe loop here because past_due is a Stripe-side state — a
        // local plan_id alone can't tell us that the payment failed.
        $atRiskCents = 0;
        foreach ($subscriptions as $sub) {
            if (! in_array($sub->stripe_status, $atRiskStatuses, true)) {
                continue;
            }
            $plan = $plansByStripeId->get((string) $sub->stripe_price);
            if ($plan === null) {
                continue;
            }
            $atRiskCents += (int) $plan->price_cents;
        }

        // Subscriber count per plan: derive from workspaces.plan_id, NOT
        // from Stripe subscriptions. Pre-fix this counted only workspaces
        // with an active Stripe sub whose `stripe_price` matched a known
        // `Plan.stripe_price_id` — so the Free plan was always 0
        // (no Stripe sub on a $0 plan), and any install that hadn't
        // wired `stripe_price_id` on its plan rows yet read 0 across
        // the board. Counting plan_id covers Free + LTD + manual
        // assignments + Stripe-driven plans in one query.
        $byPlanCount = DB::table('workspaces')
            ->whereNull('deleted_at')
            ->whereNotNull('plan_id')
            ->select('plan_id', DB::raw('count(*) as total'))
            ->groupBy('plan_id')
            ->pluck('total', 'plan_id');

        // Translate plan_id-keyed counts to slug-keyed counts the view expects.
        $byPlanCountBySlug = $plans->mapWithKeys(fn (Plan $p) => [
            $p->slug => (int) ($byPlanCount[$p->id] ?? 0),
        ])->all();

        // Workspace-level breakdown — one row per workspace with a paid
        // plan_id set OR an active Cashier subscription.
        $workspacesQuery = Workspace::query()
            ->withoutGlobalScopes()
            ->where(function ($outer) use ($subscriptions) {
                $outer->whereNotNull('plan_id')
                    ->orWhereIn('id', $subscriptions->pluck('workspace_id'));
            })
            ->with('plan:id,name,slug,price_cents,monthly_conversations')
            ->orderBy('created_at');

        if ($q !== '') {
            $like = "%{$q}%";
            $workspacesQuery->where(function ($w) use ($like) {
                $w->where('name', 'like', $like)
                    ->orWhere('slug', 'like', $like)
                    ->orWhere('stripe_id', 'like', $like);
            });
        }

        $paginator = $workspacesQuery
            ->paginate(25, ['id', 'name', 'slug', 'plan_id', 'stripe_id', 'created_at'])
            ->withQueryString();

        $subsByWorkspace = $subscriptions->keyBy('workspace_id');

        $rows = collect($paginator->items())->map(function (Workspace $w) use ($subsByWorkspace) {
            $sub = $subsByWorkspace->get($w->id);

            // Pre-fix: every workspace without a live Cashier row read
            // "no subscription", even ones on paid plans assigned via
            // a non-Stripe path (PayPal/Razorpay/lifetime/manual). The
            // status column was lying. Derive a truthful state from the
            // local plan_id when Stripe has no opinion.
            $planPrice = (int) ($w->plan?->price_cents ?? 0);
            $derivedStatus = $sub?->stripe_status;
            if ($derivedStatus === null) {
                if ($w->plan_id === null) {
                    $derivedStatus = 'no_plan';
                } elseif ($planPrice === 0) {
                    $derivedStatus = 'free';
                } else {
                    $derivedStatus = 'manual';
                }
            }

            return [
                'workspace_id' => $w->id,
                'workspace_name' => $w->name,
                'workspace_slug' => $w->slug,
                'plan' => $w->plan?->name ?? '—',
                'plan_slug' => $w->plan?->slug ?? null,
                'price_cents' => $planPrice,
                'monthly_conversations' => (int) ($w->plan?->monthly_conversations ?? 0),
                'stripe_status' => $derivedStatus,
                'stripe_customer_id' => $w->stripe_id,
                'created_at' => $w->created_at?->toIso8601String(),
                'ends_at' => $sub?->ends_at?->toIso8601String(),
            ];
        })->values();

        // Snapshot total customers ever — useful for churn baseline later.
        $totalWorkspaces = (int) DB::table('workspaces')->whereNull('deleted_at')->count();

        // Paid-plan workspace count: drives the "Paid conversion" tile.
        // Previously `active_count` came from `subscriptions->whereIn(...)`
        // which is Stripe-only — same blind spot as MRR. Counting paid
        // plan_id (price > 0) covers every billing model.
        $paidWorkspaceCount = (int) DB::table('workspaces')
            ->whereNull('deleted_at')
            ->whereNotNull('plan_id')
            ->whereIn('plan_id', Plan::query()
                ->where('is_active', true)
                ->where('price_cents', '>', 0)
                ->pluck('id'))
            ->count();

        return Inertia::render('admin/subscriptions/index', [
            'totals' => [
                'mrr_cents' => $mrrCents,
                'at_risk_cents' => $atRiskCents,
                'active_count' => $paidWorkspaceCount,
                'past_due_count' => $subscriptions->whereIn('stripe_status', $atRiskStatuses)->count(),
                'total_workspaces' => $totalWorkspaces,
            ],
            'plans' => $plans->map(fn (Plan $p) => [
                'name' => $p->name,
                'slug' => $p->slug,
                'price_cents' => (int) $p->price_cents,
                'monthly_conversations' => (int) $p->monthly_conversations,
                'subscriber_count' => $byPlanCountBySlug[$p->slug] ?? 0,
            ])->values(),
            'workspaces' => $rows,
            'pagination' => Pagination::meta($paginator),
            'filters' => ['q' => $q],
        ]);
    }
}
