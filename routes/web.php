<?php

use App\Http\Controllers\Admin\AgentController;
use App\Http\Controllers\Admin\AgentLauncherIconController;
use App\Http\Controllers\Admin\AnalyticsController;
use App\Http\Controllers\Admin\AuditController;
use App\Http\Controllers\Admin\BehaviorRuleController;
use App\Http\Controllers\Admin\BillingController;
use App\Http\Controllers\Admin\CannedReplyController;
use App\Http\Controllers\Admin\ConversationController;
use App\Http\Controllers\Admin\ConversationExportController;
use App\Http\Controllers\Admin\ConversationTagAssignmentController;
use App\Http\Controllers\Admin\ConversationTagController;
use App\Http\Controllers\Admin\ConversationTakeoverController;
use App\Http\Controllers\Admin\CtaRuleController;
use App\Http\Controllers\Admin\CuratedAnswerController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\DsrController;
use App\Http\Controllers\Admin\ExperimentController;
use App\Http\Controllers\Admin\GoogleOAuthController;
use App\Http\Controllers\Admin\IntegrationController;
use App\Http\Controllers\Admin\InvitationController;
use App\Http\Controllers\Admin\KnowledgeController;
use App\Http\Controllers\Admin\LeadController;
use App\Http\Controllers\Admin\LeadFeedController;
use App\Http\Controllers\Admin\LiveChatSettingsController;
use App\Http\Controllers\Admin\Mcp\McpActivityController;
use App\Http\Controllers\Admin\Mcp\McpServerController;
use App\Http\Controllers\Admin\MemberController;
use App\Http\Controllers\Admin\NotionOAuthController;
use App\Http\Controllers\Admin\OnboardingController;
use App\Http\Controllers\Admin\Platform\AgentController as PlatformAgentController;
use App\Http\Controllers\Admin\Platform\ConversationController as PlatformConversationController;
use App\Http\Controllers\Admin\Platform\DashboardController as PlatformDashboardController;
use App\Http\Controllers\Admin\Platform\ImpersonateController;
use App\Http\Controllers\Admin\Platform\JobController as PlatformJobController;
use App\Http\Controllers\Admin\Platform\KanbanBoardController;
use App\Http\Controllers\Admin\Platform\LeadController as PlatformLeadController;
use App\Http\Controllers\Admin\Platform\PageController as PlatformPageController;
use App\Http\Controllers\Admin\Platform\PlanController as PlatformPlanController;
use App\Http\Controllers\Admin\Platform\SearchController as PlatformSearchController;
use App\Http\Controllers\Admin\Platform\SubscriptionController;
use App\Http\Controllers\Admin\Platform\TranslationController as PlatformTranslationController;
use App\Http\Controllers\Admin\Platform\UsageController as PlatformUsageController;
use App\Http\Controllers\Admin\Platform\UserController as PlatformUserController;
use App\Http\Controllers\Admin\Platform\WordPressDistributionController;
use App\Http\Controllers\Admin\Platform\WorkspaceController as PlatformWorkspaceController;
use App\Http\Controllers\Admin\PlaygroundController;
use App\Http\Controllers\Admin\PlaygroundStreamController;
use App\Http\Controllers\Admin\PresenceController;
use App\Http\Controllers\Admin\SearchController;
use App\Http\Controllers\Admin\SourceController;
use App\Http\Controllers\Admin\SystemHealthController;
use App\Http\Controllers\Admin\TicketController;
use App\Http\Controllers\Admin\UploadController;
use App\Http\Controllers\Admin\Vertical\ApplyController;
use App\Http\Controllers\Admin\Vertical\DetectController;
use App\Http\Controllers\Admin\WorkflowController;
use App\Http\Controllers\Admin\WorkspaceController;
use App\Http\Controllers\Admin\WorkspaceSelectController;
use App\Http\Controllers\Billing\CheckoutController;
use App\Http\Controllers\Billing\LifecycleController as BillingLifecycleController;
use App\Http\Controllers\Billing\PayPalWebhookController;
use App\Http\Controllers\Billing\RazorpayWebhookController;
use App\Http\Controllers\Billing\WebhookController as BillingWebhookController;
use App\Http\Controllers\ChangelogController;
use App\Http\Controllers\ChangelogSeenController;
use App\Http\Controllers\DocumentationController;
use App\Http\Controllers\MarketingController;
use App\Http\Controllers\Public\KnowledgeBaseController;
use App\Http\Controllers\Public\PageController;
use App\Http\Controllers\PwaManifestController;
use App\Http\Controllers\SeoController;
use App\Http\Controllers\Settings\LocaleController;
use App\Http\Controllers\WidgetBundleController;
use App\Http\Controllers\WidgetManifestController;
use App\Http\Middleware\RedirectMarketingWhenDisabled;
use App\Models\Agent;
use App\Models\ChangelogEntry;
use App\Models\Workspace;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

// Marketing site — React/Inertia landing page plus simple Blade
// subpages. The `marketing.public` middleware redirects to /login
// when the platform admin has disabled the public marketing site
// (Settings → Branding → "Public marketing site" off). /privacy
// and /terms stay accessible always — they're required reading from
// the auth flows.
// D1: PWA manifest for the admin shell. Sits OUTSIDE the marketing
// toggle group so installed admins keep working even when the public
// marketing site is hidden.
Route::get('/manifest.webmanifest', PwaManifestController::class)
    ->name('pwa.manifest');

