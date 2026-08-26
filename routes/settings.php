<?php

use App\Http\Controllers\Admin\Platform\CronWorkerController;
use App\Http\Controllers\Admin\Platform\HotPathLatencyController;
use App\Http\Controllers\Admin\Platform\SystemController as PlatformSystemController;
use App\Http\Controllers\Admin\Platform\WidgetMonitorController;
use App\Http\Controllers\Settings\ByokKeysController;
use App\Http\Controllers\Settings\LocaleController;
use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Settings\SecurityController;
use App\Http\Controllers\Settings\WidgetController as SettingsWidgetController;
use App\Http\Controllers\Settings\WorkspaceApiTokenController;
use App\Http\Controllers\Settings\WorkspaceSettingsController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function () {
    Route::redirect('settings', '/settings/profile');

    Route::get('settings/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('settings/profile', [ProfileController::class, 'update'])->name('profile.update');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::delete('settings/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::get('settings/security', [SecurityController::class, 'edit'])->name('security.edit');

    Route::put('settings/password', [SecurityController::class, 'update'])
        ->middleware('throttle:6,1')
        ->name('user-password.update');

    Route::inertia('settings/appearance', 'settings/appearance')->name('appearance.edit');

    // Per-user UI language. Backed by users.locale; resolved by SetLocale
    // middleware on every subsequent request.
    Route::get('settings/locale', [LocaleController::class, 'edit'])->name('locale.edit');
    Route::patch('settings/locale', [LocaleController::class, 'update'])->name('locale.update');

    // Workspace-level widget defaults — owner sets the colour /
    // persona / starter prompts / max-chars that new agents inherit.
    // Both routes need an active workspace, so they sit alongside the
    // other authed-but-not-platform-admin settings.
    Route::middleware('workspace.require')->group(function () {
        // Customer-side workspace name editor (Admin tier or higher).
        // Buyer-reported gap, 2026-05-15.
        Route::get('settings/workspace', [WorkspaceSettingsController::class, 'edit'])
            ->name('settings.workspace.edit');
        Route::patch('settings/workspace', [WorkspaceSettingsController::class, 'update'])
            ->name('settings.workspace.update');

        Route::get('settings/widget', [SettingsWidgetController::class, 'edit'])
            ->name('settings.widget.edit');
        Route::patch('settings/widget', [SettingsWidgetController::class, 'update'])
            ->name('settings.widget.update');
        Route::post('settings/widget/apply-to-all', [SettingsWidgetController::class, 'applyToAll'])
            ->name('settings.widget.apply-to-all');

        // Workspace API tokens — first-party integrations like the
        // WordPress companion plugin. Admin+ only (policy-enforced).
        Route::get('settings/api-tokens', [WorkspaceApiTokenController::class, 'index'])
            ->name('settings.api-tokens.index');
        Route::post('settings/api-tokens', [WorkspaceApiTokenController::class, 'store'])
            ->name('settings.api-tokens.store');
        Route::delete('settings/api-tokens/{token}', [WorkspaceApiTokenController::class, 'destroy'])
            ->name('settings.api-tokens.destroy');
        Route::delete('settings/api-tokens/{token}/forget', [WorkspaceApiTokenController::class, 'forceDestroy'])
            ->name('settings.api-tokens.force-destroy');
        Route::post('settings/api-tokens/purge-revoked', [WorkspaceApiTokenController::class, 'purgeRevoked'])
            ->name('settings.api-tokens.purge-revoked');

        // C1: BYOK keys form. Route exists for every authed workspace
        // member but 404s inside the controller when ByokResolver
        // returns false for the user × workspace pair, so unauthorized
        // users never even see the page exists.
        Route::get('settings/byok-keys', [ByokKeysController::class, 'edit'])
            ->name('settings.byok-keys.edit');
        Route::patch('settings/byok-keys', [ByokKeysController::class, 'update'])
            ->name('settings.byok-keys.update');
        Route::delete('settings/byok-keys/{provider}', [ByokKeysController::class, 'clear'])
            ->where('provider', 'cloudflare|openai|openrouter|qdrant')
            ->name('settings.byok-keys.clear');
    });
});

