<?php

namespace App\Console\Commands\Board;

use App\Services\Board\BoardStore;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Str;

/**
 * Idempotently seed the internal Kanban board with the post-Phase-1
 * backlog (open CodeCanyon-comment items + pagenet asks) and the
 * shipped-this-session items in the Done column.
 *
 * Idempotency is per-title: re-running the command only adds tasks
 * whose title isn't already present, so it's safe to bake into
 * deploy steps.
 *
 *   php artisan board:seed
 */
#[Signature('board:seed')]
#[Description('Seed the internal admin Kanban board with the current backlog (idempotent).')]
class SeedBoardCommand extends Command
{
    public function handle(BoardStore $store): int
    {
        $existing = $store->all();
        $byTitle = [];
        foreach ($existing as $task) {
            $byTitle[(string) ($task['title'] ?? '')] = true;
        }

        $now = Date::now()->toIso8601String();
        $position = ['backlog' => 0, 'doing' => 0, 'review' => 0, 'done' => 0];
        $number = $store->nextNumber();

        $defs = self::definitions();
        $added = 0;
        foreach ($defs as $def) {
            if (isset($byTitle[$def['title']])) {
                continue;
            }
            $status = $def['status'];
            $position[$status] = ($position[$status] ?? 0) + 1;

            $existing[] = [
                'id' => (string) Str::uuid7(),
                'number' => $number++,
                'title' => $def['title'],
                'body' => $def['body'] ?? null,
                'status' => $status,
                'labels' => $def['labels'] ?? [],
                'position' => $position[$status],
                'created_at' => $now,
                'updated_at' => $now,
            ];
            $added++;
        }

        if ($added === 0) {
            $this->info('No new tasks to add — board already seeded.');

            return self::SUCCESS;
        }

        $store->replace($existing);

        $this->info("Added {$added} task(s) to the board.");

        return self::SUCCESS;
    }

    /**
     * @return array<int, array{title: string, status: string, body?: string, labels?: array<int, string>}>
     */
    private static function definitions(): array
    {
        return [
            // ─── DONE ────────────────────────────────────────────────────
            ['status' => 'done', 'title' => 'Mobile homepage hero overflow fix (narrow viewports)', 'labels' => ['ui', 'marketing']],
            ['status' => 'done', 'title' => 'Lead capture email pipeline + e2e test + white-label footer', 'labels' => ['leads', 'email']],
            ['status' => 'done', 'title' => 'White-label cascade — Stripe/PayPal/Razorpay/Razorpay-checkout/OpenRouter', 'labels' => ['branding']],
            ['status' => 'done', 'title' => 'Per-plan AI rate-limit + max_tokens_per_response dials', 'labels' => ['billing', 'admin']],
            ['status' => 'done', 'title' => 'Annual (12-month) billing interval — Stripe + PayPal + Razorpay', 'labels' => ['billing']],
            ['status' => 'done', 'title' => 'In-app toast + browser-push notifications for new leads', 'labels' => ['leads', 'admin']],
            ['status' => 'done', 'title' => 'Chatflow / workflow builder MVP (Phase 1 — linear keyword flows)', 'labels' => ['workflows']],
            ['status' => 'done', 'title' => 'Internal Kanban board for the platform', 'labels' => ['admin', 'meta']],

            // ─── BACKLOG (open CodeCanyon-comment items + pagenet asks) ──
            [
                'status' => 'backlog',
                'title' => 'pagenet — bottom-right widget bubble option',
                'body' => "Buyer pagenet flagged that some site owners don't want the bar across the bottom-center; the traditional Intercom/Drift/Tawk-style floating bubble in the corner is the de-facto industry pattern. Add a per-agent widget_position option (bottom_center | bottom_right | bottom_left), wired from widget_defaults → Bar.tsx CSS positioning.",
                'labels' => ['widget', 'pagenet'],
            ],
            [
                'status' => 'backlog',
                'title' => 'pagenet — pre-chat name/email gate',
                'body' => 'Buyer pagenet asks for the option to require Name + Email BEFORE the chat surface opens (vs the current inline-form-during-chat). Conversion is higher because the visitor is still motivated to identify themselves before getting their answer. Per-agent require_lead_before_chat toggle; widget renders a lead form first, creates the Lead, then opens the chat.',
                'labels' => ['widget', 'leads', 'pagenet'],
            ],
            [
                'status' => 'backlog',
                'title' => 'Reply on CodeCanyon threads — PayPal/Razorpay/product-cards/adaptive-widget shipped',
                'labels' => ['comms', 'codecanyon'],
            ],
            [
                'status' => 'backlog',
                'title' => 'Add Paddle payment gateway (Merchant of Record)',
                'body' => 'EU/UK buyers prefer Paddle for tax handling. Mirror the StripeProductSync / PayPalProductSync / RazorpayProductSync pattern.',
                'labels' => ['billing'],
            ],
            [
                'status' => 'backlog',
                'title' => 'Add Iyzico payment gateway (Turkish market)',
                'labels' => ['billing'],
            ],
            [
                'status' => 'backlog',
                'title' => 'Add PayU payment gateway',
                'labels' => ['billing'],
            ],
            [
                'status' => 'backlog',
                'title' => 'More landing-page sections — testimonial carousel + FAQ',
                'labels' => ['marketing'],
            ],
            [
                'status' => 'backlog',
                'title' => 'SMS 2FA via Twilio (alongside Fortify TOTP)',
                'labels' => ['auth'],
            ],
            [
                'status' => 'backlog',
                'title' => 'i18n — extract admin UI strings to lang/ files + es/fr/tr starters',
                'labels' => ['i18n'],
            ],
            [
                'status' => 'backlog',
                'title' => 'Chatflow Phase 2 — branching, conditional logic, variables',
                'body' => 'Add a branch step type, multi-keyword match modes (all-of / any-of / exact), tag-lead step, HTTP-webhook step. The persistence shape is forwards-compatible already.',
                'labels' => ['workflows'],
            ],
            [
                'status' => 'backlog',
                'title' => 'Post a public roadmap on the CodeCanyon item thread',
                'labels' => ['comms', 'codecanyon'],
            ],
            [
                'status' => 'backlog',
                'title' => 'Ecommerce plugin — Shopify/WooCommerce product catalog sync',
                'labels' => ['integrations', 'ecommerce'],
            ],
            [
                'status' => 'backlog',
                'title' => 'Promo code / discount system for plan signups',
                'labels' => ['billing'],
            ],
            [
                'status' => 'backlog',
                'title' => 'Audit white-label completeness across login, dashboard, marketing, widget, emails',
                'body' => 'Phase 1 done. Round-trip every page in a fully-renamed install once Paddle/Iyzico/PayU are wired so the audit covers them too.',
                'labels' => ['branding', 'audit'],
            ],
            [
                'status' => 'backlog',
                'title' => 'Verify NewLeadCaptured email firing in production after MAIL_MAILER + queue worker',
                'body' => 'Pipeline is wired + tested. Buyer reports of "leads not arriving" almost always trace back to MAIL_MAILER=log or no queue worker. Add a "send test email" button in admin to surface misconfigurations.',
                'labels' => ['leads', 'ops'],
            ],
        ];
    }
}