Route::get('/widget/manifest.json', WidgetManifestController::class)
    ->name('widget.manifest');

// Serve the unhashed widget.js bundle through PHP so we can attach
// `Cache-Control: no-cache, must-revalidate` headers. Apache / Nginx
// serve static files with permissive defaults; embedders pointing at
// /widget/widget.js then got a stale bundle for days even after the
// host re-deployed. ETag is preserved so 304 short-circuits still
// save bandwidth.
Route::get('/widget/widget.js', WidgetBundleController::class)
    ->name('widget.bundle');

// In-chat Stripe Checkout return pages. Stripe redirects the visitor's
// new tab here after success / cancel. These are intentionally
// no-frills pages — the real status of payment_intents flips via the
// webhook, not via these URLs (which a malicious visitor could call
// without paying).
Route::view('/widget/checkout/success', 'widget.checkout-success')
    ->name('widget.checkout.success');
Route::view('/widget/checkout/cancel', 'widget.checkout-cancel')
    ->name('widget.checkout.cancel');

// C3: public knowledge base. Operators flip `kb_published=true` on a
// curated answer to surface it here. Workspace-slug-scoped so two
// workspaces can carry an identically-titled article without
// conflict. Rate-limited per IP inside the controller.
Route::get('/kb/{workspace}', [KnowledgeBaseController::class, 'index'])
    ->name('kb.index');
Route::get('/kb/{workspace}/{slug}', [KnowledgeBaseController::class, 'show'])
    ->name('kb.show');

Route::middleware(RedirectMarketingWhenDisabled::class)->group(function () {
    Route::get('/', [MarketingController::class, 'home'])->name('home');
    Route::get('/pricing', [MarketingController::class, 'pricing'])->name('marketing.pricing');
    Route::get('/how-it-works', [MarketingController::class, 'howItWorks'])->name('marketing.how-it-works');
    Route::get('/integrations', [MarketingController::class, 'integrations'])->name('marketing.integrations');

    // Documentation + public changelog — sales / support surfaces
    // that should hide when the install is private.
    Route::get('documentation/{slug?}', [DocumentationController::class, 'show'])
        ->where('slug', '[a-z0-9-]+')
        ->name('documentation.show');
    Route::get('changelog', [ChangelogController::class, 'show'])
        ->name('changelog.show');
    Route::get('changelog.json', [ChangelogController::class, 'feed'])
        ->name('changelog.feed');
});

// Always public regardless of the marketing toggle — /privacy and
// /terms are linked from the auth screens and have to stay
// reachable.
Route::get('/privacy', [MarketingController::class, 'privacy'])->name('marketing.privacy');
Route::get('/terms', [MarketingController::class, 'terms'])->name('marketing.terms');
Route::post('/marketing/start', [MarketingController::class, 'start'])->name('marketing.start');

// Admin-authored custom pages. Slug-bound public route — unpublished
// drafts 404 from the controller, so a guessable slug doesn't leak a
// draft URL.
Route::get('/p/{slug}', [PageController::class, 'show'])
    ->where('slug', '[a-z0-9-]+')
    ->name('pages.public');

// SEO — sitemap + robots.txt. Both cached for an hour at the
// controller level so freshly-published changelog versions show up
// within the cache window without us paying recompute cost on every
// crawler visit. SeoController honours the marketing-site-enabled
// flag — sitemap returns empty + robots disallows everything when
// the install is private.
Route::get('/sitemap.xml', [SeoController::class, 'sitemap'])->name('seo.sitemap');
Route::get('/robots.txt', [SeoController::class, 'robots'])->name('seo.robots');

// One-shot acknowledgement endpoint — the What's-new banner POSTs
// here when the user dismisses it, stamping last_changelog_seen_at.
Route::post('changelog/seen', ChangelogSeenController::class)
    ->middleware('auth')
    ->name('changelog.seen');

// Public locale switch + geo-banner dismiss. No auth required so the
// banner works on the marketing site and for unauth visitors. The
// switch endpoint sets `pb_locale` cookie; LocaleController::update
// also persists `users.locale` when the visitor is signed in.
Route::patch('locale/switch', [LocaleController::class, 'update'])
    ->name('locale.switch');
Route::post('locale/dismiss-suggestion', [LocaleController::class, 'dismissSuggestion'])
    ->name('locale.dismiss-suggestion');

// Public invitation landing (login required to accept).
Route::get('invitations/{token}', [InvitationController::class, 'show'])->name('invitations.show');
Route::post('invitations/{token}/accept', [InvitationController::class, 'accept'])
    ->middleware('auth')
    ->name('invitations.accept');

// Payment-gateway webhooks (no CSRF, no auth). Each gateway gets its own
// signed endpoint — Stripe is preserved at the historical /billing/webhook
// path so already-configured Stripe dashboards don't need re-pointing.
Route::post('billing/webhook', [BillingWebhookController::class, 'handleWebhook'])->name('billing.webhook');
Route::post('billing/webhook/paypal', PayPalWebhookController::class)->name('billing.webhook.paypal');
Route::post('billing/webhook/razorpay', RazorpayWebhookController::class)->name('billing.webhook.razorpay');

