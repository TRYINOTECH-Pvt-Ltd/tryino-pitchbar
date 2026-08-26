<?php

namespace App\Http\Middleware;

use App\Models\ChangelogEntry;
use App\Models\Conversation;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Billing\MeteredBilling;
use App\Services\Billing\PlanLimits;
use App\Services\I18n\LocaleCatalog;
use App\Services\I18n\LocaleResolver;
use App\Services\I18n\TranslationLoader;
use App\Support\AppBranding;
use App\Support\ByokResolver;
use App\Support\MarketingTheme;
use App\Support\MarketingWidget;
use App\Support\PlatformAdminHeader;
use App\Support\Tenancy;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $this->flashToastFromSession($request);

        $user = $request->user();
        $branding = AppBranding::shared();

        $impersonatorId = $request->session()->get('impersonator_id');
        $impersonator = $impersonatorId
            ? User::query()->find($impersonatorId)?->only('id', 'name', 'email')
            : null;

        return [
            ...parent::share($request),
            'name' => $branding['site_title'],
            'branding' => $branding,
            'auth' => [
                'user' => $user ? [
                    ...$user->toArray(),
                    'role' => $user->role?->value ?? 'customer',
                    'is_super_admin' => $user->isSuperAdmin(),
                ] : null,
                'needs_onboarding' => fn () => $this->needsOnboarding($user),
            ],
            'impersonating' => $impersonator,
            'currentWorkspace' => fn () => $user?->defaultWorkspace,
            // Role of the authenticated user inside their current
            // workspace ('owner' | 'admin' | 'editor' | 'viewer' | null).
            // Drives sidebar visibility so non-admin members don't see
            // entries that 403 on click (Workspace name, Members, etc).
            // Buyer-reported (2026-05-20): customer member account hit
            // 403 walls clicking visible nav rows.
            'workspaceRole' => fn () => $this->workspaceRole($user),
            'workspaces' => fn () => $user
                ? $user->workspaces()
                    ->select('workspaces.id', 'workspaces.name', 'workspaces.slug')
                    ->withPivot('role')
                    ->get()
                : [],
            'flash' => [
                'success' => $request->session()->get('success'),
                'error' => $request->session()->get('error'),
            ],
            'billingSummary' => fn () => $this->billingSummary($user),
            // Trial countdown / expiry state for the current workspace.
            // Null when the workspace isn't on a trial at all. Drives the
            // app-shell "X days left" banner and the expired upgrade wall.
            'trial' => fn () => $this->trialSummary($user),
            // Per-resource plan-limit snapshot for the current
            // workspace. Lets every Inertia page disable "Add" buttons +
            // surface an upgrade nudge BEFORE the operator wastes time
            // picking 10 sources only to hit a 3-source server-side
            // cap. Buyer ask 2026-05-19. Closure so the DB read only
            // fires when the page actually consumes the prop.
            'workspaceLimits' => fn () => $this->workspaceLimits($user),
            'adminHeader' => fn () => $user?->isSuperAdmin()
                ? app(PlatformAdminHeader::class)->payload()
                : null,
            'changelogTeaser' => fn () => $this->changelogTeaser($user),
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
            // Demo-mode flag. When DEMO=true in env, the marketing site
            // opens Login + Get started in a new tab so a reviewer
            // exploring the live demo (typically a CodeCanyon preview)
            // doesn't lose the marketing page when they hop into the
            // app. Drives the same UX in the Blade marketing layout.
            'demo' => (bool) config('demo.enabled', false),
            // Slug of the currently active marketing theme. Exposed on
            // every page so non-marketing surfaces (auth flows, public
            // /p/{slug} pages) can match the brand identity of the
            // marketing site. Client report 2026-05-23: "when we
            // change theme, all the frontend pages should follow the
            // theme style — login page remains the default theme".
            'marketingTheme' => MarketingTheme::active(),
            // Marketing-widget mount payload. Lazy callable so admin /
            // auth routes don't pay the AppSetting lookup. Drives the
            // React-side reactive remount in
            // `resources/js/components/marketing-widget-mount.tsx` — when
            // the operator toggles the widget in /settings/system, the
            // next Inertia visit (or visibilitychange tab focus) on the
            // marketing tab refreshes this prop and the component
            // injects / tears down the script tag accordingly. Without
            // this, the Blade-emitted script only re-evaluates on full
            // page reload — client report 2026-05-25: "I have to Ctrl+R
            // to hide the widget from the marketing site".
            'marketingWidget' => fn () => MarketingWidget::payload((bool) $user),
            'i18n' => [
                'locale' => app()->getLocale(),
                'fallback' => LocaleResolver::DEFAULT,
                'supported' => app(LocaleResolver::class)->supported(),
                'catalog' => LocaleCatalog::hydrate(app(LocaleResolver::class)->supported()),
                'translations' => app(TranslationLoader::class)->jsonFor(app()->getLocale()),
                // Master switch for the language layer. When false the
                // React side hides the locale picker + Translation Manager.
                'multilingual' => (bool) config('app.multilingual_enabled'),
            ],
            // Geo-suggested locale banner (#49). Resolves to null when
            // the visitor already dismissed it, when the country isn't
            // in our COUNTRY_TO_LOCALE map, or when the suggestion
            // matches the current locale anyway. Lazy callable so the
            // header read + cookie check only happens when the page
            // actually renders the banner slot.
            'localeSuggestion' => fn () => app(LocaleResolver::class)
                ->suggestionFor($request, app()->getLocale()),
            // Live-handoff signals for the sidebar Conversations badge.
            // Lazy callable so the count only runs when the sidebar
            // actually consumes the prop, and never on routes that don't
            // render the admin shell.
            'liveHandoff' => fn () => $this->liveHandoffCounts($user),
            // BYOK gate. Drives the conditional "AI keys" entry in
            // SettingsLayout's sidebar — without this, a customer
            // whose operator turned on BYOK has no way to discover
            // /settings/byok-keys. `unlocked=true` when the same
            // ByokResolver the LLM/Vector binders use says the user
            // × default workspace pair has BYOK access; the page
            // 404s for anyone else, so showing the link conditionally
            // is what matters. Lazy so unauthed visitors don't pay.
            'byok' => fn () => $this->byokFlag($user),
        ];
    }

    /**
     * Workspace role of the authenticated user in their current
     * workspace. Returns null for unauthenticated users or members
     * with no row in the pivot. Used by SettingsLayout to gate
     * sidebar entries so non-admin members don't see options that
     * 403 on click.
     */
    private function workspaceRole(?User $user): ?string
    {
        if ($user === null) {
            return null;
        }

        $workspace = $user->defaultWorkspace;
        if ($workspace === null) {
            return null;
        }

        $role = Tenancy::roleFor($user, $workspace);

        return $role?->value;
    }

    /**
     * @return array{unlocked: bool}|null
     */
    private function byokFlag(?User $user): ?array
    {
        if ($user === null) {
            return null;
        }
        // Super-admins manage platform keys via /settings/system, NOT
        // workspace-level BYOK. Returning null here hides the "AI keys"
        // entry from their sidebar — the page wasn't intended for them
        // and surfacing it routes operators to the wrong screen.
        if ($user->isSuperAdmin()) {
            return null;
        }
        $workspace = $user->defaultWorkspace;

        return ['unlocked' => app(ByokResolver::class)->isUnlockedFor($user, $workspace)];
    }

    /**
     * Counts that drive the sidebar "Needs human" badge + the toast on
     * new live-handoff requests. Single indexed query (uses the
     * `conversations_needs_human_idx` compound index added in the
     * Phase 1 migration). Returns nulls for unauth or super-admin
     * users so the sidebar renders cleanly.
     *
     * @return array{needs_human: int, live_now: int}|null
     */
    private function liveHandoffCounts(?User $user): ?array
    {
        if ($user === null || $user->default_workspace_id === null) {
            return null;
        }

        if ($user->isSuperAdmin()) {
            return null;
        }

        $needs = (int) Conversation::query()
            ->whereHas('agent', fn ($q) => $q->where('workspace_id', $user->default_workspace_id))
            ->whereNotNull('human_requested_at')
            ->whereNull('claimed_by_user_id')
            ->where('is_playground', false)
            ->count();

        $live = (int) Conversation::query()
            ->whereHas('agent', fn ($q) => $q->where('workspace_id', $user->default_workspace_id))
            ->whereNotNull('claimed_by_user_id')
            ->where('is_playground', false)
            ->count();

        return ['needs_human' => $needs, 'live_now' => $live];
    }

    /**
     * One-row peek at the most recent published changelog entry.
     * The "What's new" banner reads it to decide whether to show.
     * The actual badge logic compares released_at to the user's
     * last_changelog_seen_at — done client-side so the controller
     * can stay agnostic of seen/unseen state.
     *
     * Cheap: one indexed `where status='published'` lookup. Skipped
     * entirely for unauthenticated visitors and super_admins.
     */
    private function changelogTeaser(?User $user): ?array
    {
        if ($user === null) {
            return null;
        }
        // Super-admins author the changelog; they don't need a
        // "what's new" badge nudging them about their own entries.
        if ($user->isSuperAdmin()) {
            return null;
        }

        $latest = ChangelogEntry::published()->first();

        if ($latest === null) {
            return null;
        }

        return [
            'version' => $latest->version,
            'released_at' => $latest->released_at?->toIso8601String(),
            'title' => $latest->title,
            'last_seen_at' => $user->last_changelog_seen_at?->toIso8601String(),
        ];
    }

    /**
     * Promote existing session success/error messages into Inertia's
     * one-time flash channel so the global toaster can display them
     * without persisting stale messages in browser history.
     */
    private function flashToastFromSession(Request $request): void
    {
        if (! $request->hasSession()) {
            return;
        }

        $flashed = Inertia::getFlashed($request);

        if (isset($flashed['toast'])) {
            return;
        }

        $error = $request->session()->get('error');

        if (is_string($error) && trim($error) !== '') {
            Inertia::flash('toast', [
                'type' => 'error',
                'message' => $error,
            ]);

            return;
        }

        $success = $request->session()->get('success');

        if (is_string($success) && trim($success) !== '') {
            Inertia::flash('toast', [
                'type' => 'success',
                'message' => $success,
            ]);
        }
    }

    /**
     * Per-resource plan limit snapshot for the current workspace.
     * Each entry is `{allowed, limit, current, remaining}` from
     * PlanLimits::check(). Null limit = unlimited. Frontend disables
     * the matching "Add" button + surfaces a "Upgrade to lift this
     * cap" nudge when `allowed === false`.
     *
     * @return array<string, array{allowed: bool, limit: ?int, current: int, remaining: ?int}>|null
     */
    private function workspaceLimits(?User $user): ?array
    {
        if ($user === null || $user->default_workspace_id === null) {
            return null;
        }
        if ($user->isSuperAdmin()) {
            return null;
        }
        $workspace = $user->defaultWorkspace;
        if ($workspace === null) {
            return null;
        }

        $limits = app(PlanLimits::class);

        return [
            'agent' => $limits->check($workspace, PlanLimits::RESOURCE_AGENT),
            'source' => $limits->check($workspace, PlanLimits::RESOURCE_SOURCE),
            'workflow' => $limits->check($workspace, PlanLimits::RESOURCE_WORKFLOW),
            'integration' => $limits->check($workspace, PlanLimits::RESOURCE_INTEGRATION),
            'member' => $limits->check($workspace, PlanLimits::RESOURCE_MEMBER),
        ];
    }

    /**
     * Slim usage summary so the admin shell can show a "near limit" banner
     * without an extra request. Cheap — one indexed sum() over usage_events.
     */
    private function billingSummary(?User $user): ?array
    {
        if ($user === null || $user->default_workspace_id === null) {
            return null;
        }
        // Super-admins look at customer subscriptions from /admin/subscriptions;
        // they shouldn't see a "near limit" banner pinned to their own
        // (probably empty) test workspace.
        if ($user->isSuperAdmin()) {
            return null;
        }
        $workspace = Workspace::query()->find($user->default_workspace_id);
        if ($workspace === null) {
            return null;
        }

        return app(MeteredBilling::class)->summaryFor($workspace);
    }

    /**
     * Trial state for the current workspace, or null when it has no
     * trial subscription. `active` while the trial is running, `expired`
     * once it has lapsed without an upgrade.
     *
     * @return array{active: bool, expired: bool, ends_at: ?string, days_left: ?int}|null
     */
    private function trialSummary(?User $user): ?array
    {
        if ($user === null || $user->default_workspace_id === null || $user->isSuperAdmin()) {
            return null;
        }

        $workspace = Workspace::query()->find($user->default_workspace_id);
        if ($workspace === null) {
            return null;
        }

        $endsAt = $workspace->trialEndsAt();
        if ($endsAt === null) {
            return null;
        }

        return [
            'active' => $workspace->onTrial(),
            'expired' => $workspace->trialExpired(),
            'ends_at' => $endsAt->toIso8601String(),
            'days_left' => $workspace->trialDaysLeft(),
        ];
    }

    /**
     * "Needs onboarding" = the user's default workspace has no agent with at
     * least one indexed source. Avoids nagging users who've already shipped.
     */
    private function needsOnboarding(?User $user): bool
    {
        if ($user === null) {
            return false;
        }
        if ($user->isSuperAdmin()) {
            return false;
        }
        $workspaceId = $user->default_workspace_id;
        if ($workspaceId === null) {
            return false;
        }

        return ! \DB::table('sources')
            ->join('agents', 'agents.id', '=', 'sources.agent_id')
            ->where('agents.workspace_id', $workspaceId)
            ->where('sources.status', 'indexed')
            ->exists();
    }
}