// Platform-admin system health page lives under /settings so super_admins
// reach it from the same Settings sidebar customers use for their profile.
// The super_admin middleware 404s for everyone else (existence-hide).
Route::middleware(['auth', 'super_admin'])->group(function () {
    // Platform-wide widget defaults — super_admin sets the colour /
    // persona / starter prompts that NEW workspaces inherit.
    Route::get('settings/widget-defaults', [SettingsWidgetController::class, 'platformEdit'])
        ->name('settings.widget.platform.edit');
    Route::patch('settings/widget-defaults', [SettingsWidgetController::class, 'platformUpdate'])
        ->name('settings.widget.platform.update');

    Route::get('settings/system', [PlatformSystemController::class, 'index'])->name('settings.system.index');
    Route::get('settings/branding', [PlatformSystemController::class, 'branding'])->name('settings.branding.index');
    Route::get('settings/marketing', [PlatformSystemController::class, 'marketing'])->name('settings.marketing.index');
    Route::get('settings/privacy', [PlatformSystemController::class, 'privacy'])->name('settings.privacy.index');
    Route::patch('settings/system/{section}', [PlatformSystemController::class, 'update'])->name('settings.system.update')
        ->where('section', 'stripe|paypal|razorpay|gateways|cloudflare|openai|openrouter|routing|byok|mail|branding|marketing|privacy|notifications|signup|wordpress_plugin|integrations|pricing');
    Route::post('settings/system/test/mail', [PlatformSystemController::class, 'testMail'])->name('settings.system.test.mail');
    Route::post('settings/system/test/lead-email', [PlatformSystemController::class, 'testLeadEmail'])->name('settings.system.test.lead-email');
    Route::post('settings/system/test/stripe', [PlatformSystemController::class, 'testStripe'])->name('settings.system.test.stripe');
    Route::post('settings/system/test/paypal', [PlatformSystemController::class, 'testPayPal'])->name('settings.system.test.paypal');
    Route::post('settings/system/test/razorpay', [PlatformSystemController::class, 'testRazorpay'])->name('settings.system.test.razorpay');
    Route::post('settings/system/test/llm', [PlatformSystemController::class, 'testLlm'])->name('settings.system.test.llm');
    Route::post('settings/system/probe/llm-latency', [PlatformSystemController::class, 'probeLatency'])->name('settings.system.probe.llm-latency');
    Route::post('settings/system/probe/cloudflare-models', [PlatformSystemController::class, 'refreshCloudflareModels'])->name('settings.system.probe.cloudflare-models');
    Route::post('settings/system/test/embed', [PlatformSystemController::class, 'testEmbed'])->name('settings.system.test.embed');
    Route::post('settings/system/test/cache', [PlatformSystemController::class, 'testCache'])->name('settings.system.test.cache');

    // "Why is the bot slow?" — per-stage latency for the last 100 turns.
    Route::get('settings/system/hotpath-latency', [HotPathLatencyController::class, 'index'])->name('settings.system.hotpath-latency');

    // "What's breaking?" — widget reliability feed (stream failures, provider
    // outages/failovers, client-reported freezes) + triage workflow.
    Route::get('settings/system/widget-monitor', [WidgetMonitorController::class, 'index'])->name('settings.system.widget-monitor');
    Route::post('settings/system/widget-monitor/{widgetEvent}/resolve', [WidgetMonitorController::class, 'resolve'])->name('settings.system.widget-monitor.resolve');

    // One-click Cloudflare Cron Worker deploy. Requires the install's
    // Cloudflare credentials to already be saved in System Settings.
    Route::post('settings/system/cron-worker/deploy', [CronWorkerController::class, 'deploy'])->name('settings.system.cron-worker.deploy');
    Route::get('settings/system/cron-worker/status', [CronWorkerController::class, 'status'])->name('settings.system.cron-worker.status');
    Route::delete('settings/system/cron-worker', [CronWorkerController::class, 'destroy'])->name('settings.system.cron-worker.destroy');
});