Route::middleware(['auth', 'verified'])->group(function () {
    // ───────────────────────────────────────────────────────────
    // CUSTOMER surface — every route below this line is a tenant
    // view. Super-admins get bounced to /admin by redirect.super_admin
    // (their proper home). They impersonate to see customer views.
    // ───────────────────────────────────────────────────────────
    Route::middleware('redirect.super_admin')->group(function () {

        // No-workspace-OK routes — these handle the empty state themselves
        // or are how the user GETS a workspace (workspace switcher / signup).
        Route::get('dashboard', [DashboardController::class, 'show'])->name('dashboard');
        Route::get('onboarding', [OnboardingController::class, 'show'])->name('onboarding');
        Route::get('api/v1/agents/{agent}/onboarding-status', [OnboardingController::class, 'status'])->name('onboarding.status');

        Route::post('workspaces/{workspace}/select', [WorkspaceSelectController::class, 'store'])
            ->name('workspaces.select');

        // Buyer-facing create-another-workspace endpoint. Enforces
        // `plans.workspaces_limit` cap; returns ValidationException
        // with an upgrade-prompt message when exceeded.
        Route::post('workspaces', [WorkspaceController::class, 'store'])
            ->name('workspaces.store');

        // Admin presence heartbeat. Throttle is generous — a 60s
        // interval × multiple tabs × occasional retry = ~10/min ceiling
        // is comfortable for a single admin user.
        Route::post('app/me/presence', PresenceController::class)
            ->middleware('throttle:30,1')
            ->name('me.presence');

        // Everything below requires a current workspace. Without one, the
        // middleware redirects to /dashboard with a flash explaining why.
        // `trial.active` walls the surface once a no-card trial expires —
        // billing.* routes are allow-listed so the upgrade path stays open.
        Route::middleware(['workspace.require', 'trial.active'])->group(function () {

            // Live chat settings (workspace-level: webhooks, business
            // hours, operator personalization)
            Route::get('app/settings/live-chat', [LiveChatSettingsController::class, 'show'])->name('settings.live-chat');
            Route::patch('app/settings/live-chat', [LiveChatSettingsController::class, 'update'])->name('settings.live-chat.update');

            // Live human takeover
            Route::post('app/conversations/{conversation}/claim', [ConversationTakeoverController::class, 'claim'])->name('conversations.claim');
            Route::post('app/conversations/{conversation}/release', [ConversationTakeoverController::class, 'release'])->name('conversations.release');
            Route::post('app/conversations/{conversation}/reply', [ConversationTakeoverController::class, 'reply'])->name('conversations.reply');
            // Phase 3 takeover extensions: internal notes (operator-only),
            // typing indicator (debounced), conversation transfer.
            Route::post('app/conversations/{conversation}/note', [ConversationTakeoverController::class, 'note'])->name('conversations.note');
            Route::post('app/conversations/{conversation}/typing', [ConversationTakeoverController::class, 'typing'])
                ->middleware('throttle:60,1')
                ->name('conversations.typing');
            Route::post('app/conversations/{conversation}/transfer', [ConversationTakeoverController::class, 'transfer'])->name('conversations.transfer');

            // Conversation tags — workspace-scoped CRUD + apply/detach
            // on individual conversations.
            Route::get('app/settings/tags', [ConversationTagController::class, 'index'])->name('tags.index');
            Route::post('app/settings/tags', [ConversationTagController::class, 'store'])->name('tags.store');
            Route::patch('app/settings/tags/{tag}', [ConversationTagController::class, 'update'])->name('tags.update');
            Route::delete('app/settings/tags/{tag}', [ConversationTagController::class, 'destroy'])->name('tags.destroy');
            Route::post('app/conversations/{conversation}/tags/{tag}', [ConversationTagAssignmentController::class, 'attach'])->name('conversations.tags.attach');
            Route::delete('app/conversations/{conversation}/tags/{tag}', [ConversationTagAssignmentController::class, 'detach'])->name('conversations.tags.detach');

            // Canned replies — workspace-scoped via BelongsToWorkspace.
            // `/reorder` declared before the `/{cannedReply}` PATCH so
            // it doesn't match against the wildcard.
            Route::get('app/settings/canned-replies', [CannedReplyController::class, 'index'])->name('canned-replies.index');
            Route::post('app/settings/canned-replies', [CannedReplyController::class, 'store'])->name('canned-replies.store');
            Route::patch('app/settings/canned-replies/reorder', [CannedReplyController::class, 'reorder'])->name('canned-replies.reorder');
            Route::patch('app/settings/canned-replies/{cannedReply}', [CannedReplyController::class, 'update'])->name('canned-replies.update');
            Route::delete('app/settings/canned-replies/{cannedReply}', [CannedReplyController::class, 'destroy'])->name('canned-replies.destroy');

            Route::get('app/integrations', [IntegrationController::class, 'index'])->name('integrations.index');
            Route::post('app/integrations/slack', [IntegrationController::class, 'storeSlack'])->name('integrations.slack.store');
            Route::post('app/integrations/webhooks', [IntegrationController::class, 'storeWebhook'])->name('integrations.webhooks.store');
            Route::patch('app/integrations/webhooks/{webhookSubscription}', [IntegrationController::class, 'updateWebhook'])->name('integrations.webhooks.update');
            Route::delete('app/integrations/webhooks/{webhookSubscription}', [IntegrationController::class, 'destroyWebhook'])->name('integrations.webhooks.destroy');
            Route::post('app/integrations/webhooks/{webhookSubscription}/rotate-secret', [IntegrationController::class, 'rotateWebhookSecret'])->name('integrations.webhooks.rotate');
            Route::delete('app/integrations/{integration}', [IntegrationController::class, 'destroy'])->name('integrations.destroy');

            Route::get('app/members', [MemberController::class, 'index'])->name('members.index');
            Route::post('app/members', [MemberController::class, 'store'])->name('members.store');
            Route::delete('app/members/{member}', [MemberController::class, 'destroy'])->name('members.destroy');
            Route::delete('app/invitations/{invitation}', [InvitationController::class, 'destroy'])->name('invitations.destroy');

            // GDPR / CCPA data-subject-request endpoints. Admins+ only.
            Route::post('app/dsr/lookup', [DsrController::class, 'lookup'])->name('dsr.lookup');
            Route::post('app/dsr/export', [DsrController::class, 'export'])->name('dsr.export');
            Route::post('app/dsr/erase', [DsrController::class, 'erase'])->name('dsr.erase');

            Route::get('app/audit', [AuditController::class, 'index'])->name('audit.index');

            // Agents CRUD
            Route::get('app/agents', [AgentController::class, 'index'])->name('agents.index');
            Route::get('app/agents/create', [AgentController::class, 'create'])->name('agents.create');
            Route::post('app/agents', [AgentController::class, 'store'])->name('agents.store');
            Route::get('app/agents/{agent}', [AgentController::class, 'show'])->name('agents.show');
            Route::get('app/agents/{agent}/settings', [AgentController::class, 'edit'])->name('agents.edit');
            // GET pages — customize + vertical — moved to controller
            // methods (audit 2026-05-16) so `php artisan route:cache`
            // compiles the full route map on deploy. The POST endpoints
            // below remain single-action controllers as before.
            Route::get('app/agents/{agent}/customize', [AgentController::class, 'customize'])->name('agents.customize');
            Route::get('app/agents/{agent}/vertical', [AgentController::class, 'vertical'])->name('agents.vertical');

            // MCP (Model Context Protocol) integration — per-agent
            // server attachments + tool whitelist. Each agent in a
            // workspace has its own set of connected MCP servers and
            // its own per-tool grants. Authorisation lives in the
            // controller; the workspace + auth middleware groups
            // enforce login + workspace context.
            Route::get('app/agents/{agent}/mcp', [McpServerController::class, 'index'])->name('agents.mcp.index');
            Route::post('app/agents/{agent}/mcp', [McpServerController::class, 'store'])->name('agents.mcp.store');
            Route::delete('app/agents/{agent}/mcp/{mcpServer}', [McpServerController::class, 'destroy'])->name('agents.mcp.destroy');
            Route::post('app/agents/{agent}/mcp/{mcpServer}/test', [McpServerController::class, 'testConnection'])->name('agents.mcp.test');
            Route::post('app/agents/{agent}/mcp/{mcpServer}/refresh', [McpServerController::class, 'refreshTools'])->name('agents.mcp.refresh');
            Route::get('app/agents/{agent}/mcp/{mcpServer}/tools', [McpServerController::class, 'tools'])->name('agents.mcp.tools');
            Route::patch('app/agents/{agent}/mcp/{mcpServer}/tools', [McpServerController::class, 'bulkUpdateGrants'])->name('agents.mcp.tools.bulk');
            Route::get('app/agents/{agent}/mcp/{mcpServer}/activity', [McpActivityController::class, 'show'])->name('agents.mcp.activity');
            Route::post('app/agents/{agent}/vertical/detect', DetectController::class)
                ->name('agents.vertical.detect');
            Route::post('app/agents/{agent}/vertical/apply', ApplyController::class)
                ->name('agents.vertical.apply');
            Route::patch('app/agents/{agent}', [AgentController::class, 'update'])->name('agents.update');
            Route::delete('app/agents/{agent}', [AgentController::class, 'destroy'])->name('agents.destroy');
            Route::post('app/agents/bulk-destroy', [AgentController::class, 'bulkDestroy'])->name('agents.bulkDestroy');
            Route::post('app/agents/{agent}/publish', [AgentController::class, 'publish'])->name('agents.publish');
            Route::post('app/agents/{agent}/rollback', [AgentController::class, 'rollback'])->name('agents.rollback');

            // Curated answers / behavior rules / CTA rules
            Route::get('app/agents/{agent}/curated', [CuratedAnswerController::class, 'index'])->name('agents.curated.index');
            Route::post('app/agents/{agent}/curated', [CuratedAnswerController::class, 'store'])->name('agents.curated.store');
            Route::patch('app/curated-answers/{curatedAnswer}', [CuratedAnswerController::class, 'update'])->name('curated.update');
            Route::post('app/curated-answers/{curatedAnswer}/approve', [CuratedAnswerController::class, 'approve'])->name('curated.approve');
            Route::delete('app/curated-answers/{curatedAnswer}', [CuratedAnswerController::class, 'destroy'])->name('curated.destroy');
            Route::post('app/agents/{agent}/curated/reorder', [CuratedAnswerController::class, 'reorder'])->name('curated.reorder');

            Route::get('app/agents/{agent}/behavior', [BehaviorRuleController::class, 'index'])->name('agents.behavior.index');
            Route::post('app/agents/{agent}/behavior', [BehaviorRuleController::class, 'store'])->name('agents.behavior.store');
            Route::patch('app/behavior-rules/{behaviorRule}', [BehaviorRuleController::class, 'update'])->name('behavior.update');
            Route::delete('app/behavior-rules/{behaviorRule}', [BehaviorRuleController::class, 'destroy'])->name('behavior.destroy');

            Route::get('app/agents/{agent}/ctas', [CtaRuleController::class, 'index'])->name('agents.ctas.index');
            Route::post('app/agents/{agent}/ctas', [CtaRuleController::class, 'store'])->name('agents.ctas.store');
            Route::patch('app/cta-rules/{ctaRule}', [CtaRuleController::class, 'update'])->name('cta.update');
            Route::delete('app/cta-rules/{ctaRule}', [CtaRuleController::class, 'destroy'])->name('cta.destroy');

            // Workspace-wide quick search — agents, conversations, leads
            Route::get('app/search', SearchController::class)->name('search');

            // Knowledge — what the AI actually has indexed (per agent)
            Route::get('app/agents/{agent}/knowledge', [KnowledgeController::class, 'index'])->name('agents.knowledge.index');
            Route::post('app/documents/{document}/reindex', [KnowledgeController::class, 'reindex'])->name('documents.reindex');

            // Conversations log — every visitor session for an agent
            Route::get('app/conversations', [ConversationController::class, 'workspaceIndex'])->name('conversations.index');
            Route::get('app/agents/{agent}/conversations', [ConversationController::class, 'index'])->name('agents.conversations.index');
            Route::get('app/conversations/{conversation}', [ConversationController::class, 'show'])->name('conversations.show');
            Route::post('app/conversations/bulk-destroy', [ConversationController::class, 'bulkDestroy'])->name('conversations.bulk-destroy');
            Route::delete('app/conversations/{conversation}', [ConversationController::class, 'destroy'])->name('conversations.destroy');

            // Knowledge sources (URL/sitemap crawl)
            Route::get('app/agents/{agent}/sources', [SourceController::class, 'index'])->name('agents.sources.index');
            Route::post('app/agents/{agent}/sources', [SourceController::class, 'store'])->name('agents.sources.store');
            Route::delete('app/sources/{source}', [SourceController::class, 'destroy'])->name('sources.destroy');
            Route::post('app/sources/{source}/reindex', [SourceController::class, 'reindex'])->name('sources.reindex');
            Route::patch('app/sources/{source}/text', [SourceController::class, 'updateText'])->name('sources.text.update');
            Route::patch('app/sources/{source}/global', [SourceController::class, 'updateGlobal'])->name('sources.global.update');
            Route::get('app/sources/{source}/preview', [SourceController::class, 'preview'])->name('sources.preview');
            Route::post('app/agents/{agent}/sources/discover', [SourceController::class, 'discover'])->name('agents.sources.discover');
            Route::post('app/agents/{agent}/sources/bulk', [SourceController::class, 'bulkStore'])->name('agents.sources.bulk');
            Route::post('app/agents/{agent}/sources/text', [SourceController::class, 'storeText'])->name('agents.sources.text');
            Route::post('app/agents/{agent}/sources/notion', [SourceController::class, 'storeNotion'])->name('agents.sources.notion');
            Route::post('app/agents/{agent}/sources/google-doc', [SourceController::class, 'storeGoogleDoc'])->name('agents.sources.googleDoc');
            Route::post('app/agents/{agent}/sources/google-sheet', [SourceController::class, 'storeGoogleSheet'])->name('agents.sources.googleSheet');
            Route::post('app/agents/{agent}/sources/google-sheet/metadata', [SourceController::class, 'googleSheetMetadata'])->name('agents.sources.googleSheetMetadata');
            Route::post('app/agents/{agent}/sources/sql', [SourceController::class, 'storeSql'])->name('agents.sources.sql');
            Route::get('app/agents/{agent}/leads', [LeadController::class, 'agentIndex'])->name('agents.leads.index');

            // OAuth — Notion
            Route::get('app/oauth/notion/connect', [NotionOAuthController::class, 'start'])->name('oauth.notion.start');
            Route::get('app/oauth/notion/callback', [NotionOAuthController::class, 'callback'])->name('oauth.notion.callback');

            // OAuth — Google
            Route::get('app/oauth/google/connect', [GoogleOAuthController::class, 'start'])->name('oauth.google.start');
            Route::get('app/oauth/google/callback', [GoogleOAuthController::class, 'callback'])->name('oauth.google.callback');

            // File uploads (PDF/DOCX/CSV/MD/TXT)
            Route::post('app/agents/{agent}/uploads', [UploadController::class, 'store'])->name('agents.uploads.store');

            // Launcher icon (image uploaded to public disk, URL stored in agent.theme.launcher_icon_url)
            Route::post('app/agents/{agent}/launcher-icon', [AgentLauncherIconController::class, 'store'])->name('agents.launcher-icon.store');
            Route::delete('app/agents/{agent}/launcher-icon', [AgentLauncherIconController::class, 'destroy'])->name('agents.launcher-icon.destroy');

            // Self-serve diagnostic — flags missing LLM creds, queue
            // worker absence, recently failed crawl/index jobs.
            Route::get('app/system-health', SystemHealthController::class)->name('system-health');
            Route::post('app/system-health/probe-llm', [SystemHealthController::class, 'probe'])->name('system-health.probe-llm');

            // Playground
            Route::get('app/agents/{agent}/playground', [PlaygroundController::class, 'show'])->name('agents.playground');
            Route::post('app/agents/{agent}/playground', [PlaygroundController::class, 'send'])->name('agents.playground.send');
            Route::post('app/agents/{agent}/playground/stream', PlaygroundStreamController::class)->name('agents.playground.stream');
            Route::post('app/agents/{agent}/playground/reset', [PlaygroundController::class, 'reset'])->name('agents.playground.reset');

            // A/B testing experiments
            Route::get('app/agents/{agent}/experiments', [ExperimentController::class, 'index'])->name('agents.experiments.index');
            Route::post('app/agents/{agent}/experiments', [ExperimentController::class, 'store'])->name('agents.experiments.store');
            Route::post('app/experiments/{experiment}/start', [ExperimentController::class, 'start'])->name('experiments.start');
            Route::post('app/experiments/{experiment}/stop', [ExperimentController::class, 'stop'])->name('experiments.stop');
            Route::delete('app/experiments/{experiment}', [ExperimentController::class, 'destroy'])->name('experiments.destroy');

            // Inbox
            Route::get('app/inbox', [LeadController::class, 'index'])->name('inbox.index');
            Route::get('app/inbox/{lead}', [LeadController::class, 'show'])->name('inbox.show');
            Route::patch('app/inbox/{lead}', [LeadController::class, 'update'])->name('inbox.update');
            Route::post('app/inbox/bulk-destroy', [LeadController::class, 'bulkDestroy'])->name('inbox.bulkDestroy');
            Route::delete('app/inbox/{lead}', [LeadController::class, 'destroy'])->name('inbox.destroy');

            // Tiny JSON poll endpoint hit by the admin shell every ~30s
            // so a workspace member sees a sonner toast + (with permission)
            // a native browser notification the moment a lead lands.
            Route::get('app/leads/feed', LeadFeedController::class)
                ->name('leads.feed');

            // Workflow builder. Phase 1 shipped linear keyword-triggered
            // sequences of message / question / escalate steps. The runtime
            // engine in App\Services\Workflows\WorkflowEngine consumes the
            // exact JSON shape the form posts (`definition.steps`).
            Route::get('app/workflows', [WorkflowController::class, 'index'])->name('workflows.index');
            Route::get('app/workflows/create', [WorkflowController::class, 'create'])->name('workflows.create');
            Route::post('app/workflows', [WorkflowController::class, 'store'])->name('workflows.store');
            // Stateless canvas test-run — dry-runs a DRAFT definition, no {workflow} binding on purpose.
            Route::post('app/workflows/simulate', [WorkflowController::class, 'simulate'])->name('workflows.simulate');
            Route::get('app/workflows/{workflow}/edit', [WorkflowController::class, 'edit'])->name('workflows.edit');
            Route::get('app/workflows/{workflow}/canvas', [WorkflowController::class, 'canvas'])->name('workflows.canvas');
            Route::patch('app/workflows/{workflow}', [WorkflowController::class, 'update'])->name('workflows.update');
            Route::delete('app/workflows/{workflow}', [WorkflowController::class, 'destroy'])->name('workflows.destroy');
            Route::post('app/workflows/bulk-destroy', [WorkflowController::class, 'bulkDestroy'])->name('workflows.bulkDestroy');

            // Analytics
            Route::get('app/analytics', [AnalyticsController::class, 'overview'])->name('analytics.overview');
            Route::get('app/analytics/content-gaps', [AnalyticsController::class, 'contentGaps'])->name('analytics.content-gaps');
            Route::post('app/analytics/gaps/{contentGap}/resolve', [AnalyticsController::class, 'resolveGap'])->name('analytics.gaps.resolve');

            Route::get('app/conversation-exports', [ConversationExportController::class, 'index'])->name('conversations.export.index');
            Route::post('app/conversation-exports', [ConversationExportController::class, 'store'])->name('conversations.export.store');
            Route::get('app/conversation-exports/{conversationExport}/download', [ConversationExportController::class, 'download'])->name('conversations.export.download');

            // Billing
            Route::get('app/billing', [BillingController::class, 'show'])->name('billing.show');
            // C7: native invoice PDF download. White-label vendor +
            // product names get stamped by BillingController.
            Route::get('app/billing/invoices/{invoice}/download', [BillingController::class, 'downloadInvoice'])
                ->name('billing.invoices.download');
            Route::post('billing/checkout', CheckoutController::class)->name('billing.checkout');
            Route::get('billing/portal', [BillingController::class, 'portal'])->name('billing.portal');
            Route::post('billing/cancel', [BillingLifecycleController::class, 'cancel'])->name('billing.cancel');
            Route::post('billing/resume', [BillingLifecycleController::class, 'resume'])->name('billing.resume');
            Route::post('billing/swap', [BillingLifecycleController::class, 'swap'])->name('billing.swap');

            // C3: ticketing surface. Owner / Admin / Editor reach the
            // list + can update; Viewer is read-only (policy enforced).
            Route::get('app/tickets', [TicketController::class, 'index'])
                ->name('tickets.index');
            Route::get('app/tickets/{ticket}', [TicketController::class, 'show'])
                ->name('tickets.show');
            Route::patch('app/tickets/{ticket}', [TicketController::class, 'update'])
                ->name('tickets.update');

        }); // workspace.require group

    }); // redirect.super_admin (customer surface) group

    // ───────────────────────────────────────────────────────────
    // Platform admin (super_admin only). 404 for everyone else.
    // ───────────────────────────────────────────────────────────
    Route::middleware('super_admin')->prefix('admin')->name('admin.')->group(function () {
        Route::get('/', PlatformDashboardController::class)->name('dashboard');

        Route::get('workspaces', [PlatformWorkspaceController::class, 'index'])->name('workspaces.index');
        Route::get('workspaces/{workspace}', [PlatformWorkspaceController::class, 'show'])->name('workspaces.show');
        Route::post('workspaces/bulk-destroy', [PlatformWorkspaceController::class, 'bulkDestroy'])->name('workspaces.bulkDestroy');
        Route::delete('workspaces/{workspace}', [PlatformWorkspaceController::class, 'destroy'])->name('workspaces.destroy');

        Route::get('users', [PlatformUserController::class, 'index'])->name('users.index');
        Route::patch('users/{user}', [PlatformUserController::class, 'update'])->name('users.update');
        Route::patch('users/{user}/role', [PlatformUserController::class, 'updateRole'])->name('users.updateRole');
        Route::patch('users/{user}/byok', [PlatformUserController::class, 'updateByok'])->name('users.updateByok');
        Route::post('users/bulk-destroy', [PlatformUserController::class, 'bulkDestroy'])->name('users.bulkDestroy');
        Route::delete('users/{user}', [PlatformUserController::class, 'destroy'])->name('users.destroy');

        Route::get('agents', [PlatformAgentController::class, 'index'])->name('agents.index');
        Route::patch('agents/{agent}', [PlatformAgentController::class, 'update'])->name('agents.update');
        Route::post('agents/bulk-destroy', [PlatformAgentController::class, 'bulkDestroy'])->name('agents.bulkDestroy');
        Route::delete('agents/{agent}', [PlatformAgentController::class, 'destroy'])->name('agents.destroy');

        // Custom content pages — operator-authored pages at /p/{slug}.
        Route::get('pages', [PlatformPageController::class, 'index'])->name('pages.index');
        Route::get('pages/create', [PlatformPageController::class, 'create'])->name('pages.create');
        Route::post('pages', [PlatformPageController::class, 'store'])->name('pages.store');
        Route::get('pages/{page}/edit', [PlatformPageController::class, 'edit'])->name('pages.edit');
        Route::patch('pages/{page}', [PlatformPageController::class, 'update'])->name('pages.update');
        Route::delete('pages/{page}', [PlatformPageController::class, 'destroy'])->name('pages.destroy');
        Route::post('pages/preview', [PlatformPageController::class, 'preview'])->name('pages.preview');

        // Translation manager — edit any string in any shipped language,
        // stored as DB overrides over lang/{locale}.json. Gated by the
        // MULTILINGUAL_ENABLED flag (the controller 404s when off).
        Route::get('translations', [PlatformTranslationController::class, 'index'])->name('translations.index');
        Route::get('translations/{locale}', [PlatformTranslationController::class, 'show'])->name('translations.show');
        Route::put('translations/{locale}', [PlatformTranslationController::class, 'update'])->name('translations.update');
        Route::delete('translations/{locale}', [PlatformTranslationController::class, 'destroy'])->name('translations.destroy');

        Route::get('conversations', [PlatformConversationController::class, 'index'])->name('conversations.index');
        Route::get('conversations/{conversation}', [PlatformConversationController::class, 'show'])->name('conversations.show');
        Route::post('conversations/bulk-destroy', [PlatformConversationController::class, 'bulkDestroy'])->name('conversations.bulkDestroy');
        Route::delete('conversations/{conversation}', [PlatformConversationController::class, 'destroy'])->name('conversations.destroy');

        Route::get('leads', [PlatformLeadController::class, 'index'])->name('leads.index');
        Route::post('leads/bulk-destroy', [PlatformLeadController::class, 'bulkDestroy'])->name('leads.bulkDestroy');
        Route::delete('leads/{lead}', [PlatformLeadController::class, 'destroy'])->name('leads.destroy');
        Route::get('search', PlatformSearchController::class)->name('search');
        Route::get('usage', [PlatformUsageController::class, 'index'])->name('usage.index');
        Route::get('subscriptions', [SubscriptionController::class, 'index'])->name('subscriptions.index');

        // Subscription plan CRUD — admin-managed, auto-syncs to every
        // enabled payment gateway (Stripe + PayPal + Razorpay).
        Route::get('plans', [PlatformPlanController::class, 'index'])->name('plans.index');
        Route::get('plans/create', [PlatformPlanController::class, 'create'])->name('plans.create');
        Route::post('plans', [PlatformPlanController::class, 'store'])->name('plans.store');
        Route::get('plans/{plan}/edit', [PlatformPlanController::class, 'edit'])->name('plans.edit');
        Route::patch('plans/{plan}', [PlatformPlanController::class, 'update'])->name('plans.update');
        // /sync (legacy, defaults to Stripe) + /sync/{gateway} (new, per-gateway).
        Route::post('plans/{plan}/sync', [PlatformPlanController::class, 'sync'])->name('plans.sync');
        Route::post('plans/{plan}/sync/{gateway}', [PlatformPlanController::class, 'sync'])
            ->whereIn('gateway', ['stripe', 'paypal', 'razorpay'])
            ->name('plans.sync.gateway');
        Route::delete('plans/{plan}', [PlatformPlanController::class, 'destroy'])->name('plans.destroy');

        // WordPress companion plugin distribution: super_admin downloads
        // a packaged zip for upload to their own WP install. The build
        // command runs `pitchbar:build-wp-plugin` under the hood.
        Route::get('integrations/wordpress', [WordPressDistributionController::class, 'index'])
            ->name('integrations.wordpress.index');
        Route::post('integrations/wordpress/build', [WordPressDistributionController::class, 'build'])
            ->name('integrations.wordpress.build');
        Route::get('integrations/wordpress/download/{version}', [WordPressDistributionController::class, 'download'])
            ->where('version', '[0-9]+\.[0-9]+\.[0-9]+(?:[-+][A-Za-z0-9.]+)?')
            ->name('integrations.wordpress.download');

        Route::post('impersonate/{user}/start', [ImpersonateController::class, 'start'])->name('impersonate.start');

        // Queue health — RUNNING / DONE / FAILED job feed + cron tick
        // history. Same payload the admin dashboard widget polls, but
        // dedicated full-width page accessible from the sidebar so the
        // platform-admin can park it during a long debug session.
        Route::get('queue-health', [PlatformJobController::class, 'queueHealth'])->name('queue-health');

        // Failed-jobs inspection
        Route::get('jobs/failed', [PlatformJobController::class, 'failed'])->name('jobs.failed');
        Route::get('jobs/failed/{uuid}', [PlatformJobController::class, 'show'])->name('jobs.failed.show');
        Route::post('jobs/failed/{uuid}/retry', [PlatformJobController::class, 'retry'])->name('jobs.failed.retry');
        Route::post('jobs/failed/{uuid}/forget', [PlatformJobController::class, 'forget'])->name('jobs.failed.forget');
        Route::post('jobs/failed/retry-all', [PlatformJobController::class, 'retryAll'])->name('jobs.failed.retryAll');
        Route::post('jobs/failed/flush', [PlatformJobController::class, 'flush'])->name('jobs.failed.flush');

        // Internal Kanban — backlog/doing/review/done. Persists across
        // sessions on the AppSetting singleton so a future Claude
        // session reading the board can pick up dormant items even
        // months out.
        Route::get('board', [KanbanBoardController::class, 'index'])->name('board.index');
        Route::post('board', [KanbanBoardController::class, 'store'])->name('board.store');
        Route::patch('board/{taskId}', [KanbanBoardController::class, 'update'])->name('board.update');
        Route::delete('board/{taskId}', [KanbanBoardController::class, 'destroy'])->name('board.destroy');

        // Application changelog — super_admin authoring. Public reads
        // sit at /changelog. Entries live in a JSON store
        // (storage/app/private/changelog-entries.json), not the
        // database, so we resolve {entry} via an explicit binding
        // rather than Laravel's default Eloquent route-model binding.
        Route::bind('entry', function (string $value) {
            return ChangelogEntry::findById($value)
                ?? abort(404);
        });
        Route::get('changelog', [App\Http\Controllers\Admin\Platform\ChangelogController::class, 'index'])->name('changelog.index');
        Route::get('changelog/create', [App\Http\Controllers\Admin\Platform\ChangelogController::class, 'create'])->name('changelog.create');
        Route::post('changelog', [App\Http\Controllers\Admin\Platform\ChangelogController::class, 'store'])->name('changelog.store');
        Route::get('changelog/{entry}/edit', [App\Http\Controllers\Admin\Platform\ChangelogController::class, 'edit'])->name('changelog.edit');
        Route::patch('changelog/{entry}', [App\Http\Controllers\Admin\Platform\ChangelogController::class, 'update'])->name('changelog.update');
        Route::post('changelog/{entry}/publish', [App\Http\Controllers\Admin\Platform\ChangelogController::class, 'publish'])->name('changelog.publish');
        Route::delete('changelog/{entry}', [App\Http\Controllers\Admin\Platform\ChangelogController::class, 'destroy'])->name('changelog.destroy');
    });

    // /stop is reachable from anywhere while impersonating; gated by an active session key, not by role.
    Route::post('impersonate/stop', [ImpersonateController::class, 'stop'])->name('impersonate.stop');
});

require __DIR__.'/settings.php';

// Friendly bare-slug landing — clients keep pasting `/<workspace-slug>`
// into the browser thinking it's their workspace URL. The slug isn't
// a route on its own (the marketing site owns root), so the request
// used to fall through to Laravel's default 404. Now we look up the
// workspace by slug and, if the customer published a knowledge base,
// 302 to `/kb/{slug}`. Workspaces that don't exist still 404 — but
// at least typoed-slug-of-a-real-workspace lands somewhere useful.
Route::fallback(function (Request $request) {
    $path = trim($request->path(), '/');

    // Single-segment paths only — multi-segment fallbacks belong to
    // their owning controller.
    if ($path === '' || str_contains($path, '/')) {
        abort(404);
    }

    // Strict slug shape so we never run a DB query on garbage paths.
    if (! preg_match('/^[a-z0-9][a-z0-9-]{2,80}$/i', $path)) {
        abort(404);
    }

    $workspace = Workspace::query()
        ->withoutGlobalScopes()
        ->where('slug', $path)
        ->first();

    if ($workspace === null) {
        abort(404);
    }

    return redirect()->route('kb.index', ['workspace' => $workspace->slug]);
});
